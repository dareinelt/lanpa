<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ad\AdUserMapper;
use Tests\Support\TestCase;

final class AdUserMapperTest extends TestCase
{
    private function mapper(): AdUserMapper
    {
        return new AdUserMapper([
            'guid' => 'objectGUID', 'username' => 'sAMAccountName', 'first_name' => 'givenName', 'last_name' => 'sn',
            'display_name' => 'displayName', 'email' => 'mail', 'personnel_number' => 'employeeID', 'department' => 'department',
            'position' => 'title', 'phone' => 'telephoneNumber', 'location' => 'physicalDeliveryOfficeName',
            'cost_center' => 'extensionAttribute1', 'account_control' => 'userAccountControl',
        ]);
    }

    public function testBinaryGuidIsConvertedToCanonicalForm(): void
    {
        // Byte-Reihenfolge laut Microsoft: erste drei Gruppen little-endian
        $binary = hex2bin('04030201' . '0605' . '0807' . '090a' . '0b0c0d0e0f10');
        $this->assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', AdUserMapper::formatGuid($binary));
    }

    public function testTextGuidsAreNormalised(): void
    {
        $this->assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', AdUserMapper::formatGuid('{01020304-0506-0708-090A-0B0C0D0E0F10}'));
        $this->assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', AdUserMapper::formatGuid('0102030405060708090a0b0c0d0e0f10'));
        $this->assertNull(AdUserMapper::formatGuid(''));
        $this->assertNull(AdUserMapper::formatGuid('keine-guid'));
    }

    public function testMapsAttributesAndDetectsDisabledAccounts(): void
    {
        $entry = [
            'dn' => 'CN=Anna Schmidt,OU=Users,DC=x', 'objectguid' => '01020304-0506-0708-090a-0b0c0d0e0f10', 'samaccountname' => 'aschmidt',
            'givenname' => 'Anna', 'sn' => 'Schmidt', 'mail' => 'Anna.Schmidt@Example.com', 'employeeid' => '10001',
            'department' => 'IT', 'title' => 'Admin', 'telephonenumber' => ['+49 1', '+49 2'], 'physicaldeliveryofficename' => 'Peine',
            'extensionattribute1' => '12345', 'useraccountcontrol' => '514',
        ];
        $row = $this->mapper()->map($entry);
        $this->assertNotNull($row);
        $this->assertSame('aschmidt', $row['username']);
        $this->assertSame('Anna Schmidt', $row['display_name'], 'Anzeigename aus Vor-/Nachname gebildet');
        $this->assertSame('anna.schmidt@example.com', $row['email']);
        $this->assertSame('+49 1', $row['phone'], 'Erster Wert mehrwertiger Attribute');
        $this->assertSame('12345', $row['ad_cost_center']);
        $this->assertSame(0, $row['is_active'], 'ACCOUNTDISABLE-Bit gesetzt');

        $entry['useraccountcontrol'] = '66048';
        $this->assertSame(1, $this->mapper()->map($entry)['is_active']);
    }

    public function testEntryWithoutGuidIsRejected(): void
    {
        $this->assertNull($this->mapper()->map(['dn' => 'x', 'samaccountname' => 'nobody']));
    }

    public function testLdapAttributesListIsDerivedFromMapping(): void
    {
        $attrs = $this->mapper()->ldapAttributes();
        $this->assertContains('objectGUID', $attrs);
        $this->assertContains('userAccountControl', $attrs);
        $this->assertCount(13, $attrs);
    }
}
