<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Office\NextcloudFilesService;
use RuntimeException;

/**
 * Export und Import von Notfallplänen als Export-Satz (Formatversion 3).
 *
 * Ein Export-Satz besteht aus einer oder mehreren JSON-Teildateien von je höchstens
 * PART_MAX_BYTES. Ist eine Datei voll, wird eine anfolgende Datei erzeugt; Anhänge
 * werden dabei bei Bedarf in Abschnitte (base64) über mehrere Dateien verteilt.
 * Jede Teildatei trägt `set = {id, part, parts}`; Teil 1 enthält zusätzlich das
 * Inhaltsverzeichnis (`manifest`: Pläne mit SHA-256 der Definition, Anhänge mit
 * Typ, Größe und Anzahl der Abschnitte). Der Import prüft damit die Vollständigkeit
 * des Satzes (alle Teile, alle Pläne, alle Abschnitte, Prüfsummen) und legt erst
 * danach alles in einem Schritt an – oder nichts.
 *
 * Erzeugte Teildateien und hochgeladene Importteile liegen bis zu TTL Sekunden in
 * einem Arbeitsverzeichnis (je Person getrennt).
 */
final class EmergencyPlanTransfer
{
    public const VERSION = 3;
    /** Obergrenze je Teildatei (15 MB, dezimal gerechnet und damit auch unter 15 MiB). */
    public const PART_MAX_BYTES = 15000000;
    public const PART_MAX_LABEL = '15 MB';
    public const MAX_PARTS = 200;
    public const TTL = 7200;
    public const NEXTCLOUD_FOLDER = 'Notfallpläne';
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    /** Platz für format, version, exported_at und set je Teildatei. */
    private const ENVELOPE_RESERVE = 1024;
    /** JSON-Rahmen eines Anhangsabschnitts ohne die Daten selbst. */
    private const CHUNK_OVERHEAD = 128;
    private const MIN_CHUNK = 65536;
    private const MAX_CHUNKS = 100000;
    private const SET_PATTERN = '/^[a-f0-9]{32}$/D';
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private readonly EmergencyPlanService $plans,
        private readonly string $directory,
        private readonly int $partMaxBytes = self::PART_MAX_BYTES
    ) {
    }

    /**
     * Erzeugt einen Export-Satz aus den aktuellen Entwürfen der gewählten Pläne.
     *
     * @param list<int> $ids
     * @return array{set:string,folder:string,exported_at:string,plans:list<string>,parts:list<array{part:int,name:string,bytes:int}>}
     */
    public function createExport(array $ids, string $actor): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => is_int($id) && $id > 0)));
        if ($ids === []) {
            self::fail('export', 'Bitte mindestens einen Notfallplan für den Export auswählen.');
        }
        if (count($ids) > EmergencyPlanService::EXPORT_MAX_PLANS) {
            self::fail('export', 'Höchstens ' . EmergencyPlanService::EXPORT_MAX_PLANS . ' Notfallpläne je Export.');
        }
        $this->cleanup();

        $entries = [];
        $manifestPlans = [];
        $owners = [];
        foreach ($ids as $index => $id) {
            $plan = $this->plans->repository->plan($id);
            $definition = $plan['definition'];
            foreach (EmergencyPlanAttachments::ids($definition) as $attachmentId) {
                $owners[$attachmentId] ??= (string) $definition['title'];
            }
            $entries[] = ['index' => $index, 'title' => $definition['title'], 'source_id' => (int) $plan['id'],
                'source_revision' => (int) $plan['revision'], 'definition' => $definition];
            $manifestPlans[] = ['title' => $definition['title'], 'sha256' => self::definitionHash($definition)];
        }
        $known = $owners === [] ? [] : $this->plans->repository->attachmentMeta(array_keys($owners));
        $attachments = [];
        foreach ($owners as $attachmentId => $title) {
            if (!isset($known[$attachmentId])) {
                self::fail('export', 'Ein Anhang des Notfallplans „' . $title . '“ fehlt in der Datenbank. Bitte den Anhang im Editor neu hochladen.');
            }
            $attachments[(string) $attachmentId] = ['mime' => $known[$attachmentId]['mime'], 'size' => $known[$attachmentId]['size'], 'chunks' => 0];
        }

        $layout = $this->layout($entries, $manifestPlans, $attachments);
        $manifest = ['plans' => $manifestPlans, 'attachments' => (object) $layout['attachments']];
        $set = bin2hex(random_bytes(16));
        $exportedAt = gmdate('Y-m-d\TH:i:s\Z');
        $count = count($layout['parts']);
        $titles = array_map(static fn (array $plan): string => (string) $plan['title'], $manifestPlans);
        $prefix = count($titles) === 1 ? 'notfallplan-' . self::slug($titles[0]) : 'notfallplaene';
        $width = strlen((string) $count);
        $dir = $this->exportDir($set);
        self::makeDir($dir);

        $parts = [];
        $loaded = ['id' => '', 'data' => ''];
        foreach ($layout['parts'] as $i => $part) {
            $number = $i + 1;
            $doc = ['format' => EmergencyPlanService::EXPORT_FORMAT, 'version' => self::VERSION, 'exported_at' => $exportedAt,
                'set' => ['id' => $set, 'part' => $number, 'parts' => $count]];
            if ($number === 1) {
                $doc['manifest'] = $manifest;
            }
            $doc['plans'] = array_map(static fn (int $index): array => $entries[$index], $part['plans']);
            $chunks = [];
            foreach ($part['chunks'] as [$attachmentId, $chunkIndex, $offset, $length]) {
                if ($loaded['id'] !== $attachmentId) {
                    $row = $this->plans->repository->attachment($attachmentId);
                    if ($row === null) {
                        $this->removeDir($dir);
                        self::fail('export', 'Ein Anhang wurde während des Exports gelöscht. Bitte erneut exportieren.');
                    }
                    $loaded = ['id' => $attachmentId, 'data' => $row['data']];
                }
                $chunks[] = ['attachment' => $attachmentId, 'index' => $chunkIndex, 'data' => substr($loaded['data'], $offset, $length)];
            }
            $doc['chunks'] = $chunks;
            $json = json_encode($doc, self::FLAGS) . "\n";
            unset($doc, $chunks);
            if (strlen($json) > $this->partMaxBytes) {
                $this->removeDir($dir);
                throw new RuntimeException('Teildatei überschreitet die Höchstgröße.');
            }
            $name = sprintf('%s_%s_%s_teil-%0' . $width . 'd-von-%d.json', $prefix, date('Y-m-d'), substr($set, 0, 8), $number, $count);
            self::write($dir . '/part-' . $number . '.json', $json);
            $parts[] = ['part' => $number, 'name' => $name, 'bytes' => strlen($json)];
            unset($json);
        }

        $label = count($titles) === 1 ? $titles[0] : count($titles) . ' Notfallpläne';
        $folder = self::NEXTCLOUD_FOLDER . '/' . NextcloudFilesService::segment(date('Y-m-d H-i') . ' ' . $label, 'Export ' . substr($set, 0, 8));
        $result = ['set' => $set, 'folder' => $folder, 'exported_at' => $exportedAt, 'plans' => $titles, 'parts' => $parts];
        self::write($dir . '/meta.json', json_encode($result + ['actor' => $actor, 'kind' => 'export'], self::FLAGS));
        app_logger()->info('Notfallpläne exportiert.', ['actor' => $actor, 'ids' => $ids, 'set' => $set, 'parts' => $count]);

        return $result;
    }

    /**
     * Teildatei eines eigenen Export-Satzes (Download bzw. Ablage in Nextcloud).
     *
     * @return array{name:string,path:string,folder:string,parts:int}
     */
    public function exportPart(string $set, int $part, string $actor): array
    {
        $meta = preg_match(self::SET_PATTERN, $set) === 1 ? self::readJson($this->exportDir($set) . '/meta.json') : null;
        if ($meta === null || !hash_equals((string) ($meta['actor'] ?? ''), $actor) || ($meta['kind'] ?? null) !== 'export') {
            throw new HttpException(404, 'Der Export ist nicht mehr verfügbar. Bitte erneut exportieren.');
        }
        foreach ($meta['parts'] ?? [] as $entry) {
            $path = $this->exportDir($set) . '/part-' . $part . '.json';
            if ((int) ($entry['part'] ?? 0) === $part && is_file($path)) {
                return ['name' => (string) $entry['name'], 'path' => $path, 'folder' => (string) $meta['folder'], 'parts' => count($meta['parts'])];
            }
        }
        throw new HttpException(404, 'Diese Teildatei gibt es nicht. Bitte erneut exportieren.');
    }

    /**
     * Nimmt eine hochgeladene Exportdatei entgegen und meldet den Stand ihres Export-Satzes.
     * Einzeldateien der Versionen 1 und 2 bilden einen vollständigen Satz aus einem Teil.
     *
     * @return array{set:string,parts:int,received:list<int>,missing:list<int>,complete:bool,plans:list<string>,exported_at:string,part:int}
     */
    public function stageImport(string $contents, string $fileName, string $actor): array
    {
        $label = '„' . EmergencyPlanAttachments::name($fileName) . '“';
        if ($contents === '' || strlen($contents) > EmergencyPlanService::IMPORT_MAX_BYTES) {
            self::fail('import', 'Die Datei ' . $label . ' ist leer oder größer als ' . EmergencyPlanService::IMPORT_MAX_LABEL . '.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('import', 'Die Datei ' . $label . ' ist keine gültige Notfallplan-Exportdatei (JSON).');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== EmergencyPlanService::EXPORT_FORMAT) {
            self::fail('import', 'Die Datei ' . $label . ' ist keine Notfallplan-Exportdatei.');
        }
        $this->cleanup();

        if (in_array($data['version'] ?? null, EmergencyPlanService::IMPORT_VERSIONS, true)) {
            $set = substr(hash('sha256', $contents), 0, 32);
            $titles = [];
            foreach (is_array($data['plans'] ?? null) ? $data['plans'] : [] as $plan) {
                $titles[] = is_array($plan) && is_string($plan['definition']['title'] ?? null) ? mb_substr($plan['definition']['title'], 0, 190) : '';
            }
            $meta = ['kind' => 'single', 'set' => $set, 'parts' => 1, 'exported_at' => is_string($data['exported_at'] ?? null) ? mb_substr($data['exported_at'], 0, 40) : '',
                'titles' => $titles, 'hashes' => [1 => hash('sha256', $contents)]];
            $dir = $this->importDir($set, $actor);
            self::makeDir($dir);
            self::write($dir . '/part-1.json', $contents);
            self::write($dir . '/meta.json', json_encode($meta, self::FLAGS));

            return self::status($meta, 1);
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            self::fail('import', 'Die Version der Datei ' . $label . ' wird nicht unterstützt.');
        }

        $header = self::header($data, 'Die Datei ' . $label);
        $titles = [];
        if ($header['part'] === 1) {
            $titles = array_map(static fn (array $plan): string => $plan['title'], self::manifest($data['manifest'] ?? null)['plans']);
        }
        unset($data);
        $dir = $this->importDir($header['set'], $actor);
        $meta = self::readJson($dir . '/meta.json') ?? ['kind' => 'set', 'set' => $header['set'], 'parts' => $header['parts'],
            'exported_at' => $header['exported_at'], 'titles' => [], 'hashes' => []];
        if ($meta['parts'] !== $header['parts'] || $meta['exported_at'] !== $header['exported_at']) {
            self::fail('import', 'Die Datei ' . $label . ' passt nicht zu den bereits ausgewählten Teilen desselben Export-Satzes.');
        }
        $hash = hash('sha256', $contents);
        $known = $meta['hashes'][(string) $header['part']] ?? null;
        if ($known !== null && $known !== $hash) {
            self::fail('import', 'Teil ' . $header['part'] . ' von ' . $header['parts'] . ' liegt bereits mit anderem Inhalt vor (' . $label . '). Bitte die Auswahl neu beginnen.');
        }
        if ($header['part'] === 1) {
            $meta['titles'] = $titles;
        }
        $meta['hashes'][(string) $header['part']] = $hash;
        self::makeDir($dir);
        self::write($dir . '/part-' . $header['part'] . '.json', $contents);
        self::write($dir . '/meta.json', json_encode($meta, self::FLAGS));

        return self::status($meta, $header['part']);
    }

    /**
     * Prüft einen hochgeladenen Export-Satz vollständig und importiert alle Pläne als neue Entwürfe
     * (alles oder nichts).
     *
     * @return list<int>
     */
    public function importStaged(string $set, string $actor): array
    {
        $dir = preg_match(self::SET_PATTERN, $set) === 1 ? $this->importDir($set, $actor) : '';
        $meta = $dir === '' ? null : self::readJson($dir . '/meta.json');
        if ($meta === null) {
            self::fail('import', 'Die ausgewählten Dateien liegen nicht (mehr) vor. Bitte die Dateien des Export-Satzes erneut auswählen.');
        }
        $status = self::status($meta, 0);
        if (!$status['complete']) {
            self::fail('import', 'Der Export-Satz ist unvollständig: Es ' . (count($status['missing']) === 1 ? 'fehlt Teil ' : 'fehlen die Teile ')
                . implode(', ', $status['missing']) . ' von ' . $status['parts'] . '. Bitte alle Dateien des Export-Satzes auswählen. Es wurde nichts importiert.');
        }
        @set_time_limit(300);
        try {
            if ($meta['kind'] === 'single') {
                return $this->plans->importPlans((string) file_get_contents($dir . '/part-1.json'), $actor);
            }

            return $this->importSet($dir, $meta, $actor);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<int>
     */
    private function importSet(string $dir, array $meta, string $actor): array
    {
        $work = $dir . '/chunks';
        self::makeDir($work);
        $manifest = null;
        $entries = [];
        $received = [];
        for ($number = 1; $number <= $meta['parts']; $number++) {
            $label = 'Teil ' . $number . ' von ' . $meta['parts'];
            $contents = (string) file_get_contents($dir . '/part-' . $number . '.json');
            if (!hash_equals((string) ($meta['hashes'][(string) $number] ?? ''), hash('sha256', $contents))) {
                self::fail('import', $label . ' wurde nach dem Hochladen verändert. Es wurde nichts importiert.');
            }
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            unset($contents);
            $header = self::header($data, $label);
            if ($header['set'] !== $meta['set'] || $header['part'] !== $number || $header['parts'] !== $meta['parts'] || $header['exported_at'] !== $meta['exported_at']) {
                self::fail('import', $label . ' gehört nicht zu diesem Export-Satz. Es wurde nichts importiert.');
            }
            if ($number === 1) {
                $manifest = self::manifest($data['manifest'] ?? null);
            } elseif (array_key_exists('manifest', $data)) {
                self::fail('import', $label . ' enthält ein unerwartetes Inhaltsverzeichnis. Es wurde nichts importiert.');
            }
            $plans = $data['plans'] ?? null;
            $chunks = $data['chunks'] ?? null;
            if (!is_array($plans) || !array_is_list($plans) || !is_array($chunks) || !array_is_list($chunks)) {
                self::fail('import', $label . ' hat einen ungültigen Aufbau. Es wurde nichts importiert.');
            }
            foreach ($plans as $entry) {
                $index = is_array($entry) ? ($entry['index'] ?? null) : null;
                if (!is_int($index) || !isset($manifest['plans'][$index]) || isset($entries[$index]) || !is_array($entry['definition'] ?? null)) {
                    self::fail('import', $label . ' enthält einen Plan, der nicht im Inhaltsverzeichnis steht oder doppelt ist. Es wurde nichts importiert.');
                }
                if (!hash_equals($manifest['plans'][$index]['sha256'], self::definitionHash($entry['definition']))) {
                    self::fail('import', 'Der Plan „' . $manifest['plans'][$index]['title'] . '“ in ' . $label . ' ist beschädigt. Es wurde nichts importiert.');
                }
                $entries[$index] = ['definition' => $entry['definition']];
            }
            foreach ($chunks as $chunk) {
                $id = is_array($chunk) ? ($chunk['attachment'] ?? null) : null;
                $index = is_array($chunk) ? ($chunk['index'] ?? null) : null;
                $payload = is_array($chunk) ? ($chunk['data'] ?? null) : null;
                if (!is_string($id) || !isset($manifest['attachments'][$id]) || !is_int($index) || $index < 0
                    || $index >= $manifest['attachments'][$id]['chunks'] || isset($received[$id][$index])
                    || !is_string($payload) || $payload === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $payload) !== 1) {
                    self::fail('import', $label . ' enthält ungültige oder doppelte Anhangsdaten. Es wurde nichts importiert.');
                }
                self::write($work . '/' . $id . '-' . $index, $payload);
                $received[$id][$index] = true;
            }
            unset($data, $plans, $chunks);
        }

        $missingPlans = array_diff_key($manifest['plans'], $entries);
        if ($missingPlans !== []) {
            self::fail('import', 'Der Export-Satz ist unvollständig: Es fehlen die Pläne '
                . implode(', ', array_map(static fn (array $plan): string => '„' . $plan['title'] . '“', $missingPlans)) . '. Es wurde nichts importiert.');
        }
        $attachments = [];
        foreach ($manifest['attachments'] as $id => $attachment) {
            if (count($received[$id] ?? []) !== $attachment['chunks']) {
                self::fail('import', 'Der Export-Satz ist unvollständig: Von einem Anhang fehlen Daten. Es wurde nichts importiert.');
            }
            $bytes = self::assemble($work, $id, $attachment['chunks']);
            if ($bytes === null || strlen($bytes) !== $attachment['size'] || !hash_equals($id, hash('sha256', $bytes))) {
                self::fail('import', 'Ein Anhang des Export-Satzes ist beschädigt. Es wurde nichts importiert.');
            }
            try {
                $mime = EmergencyPlanAttachments::check($bytes);
            } catch (ValidationException $exception) {
                self::fail('import', 'Ein Anhang des Export-Satzes ist unzulässig: ' . implode(' ', $exception->errors()));
            }
            $attachments[$id] = ['mime' => $mime, 'size' => strlen($bytes)];
            unset($bytes);
        }
        ksort($entries);

        return $this->plans->importDefinitions(array_values($entries), $attachments, static fn (string $id): array => [
            'bytes' => (string) self::assemble($work, $id, $manifest['attachments'][$id]['chunks']),
            'mime' => $attachments[$id]['mime'],
        ], $actor);
    }

    /**
     * Verteilt Pläne und Anhangsabschnitte auf Teildateien (nur anhand der Größen).
     *
     * @param list<array<string,mixed>> $entries
     * @param list<array{title:string,sha256:string}> $manifestPlans
     * @param array<string,array{mime:string,size:int,chunks:int}> $attachments
     * @return array{parts:list<array{plans:list<int>,chunks:list<array{0:string,1:int,2:int,3:int}>}>,attachments:array<string,array{mime:string,size:int,chunks:int}>}
     */
    private function layout(array $entries, array $manifestPlans, array $attachments): array
    {
        $capacity = $this->partMaxBytes - self::ENVELOPE_RESERVE;
        $estimate = array_map(static fn (array $attachment): array => ['chunks' => self::MAX_CHUNKS * 10] + $attachment, $attachments);
        $manifestSize = strlen(',"manifest":' . json_encode(['plans' => $manifestPlans, 'attachments' => (object) $estimate], self::FLAGS));
        if ($manifestSize > intdiv($capacity, 2)) {
            self::fail('export', 'Zu viele Pläne oder Anhänge für einen Export. Bitte weniger Notfallpläne auswählen.');
        }
        $minChunk = max(4, intdiv(min(self::MIN_CHUNK, intdiv($capacity, 8)), 4) * 4);
        $parts = [['plans' => [], 'chunks' => []]];
        $free = $capacity - $manifestSize;
        $next = static function () use (&$parts, &$free, $capacity): void {
            $parts[] = ['plans' => [], 'chunks' => []];
            $free = $capacity;
        };

        foreach ($entries as $index => $entry) {
            $cost = strlen(json_encode($entry, self::FLAGS)) + 1;
            if ($cost > $capacity) {
                self::fail('export', 'Der Notfallplan „' . $entry['title'] . '“ ist zu groß für eine Exportdatei (höchstens ' . self::PART_MAX_LABEL . ').');
            }
            if ($cost > $free) {
                $next();
            }
            $parts[array_key_last($parts)]['plans'][] = $index;
            $free -= $cost;
        }
        foreach ($attachments as $id => &$attachment) {
            $length = 4 * intdiv($attachment['size'] + 2, 3);
            $offset = 0;
            $index = 0;
            while ($offset < $length) {
                $available = intdiv($free - self::CHUNK_OVERHEAD, 4) * 4;
                if ($available < min($minChunk, $length - $offset)) {
                    $next();
                    continue;
                }
                $take = min($length - $offset, $available);
                $parts[array_key_last($parts)]['chunks'][] = [(string) $id, $index, $offset, $take];
                $free -= $take + self::CHUNK_OVERHEAD;
                $offset += $take;
                $index++;
            }
            $attachment['chunks'] = $index;
        }
        unset($attachment);
        if (count($parts) > self::MAX_PARTS) {
            self::fail('export', 'Der Export würde mehr als ' . self::MAX_PARTS . ' Dateien umfassen. Bitte weniger Notfallpläne auswählen.');
        }

        return ['parts' => $parts, 'attachments' => $attachments];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{set:string,part:int,parts:int,exported_at:string}
     */
    private static function header(array $data, string $label): array
    {
        $set = $data['set'] ?? null;
        $part = is_array($set) ? ($set['part'] ?? null) : null;
        $parts = is_array($set) ? ($set['parts'] ?? null) : null;
        if (!is_array($set) || !is_string($set['id'] ?? null) || preg_match(self::SET_PATTERN, $set['id']) !== 1
            || !is_int($parts) || $parts < 1 || $parts > self::MAX_PARTS || !is_int($part) || $part < 1 || $part > $parts
            || !is_string($data['exported_at'] ?? null) || strlen($data['exported_at']) > 40) {
            self::fail('import', $label . ' hat keine gültige Kennzeichnung als Teil eines Export-Satzes.');
        }

        return ['set' => $set['id'], 'part' => $part, 'parts' => $parts, 'exported_at' => $data['exported_at']];
    }

    /**
     * @return array{plans:list<array{title:string,sha256:string}>,attachments:array<string,array{mime:string,size:int,chunks:int}>}
     */
    private static function manifest(mixed $manifest): array
    {
        $plans = is_array($manifest) ? ($manifest['plans'] ?? null) : null;
        $attachments = is_array($manifest) ? ($manifest['attachments'] ?? []) : null;
        if (!is_array($plans) || !array_is_list($plans) || $plans === [] || count($plans) > EmergencyPlanService::EXPORT_MAX_PLANS
            || !is_array($attachments) || ($attachments !== [] && array_is_list($attachments))) {
            self::fail('import', 'Teil 1 des Export-Satzes enthält kein gültiges Inhaltsverzeichnis.');
        }
        $result = ['plans' => [], 'attachments' => []];
        foreach ($plans as $plan) {
            if (!is_array($plan) || !is_string($plan['title'] ?? null) || !is_string($plan['sha256'] ?? null) || preg_match(self::HASH_PATTERN, $plan['sha256']) !== 1) {
                self::fail('import', 'Teil 1 des Export-Satzes enthält kein gültiges Inhaltsverzeichnis.');
            }
            $result['plans'][] = ['title' => mb_substr($plan['title'], 0, 190), 'sha256' => $plan['sha256']];
        }
        foreach ($attachments as $id => $attachment) {
            $id = (string) $id;
            if (preg_match(self::HASH_PATTERN, $id) !== 1 || !is_array($attachment)
                || !in_array($attachment['mime'] ?? null, EmergencyPlanAttachments::MIMES, true)
                || !is_int($attachment['size'] ?? null) || $attachment['size'] < 1 || $attachment['size'] > EmergencyPlanAttachments::MAX_BYTES
                || !is_int($attachment['chunks'] ?? null) || $attachment['chunks'] < 1 || $attachment['chunks'] > self::MAX_CHUNKS) {
                self::fail('import', 'Teil 1 des Export-Satzes enthält kein gültiges Inhaltsverzeichnis.');
            }
            $result['attachments'][$id] = ['mime' => $attachment['mime'], 'size' => $attachment['size'], 'chunks' => $attachment['chunks']];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{set:string,parts:int,received:list<int>,missing:list<int>,complete:bool,plans:list<string>,exported_at:string,part:int}
     */
    private static function status(array $meta, int $part): array
    {
        $received = array_map('intval', array_keys($meta['hashes']));
        sort($received);
        $missing = array_values(array_diff(range(1, (int) $meta['parts']), $received));

        return ['set' => (string) $meta['set'], 'parts' => (int) $meta['parts'], 'received' => $received, 'missing' => $missing,
            'complete' => $missing === [], 'plans' => array_values($meta['titles']), 'exported_at' => (string) $meta['exported_at'], 'part' => $part];
    }

    /** SHA-256 der Plandefinition in kanonischer JSON-Darstellung (für das Inhaltsverzeichnis). */
    private static function definitionHash(array $definition): string
    {
        return hash('sha256', json_encode($definition, self::FLAGS));
    }

    private static function assemble(string $work, string $id, int $chunks): ?string
    {
        $data = '';
        for ($index = 0; $index < $chunks; $index++) {
            $part = @file_get_contents($work . '/' . $id . '-' . $index);
            if ($part === false) {
                return null;
            }
            $data .= $part;
        }
        $bytes = base64_decode($data, true);

        return $bytes === false ? null : $bytes;
    }

    private static function slug(string $title): string
    {
        $title = strtr(mb_strtolower($title), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $title), '-');

        return $slug === '' ? 'export' : rtrim(substr($slug, 0, 40), '-');
    }

    private function exportDir(string $set): string
    {
        return rtrim($this->directory, '/') . '/export-' . $set;
    }

    /** Importteile je Person getrennt: Niemand kann fremde Teile ergänzen oder importieren. */
    private function importDir(string $set, string $actor): string
    {
        return rtrim($this->directory, '/') . '/import-' . substr(hash('sha256', $actor . "\n" . $set), 0, 40);
    }

    /** Entfernt abgelaufene Export-Sätze und liegengebliebene Importteile. */
    private function cleanup(): void
    {
        foreach (glob(rtrim($this->directory, '/') . '/{export,import}-*', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $dir) {
            $time = @filemtime($dir . '/meta.json') ?: @filemtime($dir);
            if ($time !== false && $time < time() - self::TTL) {
                $this->removeDir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!str_starts_with($dir, rtrim($this->directory, '/') . '/') || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Arbeitsverzeichnis für Notfallplan-Export/-Import nicht beschreibbar.');
        }
    }

    private static function write(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Arbeitsdatei für Notfallplan-Export/-Import konnte nicht geschrieben werden.');
        }
    }

    /** @return array<string,mixed>|null */
    private static function readJson(string $path): ?array
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        $data = $contents === false ? null : json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    private static function fail(string $key, string $message): never
    {
        throw new ValidationException([$key => $message]);
    }
}
