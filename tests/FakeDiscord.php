<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Http\Client;

/** Nachgebautes Discord: Serverliste, Rollen pro Server, erzwungene Fehlerstatus. */
final class FakeDiscord
{
    public const SCOPES = 'identify guilds guilds.members.read';

    /** @var list<array<string,mixed>> */
    public array $guilds = [];
    /** @var array<string,list<string>> */
    public array $roles = [];
    /** @var array<string,string> Server-Nickname pro Server */
    public array $nicks = [];
    public int $guildsStatus = 200;
    public int $tokenStatus = 200;
    /** @var array<string,mixed> Profil für /users/@me */
    public array $profile = ['id' => '123456789012345678', 'username' => 'tester', 'global_name' => 'Tester', 'avatar' => 'abc'];
    /** @var list<string> */
    public array $calls = [];

    public function install(): void
    {
        Client::fake(function (string $method, string $url): array {
            $this->calls[] = $url;
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if (str_ends_with($url, '/oauth2/token')) {
                return $this->tokenStatus === 200
                    ? $json(['access_token' => 'neu', 'refresh_token' => 'ref2', 'expires_in' => 604800, 'scope' => self::SCOPES])
                    : $json(['error' => 'invalid_grant'], $this->tokenStatus);
            }
            if (str_ends_with($url, '/users/@me')) {
                return $json($this->profile);
            }
            if (str_ends_with($url, '/users/@me/guilds')) {
                return $this->guildsStatus === 200 ? $json($this->guilds) : $json([], $this->guildsStatus);
            }
            if (preg_match('#/users/@me/guilds/([^/]+)/member$#', $url, $m)) {
                return isset($this->roles[$m[1]]) ? $json(['roles' => $this->roles[$m[1]], 'nick' => $this->nicks[$m[1]] ?? null]) : $json(['code' => 10004], 404);
            }
            return $json([], 500);
        });
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    public static function guild(string $id, array $extra = []): array
    {
        return array_merge(['id' => $id, 'name' => "Server $id", 'icon' => null, 'owner' => false, 'permissions' => '0'], $extra);
    }
}
