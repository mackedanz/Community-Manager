<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Discord;
use Hangar\DiscordAuthError;
use Hangar\Env;
use Hangar\Secrets;

final class SecretsTest extends DbTestCase
{
    public function testRoundTripUsesAPrefixAndANewNonceEachTime(): void
    {
        $a = Secrets::encrypt('geheimes-token');
        $b = Secrets::encrypt('geheimes-token');
        $this->assertStringStartsWith('enc:v1:', (string) $a);
        $this->assertNotSame($a, $b);
        $this->assertStringNotContainsString('geheimes', (string) $a);
        $this->assertSame('geheimes-token', Secrets::decrypt($a));
        $this->assertSame('geheimes-token', Secrets::decrypt($b));
    }

    public function testEmptyValuesAndAlreadyEncryptedValuesStayAsTheyAre(): void
    {
        $this->assertNull(Secrets::encrypt(null));
        $this->assertSame('', Secrets::encrypt(''));
        $enc = (string) Secrets::encrypt('x');
        $this->assertSame($enc, Secrets::encrypt($enc));
        $this->assertNull(Secrets::decrypt(null));
        $this->assertNull(Secrets::decrypt(''));
    }

    public function testLegacyPlaintextIsStillReadable(): void
    {
        $this->assertSame('alter-klartext', Secrets::decrypt('alter-klartext'));
    }

    public function testWrongKeyOrTamperedDataGiveNullInsteadOfGarbage(): void
    {
        $enc = (string) Secrets::encrypt('token');
        Env::set('APP_KEY', 'ein-ganz-anderer-schluessel-1234567890');
        $this->assertNull(Secrets::decrypt($enc));
        Env::set('APP_KEY', 'test-schluessel-test-schluessel-1234567890');
        $this->assertSame('token', Secrets::decrypt($enc));

        $raw = (string) base64_decode(substr($enc, 7));
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
        $this->assertNull(Secrets::decrypt('enc:v1:' . base64_encode($raw)));
        $this->assertNull(Secrets::decrypt('enc:v1:kein-base64!!'));
        $this->assertNull(Secrets::decrypt('enc:v1:' . base64_encode('zu kurz')));
    }

    public function testMissingOrShortKeyIsRefused(): void
    {
        foreach ([null, 'kurz'] as $key) {
            Env::set('APP_KEY', $key);
            try {
                Secrets::encrypt('token');
                $this->fail('Ohne brauchbaren APP_KEY darf nichts verschlüsselt werden');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('APP_KEY', $e->getMessage());
            }
        }
    }

    public function testStoredTokensAreEncryptedOnceAndOnlyTheOldOnes(): void
    {
        $u = $this->mkUser();
        $already = (string) Secrets::encrypt('schon');
        Db::insert('accounts', ['id' => 'a1', 'user_id' => $u['id'], 'provider' => 'discord', 'provider_account_id' => 'p1', 'access_token' => 'alt-a', 'refresh_token' => 'alt-r', 'expires_at' => 1]);
        Db::insert('accounts', ['id' => 'a2', 'user_id' => $u['id'], 'provider' => 'discord', 'provider_account_id' => 'p2', 'access_token' => $already, 'refresh_token' => null, 'expires_at' => 1]);

        $this->assertSame(1, Secrets::encryptStoredTokens());
        $a1 = Db::one("SELECT * FROM accounts WHERE id = 'a1'");
        $this->assertStringStartsWith('enc:v1:', $a1['access_token']);
        $this->assertSame('alt-a', Secrets::decrypt($a1['access_token']));
        $this->assertSame('alt-r', Secrets::decrypt($a1['refresh_token']));
        $this->assertSame($already, Db::val("SELECT access_token FROM accounts WHERE id = 'a2'"));
        $this->assertSame(0, Secrets::encryptStoredTokens());
    }

    public function testDiscordAccessTokenReadsEncryptedLegacyAndUnreadableTokens(): void
    {
        $u = $this->mkUser();
        $row = fn (string $access, string $refresh) => Db::insert('accounts', [
            'id' => new_id(), 'user_id' => $u['id'], 'provider' => 'discord', 'provider_account_id' => 'x' . uniqid(),
            'access_token' => $access, 'refresh_token' => $refresh, 'expires_at' => time() + 3600, 'scope' => 'identify guilds guilds.members.read',
        ]);

        $row((string) Secrets::encrypt('klar'), (string) Secrets::encrypt('r'));
        $this->assertSame('klar', Discord::accessToken($u['id']));

        Db::run('DELETE FROM accounts');
        $row('altes-klartext-token', 'r');   // Altbestand vor der Verschlüsselung
        $this->assertSame('altes-klartext-token', Discord::accessToken($u['id']));

        // Falscher Schlüssel: kein Absturz, sondern "bitte neu anmelden"
        Db::run('DELETE FROM accounts');
        $row((string) Secrets::encrypt('klar'), (string) Secrets::encrypt('r'));
        Env::set('APP_KEY', 'ein-ganz-anderer-schluessel-1234567890');
        $this->expectException(DiscordAuthError::class);
        Discord::accessToken($u['id']);
    }
}