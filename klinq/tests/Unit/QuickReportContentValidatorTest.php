<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\QuickReportContentValidator;
use Tests\Support\TestCase;

/**
 * Störungsformular: Betreff und Beschreibung müssen für sich aussagekräftig sein.
 * Geprüft werden Länge, Floskeln, reine Screenshot-Beschreibungen und Wiederholungen.
 */
final class QuickReportContentValidatorTest extends TestCase
{
    private function assertValid(string $subject, string $description): void
    {
        $errors = QuickReportContentValidator::validate($subject, $description);
        $this->assertSame([], $errors, 'Meldung sollte gültig sein, Fehler: ' . implode(' | ', $errors));
    }

    private function assertSubjectError(string $subject): void
    {
        $errors = QuickReportContentValidator::validate($subject, 'Der Drucker im 2. OG druckt seit heute nicht mehr.');
        $this->assertTrue(isset($errors['subject']), 'Betreff sollte abgelehnt werden: ' . $subject);
    }

    private function assertDescriptionError(string $subject, string $description): void
    {
        $errors = QuickReportContentValidator::validate($subject, $description);
        $this->assertTrue(isset($errors['description']), 'Beschreibung sollte abgelehnt werden: ' . $description);
    }

    public function testAussagekraeftigeMeldungWirdAkzeptiert(): void
    {
        $this->assertValid('Drucker im 2. OG druckt nicht', 'Seit heute Morgen bleibt der Druckauftrag in der Warteschlange. Es erscheint keine Fehlermeldung.');
        $this->assertValid('Anmeldung schlägt fehl', 'Beim Start von Windows erscheint die Meldung 0x80070005; ein Neustart hat nicht geholfen.');
    }

    public function testLeerePflichtfelderPrueftDerTicketService(): void
    {
        $this->assertValid('', 'Seit heute druckt der Drucker nicht mehr.');
        $this->assertValid('Drucker defekt', '');
    }

    public function testZuLangerBetreffWirdAbgelehnt(): void
    {
        $this->assertSubjectError(str_repeat('Drucker druckt nicht ', 8));
    }

    public function testFloskelnImBetreffWerdenAbgelehnt(): void
    {
        foreach ([
            'Siehe unten',
            's. u.',
            'Siehe Beschreibung',
            'Drucker defekt – siehe Beschreibung',
            'Drucker defekt, siehe Anhang',
            'Näheres siehe unten',
            'Details siehe unten',
            'Wie besprochen',
            'Wie telefonisch besprochen',
            'Siehe beigefügte Datei',
            'Betreff sagt alles',
        ] as $subject) {
            $this->assertSubjectError($subject);
        }
    }

    public function testNichtssagendeBetreffsWerdenAbgelehnt(): void
    {
        foreach (['Hilfe', 'Problem', 'Störung', 'geht nicht', 'Screenshot', 'asdf', 'aaaa'] as $subject) {
            $this->assertSubjectError($subject);
        }
    }

    public function testBetreffMitKonkretemInhaltBleibtErlaubt(): void
    {
        $this->assertValid('Screenshot-Taste funktioniert nicht', 'Beim Drücken von Drucken passiert nichts mehr; die Zwischenablage bleibt leer.');
        $this->assertValid('Datei lässt sich nicht öffnen', 'Beim Doppelklick auf die Excel-Datei im Laufwerk S: erscheint „Zugriff verweigert“.');
    }

    public function testBetreffNurMitInventarnummerWirdAbgelehnt(): void
    {
        foreach (['PC24001', 'DR19015', 'NB-0815', '24001', 'PC 24001', 'pc24001', 'PC24001 kaputt'] as $subject) {
            $this->assertSubjectError($subject);
        }

        $this->assertValid('PC24001 druckt nicht', 'Der Drucker im 2. OG gibt seit heute keine Ausdrucke mehr aus.');
        $this->assertValid('Drucker PC24001 defekt', 'Der Drucker im 2. OG gibt seit heute keine Ausdrucke mehr aus.');
        $this->assertValid('Drucker 2024 druckt nicht', 'Der Drucker im 2. OG gibt seit heute keine Ausdrucke mehr aus.');
    }

    public function testBeschreibungNurMitInventarnummernWirdAbgelehnt(): void
    {
        $this->assertDescriptionError('Drucker defekt', 'PC24001 und DR19015');
    }

    public function testBeschreibungNurMitScreenshotWirdAbgelehnt(): void
    {
        $image = '![Bild](data:image/png;base64,' . str_repeat('A', 120) . ')';

        $this->assertDescriptionError('Drucker defekt', $image);
        $this->assertDescriptionError('Drucker defekt', "[Bild 1]");
        $this->assertDescriptionError('Drucker defekt', 'data:image/png;base64,' . str_repeat('A', 120));
    }

    public function testScreenshotMitBegleittextBleibtErlaubt(): void
    {
        $image = '![Bild](data:image/png;base64,' . str_repeat('A', 120) . ')';

        $this->assertValid('Drucker im 2. OG druckt nicht', 'Der Fehler erscheint direkt nach dem Anmelden. ' . $image);
        $this->assertValid('Fehler beim Start', 'Der Screenshot zeigt den Fehler 0x80070005 beim Start des Programms.');
    }

    public function testFloskelOhneInhaltInDerBeschreibungWirdAbgelehnt(): void
    {
        $this->assertDescriptionError('Drucker defekt', 'Siehe Bild');
        $this->assertDescriptionError('Drucker defekt', 'Siehe beigefügten Screenshot');
        $this->assertDescriptionError('Drucker defekt', 'Bitte helfen Sie mir');
        $this->assertDescriptionError('Drucker defekt', 'geht nicht');
    }

    public function testWiederholungDesBetreffsWirdAbgelehnt(): void
    {
        $this->assertDescriptionError('Drucker defekt', 'Drucker defekt');
        $this->assertDescriptionError('Drucker defekt', 'DRUCKER DEFEKT!');
        $this->assertDescriptionError('Drucker defekt', 'Drucker defekt – siehe unten');
    }

    public function testBetreffMitZusatzinformationBleibtErlaubt(): void
    {
        $this->assertValid('Drucker defekt', 'Drucker defekt seit heute Morgen, ein Neustart hat nicht geholfen.');
    }
}
