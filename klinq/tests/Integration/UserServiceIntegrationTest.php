<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\PasswordHasher;
use App\Services\AuthService;
use App\Services\UserService;
use Tests\Support\DatabaseTestCase;

final class UserServiceIntegrationTest extends DatabaseTestCase
{
    private const PW = 'Sicher12345!';

    /** @param array<string,mixed> $array */
    private function assertHasKey(string $key, array $array): void
    {
        $this->assertTrue(array_key_exists($key, $array), "Schlüssel {$key} erwartet, vorhanden: " . implode(', ', array_keys($array)));
    }

    private function loginAs(int $id, string $role = 'admin'): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $id, 'username' => 'tester', 'display_name' => 'Tester', 'role' => $role]);
    }

    private function seedAdmin(string $username = 'root-admin'): int
    {
        $users = $this->c->get(UserRepository::class);

        return $users->create([
            'username' => $username,
            'display_name' => 'Root Admin',
            'password_hash' => $this->c->get(PasswordHasher::class)->hash(self::PW),
            'role_id' => $users->roleId('admin'),
            'auth_source' => 'local',
            'is_active' => 1,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'username' => 'max.muster',
            'display_name' => 'Max Muster',
            'email' => 'max@example.local',
            'role' => 'lager',
            'password' => self::PW,
            'password_confirmation' => self::PW,
            'is_active' => '1',
        ], $overrides);
    }

    public function testCreateStoresHashedPasswordAndAuditsWithoutSecrets(): void
    {
        $adminId = $this->seedAdmin();
        $this->loginAs($adminId);
        $svc = $this->c->get(UserService::class);

        $id = $svc->create($this->input());
        $user = $this->c->get(UserRepository::class)->find($id);
        $this->assertSame('max.muster', $user['username']);
        $this->assertSame('lager', $user['role']);
        $this->assertSame('local', $user['auth_source']);
        $this->assertFalse(self::PW === $user['password_hash']);
        $this->assertTrue($this->c->get(PasswordHasher::class)->verify(self::PW, $user['password_hash']));

        // Anmeldung funktioniert mit dem gesetzten Passwort
        $this->assertNotNull($this->c->get(AuthService::class)->attempt('max.muster', self::PW));

        $entries = $this->c->get(AuditLogRepository::class)->search(['object_type' => 'user', 'object_id' => $id], 10, 0);
        $this->assertCount(1, $entries);
        $this->assertSame('create', $entries[0]['action']);
        $this->assertFalse(str_contains((string) json_encode($entries[0]), self::PW), 'Passwort darf nicht im Audit-Log stehen');
        $this->assertFalse(str_contains((string) $entries[0]['new_data'], 'password'));
    }

    public function testValidationRejectsWeakPasswordsBadUsernamesAndDuplicates(): void
    {
        $this->loginAs($this->seedAdmin());
        $svc = $this->c->get(UserService::class);

        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input(['password' => 'kurz1', 'password_confirmation' => 'kurz1'])));
        $this->assertHasKey('password', $e->errors());
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input(['password' => 'nurbuchstaben', 'password_confirmation' => 'nurbuchstaben'])));
        $this->assertHasKey('password', $e->errors());
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input(['password_confirmation' => 'anders12345!'])));
        $this->assertHasKey('password_confirmation', $e->errors());
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input(['username' => 'a b'])));
        $this->assertHasKey('username', $e->errors());
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input(['role' => 'superuser'])));
        $this->assertHasKey('role', $e->errors());

        $svc->create($this->input());
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->create($this->input()));
        $this->assertHasKey('username', $e->errors());
    }

    public function testUpdateChangesRoleButKeepsUsernameAndPassword(): void
    {
        $this->loginAs($this->seedAdmin());
        $svc = $this->c->get(UserService::class);
        $users = $this->c->get(UserRepository::class);
        $id = $svc->create($this->input());
        $before = $users->find($id);

        $svc->update($id, $before, ['username' => 'hacker', 'display_name' => 'Maxi Muster', 'role' => 'einkauf', 'is_active' => '1', 'password' => 'Neu12345678!']);
        $after = $users->find($id);
        $this->assertSame('max.muster', $after['username']);
        $this->assertSame('einkauf', $after['role']);
        $this->assertSame('Maxi Muster', $after['display_name']);
        $this->assertSame($before['password_hash'], $after['password_hash']);
    }

    public function testGroupsCanBeAssignedAndAreMultiValued(): void
    {
        $this->loginAs($this->seedAdmin());
        $svc = $this->c->get(UserService::class);
        $users = $this->c->get(UserRepository::class);
        $statistik = array_values(array_filter($users->permissionGroups(), static fn (array $g): bool => $g['name'] === 'statistik'))[0];

        $id = $svc->create($this->input(['groups' => [(string) $statistik['id']]]));
        $created = $users->find($id);
        $this->assertSame(['statistik'], $created['groups']);
        $this->assertSame([(int) $statistik['id']], $users->groupIdsForUser($id));

        // Ungültige/fremde IDs werden ignoriert, mehrere Gruppen bleiben zuweisbar.
        $svc->update($id, $created, ['username' => 'ignored', 'display_name' => 'Max Muster', 'role' => 'lager', 'is_active' => '1', 'groups' => [(string) $statistik['id'], '999999']]);
        $after = $users->find($id);
        $this->assertSame(['statistik'], $after['groups']);

        // Gruppen entfernen, indem das Feld weggelassen wird
        $svc->update($id, $after, ['display_name' => 'Max Muster', 'role' => 'lager', 'is_active' => '1']);
        $this->assertSame([], $users->find($id)['groups']);
    }

    public function testGuardsProtectOwnAccountAndLastAdmin(): void
    {
        $adminId = $this->seedAdmin();
        $this->loginAs($adminId);
        $svc = $this->c->get(UserService::class);
        $users = $this->c->get(UserRepository::class);
        $self = $users->find($adminId);

        $this->assertThrows(ValidationException::class, fn () => $svc->setActive($adminId, $self, false), 'eigene Konto');
        $this->assertThrows(ValidationException::class, fn () => $svc->update($adminId, $self, ['display_name' => 'Root', 'role' => 'lager', 'is_active' => '1']), 'Administratorrolle');

        // Zweiten Admin anlegen; solange der erste aktiv ist, darf der zweite herabgestuft werden …
        $secondId = $svc->create($this->input(['username' => 'second.admin', 'role' => 'admin']));
        $svc->update($secondId, $users->find($secondId), ['display_name' => 'Second', 'role' => 'readonly', 'is_active' => '1']);
        $this->assertSame('readonly', $users->find($secondId)['role']);

        // … aber ein anderer Admin darf den letzten aktiven Admin nicht deaktivieren
        $this->loginAs($secondId);
        $this->assertThrows(ValidationException::class, fn () => $svc->setActive($adminId, $users->find($adminId), false), 'Mindestens ein aktiver Administrator');
    }

    public function testResetAndChangePasswordClearLockoutAndRequireCurrentPassword(): void
    {
        $adminId = $this->seedAdmin();
        $this->loginAs($adminId);
        $svc = $this->c->get(UserService::class);
        $users = $this->c->get(UserRepository::class);
        $auth = $this->c->get(AuthService::class);
        $id = $svc->create($this->input());

        // Konto sperren
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($auth->attempt('max.muster', 'falsch12345!'));
        }
        $this->assertNull($auth->attempt('max.muster', self::PW), 'gesperrt');

        $svc->resetPassword($id, $users->find($id), 'Reset12345!', 'Reset12345!');
        $this->assertNotNull($auth->attempt('max.muster', 'Reset12345!'), 'Sperre nach Reset aufgehoben');

        $user = $users->find($id);
        $this->assertThrows(ValidationException::class, fn () => $svc->changeOwnPassword($user, 'falsch', 'Neu12345678!', 'Neu12345678!'), 'aktuelle Passwort');
        $this->assertThrows(ValidationException::class, fn () => $svc->changeOwnPassword($user, 'Reset12345!', 'Reset12345!', 'Reset12345!'), 'unterscheiden');
        $svc->changeOwnPassword($user, 'Reset12345!', 'Neu12345678!', 'Neu12345678!');
        $this->assertNotNull($auth->attempt('max.muster', 'Neu12345678!'));
        $this->assertNull($auth->attempt('max.muster', 'Reset12345!'));
    }

    public function testLdapAccountsHaveNoLocalPassword(): void
    {
        $this->loginAs($this->seedAdmin());
        $users = $this->c->get(UserRepository::class);
        $id = $users->create(['username' => 'ad.user', 'display_name' => 'AD User', 'password_hash' => null, 'role_id' => $users->roleId('readonly'), 'auth_source' => 'ldap', 'is_active' => 1]);
        $svc = $this->c->get(UserService::class);
        $this->assertThrows(ValidationException::class, fn () => $svc->resetPassword($id, $users->find($id), self::PW, self::PW), 'Active Directory');
    }
}
