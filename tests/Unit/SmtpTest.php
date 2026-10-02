<?php

declare(strict_types=1);

use App\Repositories\EmergencyPlanRepository;
use App\Repositories\SettingsRepository;
use App\Security\SecretBox;
use App\Services\MailQueueService;
use App\Services\SettingsService;
use App\Services\SmtpService;
use App\Exceptions\ValidationException;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('SMTP: Adressen und Header-Injektion abweisen', static function (): void {
    Assert::true(SmtpService::validEmail('kaep@example.test'));
    foreach (["victim@example.test\r\nBcc: someone@example.test", "a@example.test\n", '', 'a b@example.test', '<a@example.test>'] as $email) {
        Assert::false(SmtpService::validEmail($email));
    }
});

Runner::test('SMTP: Passwort und Klartextanmeldung werden nicht versehentlich akzeptiert', static function (): void {
    $service = new SmtpService(new SettingsService(new SettingsRepository(emergencyPdo())), new SecretBox('/unused-smtp-test-key'));
    $failed = false;
    try {
        $service->save(['smtp_host' => 'mail.example.test', 'smtp_from' => 'kaep@example.test', 'smtp_username' => 'admin', 'smtp_security' => 'none']);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['smtp_security']));
        Assert::true(isset($exception->errors()['smtp_password']));
        $failed = true;
    }
    Assert::true($failed);
    Assert::false(array_key_exists('smtp_password', $service->config()), 'Passwort wird nie an die Ansicht geliefert.');
});

Runner::test('SMTP: Warteschlange protokolliert Fehler und begrenzt Wiederholungen', static function (): void {
    $pdo = emergencyPdo();
    $eventService = emergencyService($pdo);
    $event = emergencyStart($eventService);
    $smtp = new SmtpService(new SettingsService(new SettingsRepository($pdo)), new SecretBox('/unused-smtp-test-key'));
    $queue = new MailQueueService($pdo, $smtp, new EmergencyPlanRepository($pdo));
    Assert::same(1, $queue->run());
    $mail = $queue->recent()[0];
    Assert::same('queued', $mail['status']);
    Assert::same(1, (int) $mail['attempts']);
    Assert::contains('SMTP ist deaktiviert', $mail['message']);
    Assert::same(0, $queue->run(), 'Wartezeit beachten.');
    $pdo->exec('UPDATE mail_outbox SET available_at = 0');
    $queue->run();
    $pdo->exec('UPDATE mail_outbox SET available_at = 0');
    $queue->run();
    Assert::same('failed', $queue->recent()[0]['status']);
    Assert::same(3, (int) $queue->recent()[0]['attempts']);
    Assert::same(0, $queue->run());
    Assert::same('active', $eventService->repository->event($event)['status'], 'Mailfehler beendet das Ereignis nicht.');
    Assert::same('email_failed', array_slice($eventService->repository->logs($event), -1)[0]['action']);
});

Runner::test('SMTP: Hängender Versand wird nach Abbruch sichtbar wiederhergestellt', static function (): void {
    $pdo = emergencyPdo();
    $smtp = new SmtpService(new SettingsService(new SettingsRepository($pdo)), new SecretBox('/unused-smtp-test-key'));
    $queue = new MailQueueService($pdo, $smtp, new EmergencyPlanRepository($pdo));
    $queue->enqueueTest('kaep@example.test');
    $pdo->exec("UPDATE mail_outbox SET status = 'sending', attempts = 3, available_at = 0");
    Assert::same(0, $queue->run());
    Assert::same('failed', $queue->recent()[0]['status']);
    Assert::contains('Zustellung unklar', $queue->recent()[0]['message']);
});
