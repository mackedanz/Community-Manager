<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\DiscordAuthError;
use Hangar\DiscordBot;
use Hangar\DiscordRoles;
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
        $roleInfo = null;
        if ($section === 'rollen' && $lists['problem'] === null) {
            try {
                $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
                $details = DiscordBot::roleDetails($guild);
                $member = DiscordBot::botMember($guild, $details);
                $roleInfo = ['roles' => $details, 'bot' => $member['permissions'] ?? null, 'top' => $member['top'] ?? null];
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
            'roleInfo' => $roleInfo,
            'usedChannels' => self::usedChannels($all),
            'cases' => $cases,
        ], 'Discord-Bot', 'bot');
    }

    /** Kanäle, die der Bot nutzt: Einstellung => Bezeichnung. */
    private const USED_CHANNELS = [
        BotSettings::ANNOUNCE_CHANNEL => 'Terminankündigungen',
        BotSettings::WELCOME_CHANNEL => 'Willkommen',
        BotSettings::RULES_CHANNEL => 'Regeln',
        BotSettings::MOD_LOG_CHANNEL => 'Moderations-Protokoll',
        BotSettings::ADMIN_CHANNEL => 'Bot-Einstellungen',
    ];

    /** Kanal-ID => Bezeichnung für die Kanäle, die der Bot nutzt. @param array<string,string> $all @return array<string,string> */
    private static function usedChannels(array $all): array
    {
        $out = [];
        foreach (self::USED_CHANNELS as $name => $label) {
            if (isset($all[$name])) {
                $out[$all[$name]] = isset($out[$all[$name]]) ? $out[$all[$name]] . ', ' . $label : $label;
            }
        }
        return $out;
    }

    /**
     * Gemeinsamer Rahmen der Änderungen: Admin, Server und Token prüfen, dann die Arbeit ausführen; Fehler werden zur Meldung.
     * @param callable(string,array<string,mixed>):string $work liefert die Erfolgsmeldung
     */
    private static function change(Request $req, array $p, string $section, callable $work): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=' . $section;
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
        if ($guild === '' || !DiscordBot::configured()) {
            return Flash::error($to, 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN).');
        }
        try {
            return Flash::ok($to, $work($guild, $org));
        } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
            return Flash::error($to, $e->getMessage());
        }
    }

    /** Ändert eine vorhandene Rolle. */
    public static function roleUpdate(Request $req, array $p): Response
    {
        return self::change($req, $p, 'rollen', static function (string $guild) use ($req): string {
            $details = DiscordBot::roleDetails($guild);
            $plan = DiscordRoles::planUpdate($req->post, $details, DiscordBot::botMember($guild, $details));
            DiscordBot::updateRole($guild, $plan['id'], $plan['payload']);
            return "Rolle „{$plan['name']}“ geändert.";
        });
    }

    /** Löscht eine vorhandene Rolle. */
    public static function roleDelete(Request $req, array $p): Response
    {
        return self::change($req, $p, 'rollen', static function (string $guild) use ($req): string {
            $details = DiscordBot::roleDetails($guild);
            $plan = DiscordRoles::planDelete((string) $req->input('id', ''), $details, DiscordBot::botMember($guild, $details));
            DiscordBot::deleteRole($guild, $plan['id']);
            return "Rolle „{$plan['name']}“ gelöscht.";
        });
    }

    /** Ändert einen vorhandenen Kanal oder eine Kategorie. */
    public static function channelUpdate(Request $req, array $p): Response
    {
        return self::change($req, $p, 'setup', static function (string $guild) use ($req): string {
            $plan = DiscordSetup::planUpdate($req->post, DiscordBot::structure($guild), array_column(DiscordBot::roles($guild), 'id'), $guild, DiscordBot::selfId());
            DiscordBot::updateChannel($plan['id'], $plan['payload']);
            return "„{$plan['name']}“ geändert.";
        });
    }

    /** Löscht einen vorhandenen Kanal oder eine Kategorie; Einstellungen des Bots, die darauf zeigen, werden geleert. */
    public static function channelDelete(Request $req, array $p): Response
    {
        return self::change($req, $p, 'setup', static function (string $guild, array $org) use ($req): string {
            $plan = DiscordSetup::planDelete((string) $req->input('id', ''), DiscordBot::structure($guild));
            DiscordBot::deleteChannel($plan['id']);
            $cleared = 0;
            foreach (BotSettings::all($org['id']) as $name => $value) {
                if (isset(self::USED_CHANNELS[$name]) && $value === $plan['id']) {
                    BotSettings::set($org['id'], $name, null);
                    $cleared++;
                }
            }
            return ($plan['category'] ? 'Kategorie' : 'Kanal') . " „{$plan['name']}“ gelöscht."
                . ($cleared > 0 ? ' Der Bot nutzt ihn nicht mehr, bitte unter „Allgemein“ und „Termine“ einen neuen wählen.' : '');
        });
    }

    /** Legt Rollen mit Rechten an (erst alles prüfen, dann anlegen). */
    public static function roles(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=rollen';
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
        if ($guild === '' || !DiscordBot::configured()) {
            return Flash::error($to, 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN).');
        }
        try {
            $details = DiscordBot::roleDetails($guild);
            $plan = DiscordRoles::plan($req->post, $details, DiscordBot::botPermissions($guild, $details));
            $done = DiscordRoles::run($guild, $plan);
        } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, "Angelegt: $done Rollen. Zum Vergeben der Rolle in Discord die Mitglieder zuweisen; in Kategorien und Kanälen steht sie jetzt zur Auswahl.");
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
