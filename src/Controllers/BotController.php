<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\DiscordAuthError;
use Hangar\DiscordBot;
use Hangar\DiscordSetup;
use Hangar\DiscordUnavailableError;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;

/** Einstellungen des Discord-Bots im Web (nur Orga-Admins), als Alternative zu den Slash-Befehlen. */
final class BotController extends Controller
{
    /** @return array{0:\Hangar\Viewer,1:array<string,mixed>} */
    private static function requireAdmin(Request $req, string $slug): array
    {
        [$viewer, $org] = Auth::requireOrgMember($req, $slug);
        if ($org['role'] !== 'ADMIN') {
            throw HttpException::notFound();
        }
        return [$viewer, $org];
    }

    /**
     * Kanäle und Rollen des Servers; null, wenn sie nicht zu holen sind (kein Bot, Discord-Fehler), dazu der Grund.
     * @return array{channels:?list<array{id:string,name:string,category:?string}>,roles:?list<array{id:string,name:string,color:int}>,problem:?string}
     */
    private static function discordLists(string $orgId): array
    {
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$orgId]);
        if ($guild === '' || !DiscordBot::configured()) {
            return ['channels' => null, 'roles' => null, 'problem' => 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN). Kanäle und Rollen können nicht geladen werden.'];
        }
        try {
            return ['channels' => DiscordBot::textChannels($guild), 'roles' => DiscordBot::roles($guild), 'problem' => null];
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            return ['channels' => null, 'roles' => null, 'problem' => $e->getMessage()];
        }
    }

    public static function show(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $section = (string) $req->query('m', 'allgemein');
        if (!isset(BotSettings::SECTIONS[$section])) {
            $section = 'allgemein';
        }
        $all = BotSettings::all($org['id']);
        $lists = self::discordLists($org['id']);
        $structure = null;
        if ($section === 'setup' && $lists['problem'] === null) {
            try {
                $structure = DiscordBot::structure((string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]));
            } catch (DiscordAuthError | DiscordUnavailableError $e) {
                $lists['problem'] = $e->getMessage();
            }
        }
        $cases = [];
        if ($section === 'moderation') {
            $cases = Db::all('SELECT target_name, action, reason, created_at FROM bot_mod_cases WHERE org_id = ? ORDER BY id DESC LIMIT 5', [$org['id']]);
        }
        return OrgController::orgPage($org, 'org_bot', [
            'section' => $section,
            'settings' => $all,
            'automod' => BotSettings::automod($all),
            'status' => BotSettings::status($all),
            'channels' => $lists['channels'] ?? [],
            'roles' => $lists['roles'] ?? [],
            'problem' => $lists['problem'],
            'messageContent' => BotSettings::messageContentActive($all),
            'structure' => $structure,
            'cases' => $cases,
        ], 'Discord-Bot', 'bot');
    }

    /** Legt Kategorien und Kanäle aus dem Discord-Setup an (erst alles prüfen, dann anlegen). */
    public static function setup(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=setup';
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
        if ($guild === '' || !DiscordBot::configured()) {
            return Flash::error($to, 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN).');
        }
        try {
            $plan = DiscordSetup::plan($req->post, DiscordBot::structure($guild), array_column(DiscordBot::roles($guild), 'id'));
            $done = DiscordSetup::run($guild, $plan);
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, "Angelegt: {$done['categories']} Kategorien und {$done['channels']} Kanäle.");
    }

    public static function save(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $section = (string) $req->input('section', '');
        $to = '/o/' . $org['slug'] . '/bot' . (isset(BotSettings::SECTIONS[$section]) ? '?m=' . $section : '');
        $lists = self::discordLists($org['id']);
        // Bereits gespeicherte IDs bleiben gültig, auch wenn Discord sie gerade nicht liefert (sonst ließe sich nichts mehr speichern).
        $stored = [];
        foreach (BotSettings::all($org['id']) as $name => $value) {
            if ($name === BotSettings::AUTOMOD) {
                $stored = array_merge($stored, BotSettings::automod([$name => $value])['exemptChannels']);
            } else {
                $stored = array_merge($stored, explode(',', $value));
            }
        }
        $known = static fn (?array $list): ?array => $list === null ? null : array_merge(array_column($list, 'id'), $stored);
        try {
            BotSettings::save($org['id'], $section, $req->post, $known($lists['channels']), $known($lists['roles']));
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, 'Gespeichert. Der Bot übernimmt die Änderung sofort (Automod nach spätestens 30 Sekunden).');
    }
}
