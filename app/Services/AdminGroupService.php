<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AdminGroupRepository;
use App\Security\Auth;
use App\Security\SsoAuth;
use App\Support\Validator;
use Closure;

/**
 * Administratoren aus AD-Gruppen.
 *
 *   intranet:  Mitglieder melden sich per Windows-Anmeldung (SSO) am
 *              Adminbereich an (Rolle admin), ohne lokales Konto.
 *   nextcloud: Mitglieder werden Nextcloud-Administratoren (Gruppe "admin").
 *
 * Mitgliedschaften stammen aus der AD-Synchronisation (verschachtelt
 * aufgeloest); Gruppennamen gelten ohne Gross-/Kleinschreibung ueber alle
 * Identitaetsquellen.
 *
 * @phpstan-type AdminMember array{id:int,uid:string,display_name:string,department:string,source_label:string,groups:list<string>}
 */
final class AdminGroupService
{
    public const TARGET_INTRANET = 'intranet';
    public const TARGET_NEXTCLOUD = 'nextcloud';
    public const TARGET_KAEP = 'kaep';
    public const TARGETS = [self::TARGET_INTRANET, self::TARGET_NEXTCLOUD, self::TARGET_KAEP];

    /** Gleiches Muster wie die Nextcloud-App (SamAccountName[@kennung]). */
    public const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';

    /** @var Closure(): array<int,array{key:string,label:string}> */
    private readonly Closure $sources;

    /**
     * @param null|Closure(): array<int,array{key:string,label:string}> $sources Identitaetsquellen (ID => Kennung, Beschriftung); 0 = Hauptquelle
     */
    public function __construct(
        private readonly AdminGroupRepository $repository,
        ?Closure $sources = null
    ) {
        $this->sources = $sources ?? static fn (): array => [0 => ['key' => '', 'label' => '']];
    }

    /**
     * Regeln eines Ziels mit Anzahl der (aktiven) Mitglieder.
     *
     * @return list<array{id:int,target:string,group_name:string,created_by:string,created_at:string,members:int}>
     */
    public function rules(string $target): array
    {
        $rules = $this->repository->rules(self::target($target));
        $counts = [];
        foreach ($this->repository->members(array_column($rules, 'group_name')) as $member) {
            foreach ($member['groups'] as $group) {
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
        }

        return array_map(
            static fn (array $rule): array => $rule + ['members' => $counts[mb_strtolower($rule['group_name'])] ?? 0],
            $rules
        );
    }

    public function addRule(string $target, string $groupName, string $admin): void
    {
        $target = self::target($target);
        $groupName = trim(Validator::cleanText($groupName, 190), " \t,;");
        if ($groupName === '' || preg_match('/[,;]/', $groupName) === 1) {
            throw new ValidationException([$target . '_group' => 'Bitte genau eine AD-Gruppe angeben.']);
        }
        if ($this->repository->findByName($target, $groupName) !== null) {
            throw new ValidationException([$target . '_group' => 'Diese AD-Gruppe ist bereits eingetragen.']);
        }

        $this->repository->add($target, $groupName, $admin);
    }

    /**
     * @return array{id:int,target:string,group_name:string,created_by:string,created_at:string}|null
     */
    public function deleteRule(int $id): ?array
    {
        $rule = $this->repository->find($id);
        if ($rule === null) {
            return null;
        }

        $this->repository->delete($id);

        return $rule;
    }

    /**
     * Rolle im Adminbereich fuer einen per Windows-Anmeldung erkannten
     * Benutzer anhand seiner AD-Gruppen (null = kein Zugriff).
     *
     * @param list<string> $groups
     */
    public function intranetRole(array $groups): ?string
    {
        $groups = array_map(static fn (string $group): string => mb_strtolower(trim($group)), $groups);
        if ($groups === []) {
            return null;
        }

        foreach ($this->repository->rules(self::TARGET_INTRANET) as $rule) {
            if (in_array(mb_strtolower($rule['group_name']), $groups, true)) {
                return Auth::ROLE_ADMIN;
            }
        }

        foreach ($this->repository->rules(self::TARGET_KAEP) as $rule) {
            if (in_array(mb_strtolower($rule['group_name']), $groups, true)) {
                return Auth::ROLE_KAEP;
            }
        }

        return null;
    }

    /**
     * Gehoert der Benutzer einer AD-Gruppe an, die das KAEP-Team berechtigt?
     *
     * @param list<string> $groups
     */
    public function isKaepMember(array $groups): bool
    {
        $groups = array_map(static fn (string $group): string => mb_strtolower(trim($group)), $groups);
        foreach ($this->repository->rules(self::TARGET_KAEP) as $rule) {
            if (in_array(mb_strtolower($rule['group_name']), $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mitglieder der Gruppen eines Ziels (aktive Benutzer bekannter Quellen).
     *
     * @return list<AdminMember>
     */
    public function members(string $target): array
    {
        $rules = $this->repository->rules(self::target($target));
        $names = [];
        foreach ($rules as $rule) {
            $names[mb_strtolower($rule['group_name'])] = $rule['group_name'];
        }
        $sources = ($this->sources)();

        $members = [];
        foreach ($this->repository->members(array_values($names)) as $row) {
            $source = $sources[$row['identity_source_id']] ?? null;
            if ($source === null) {
                continue;
            }
            $uid = SsoAuth::officeUid($row['samaccount_name'], $source['key']);
            if (preg_match(self::UID_PATTERN, $uid) !== 1) {
                continue;
            }

            $groups = array_values(array_map(static fn (string $group): string => $names[$group] ?? $group, $row['groups']));
            sort($groups, SORT_STRING | SORT_FLAG_CASE);
            $members[] = [
                'id' => $row['id'],
                'uid' => $uid,
                'display_name' => $row['display_name'] !== '' ? $row['display_name'] : $uid,
                'department' => $row['department'],
                'email' => $row['email'],
                'source_label' => $row['identity_source_id'] > 0 ? $source['label'] : '',
                'groups' => $groups,
            ];
        }

        return $members;
    }

    /**
     * Nextcloud-Kennungen aller Nextcloud-Administratoren (sortiert).
     *
     * @return list<string>
     */
    public function nextcloudUids(): array
    {
        $uids = array_values(array_unique(array_column($this->members(self::TARGET_NEXTCLOUD), 'uid')));
        sort($uids, SORT_STRING);

        return $uids;
    }

    private static function target(string $target): string
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new ValidationException(['target' => 'Unbekanntes Ziel.']);
        }

        return $target;
    }
}
