<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Creators;
use Hangar\Db;
use Hangar\DiscordAuthError;
use Hangar\DiscordBot;
use Hangar\DiscordRights;
use Hangar\DiscordRoles;
use Hangar\DiscordRules;
use Hangar\DiscordSetup;
use Hangar\DiscordTemplates;
use Hangar\DiscordUnavailableError;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Moderation;
use Hangar\OrgError;
use Hangar\Recruiting;

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
     * Bereiche nur für Server-Admins: Rollen und Rechte, Discord-Setup (mit Vorlagen, Zuordnung und Kanalrechten) und Temp Sprachkanal.
     * Wer nur über die Orga-Leitungs-Rolle Admin ist, hat darauf keinen Zugriff; das bleibt beim Server-Eigner und den Server-Admins.
     */
    private const OWNER_SECTIONS = ['sprache', 'setup', 'rollen', 'vorlagen', 'zuordnung', 'rechte'];

    /** Admin der Orga und zugleich Server-Admin (nicht nur über die Leitungs-Rolle). @param array<string,mixed> $org */
    private static function isOwner(array $org): bool
    {
        return $org['role'] === 'ADMIN' && empty($org['viaRole']);
    }

    /** Wie requireAdmin, aber nur für Server-Admins. @return array{0:HangarViewer,1:array<string,mixed>} */
    private static function requireOwner(Request $req, string $slug): array
    {
        [$viewer, $org] = self::requireAdmin($req, $slug);
        if (!self::isOwner($org)) {
            throw HttpException::notFound();
        }
        return [$viewer, $org];
    }

    /** Bereiche, die Moderatoren (ohne Admin-Recht) auf der Bot-Seite sehen: Moderation, Fälle und Automod. */
    private const MOD_SECTIONS = ['moderation', 'faelle', 'automod'];

    /**
     * Orga-Admins und Moderatoren (Rolle aus den Moderations-Einstellungen). Alle anderen sehen die Seite nicht (404).
     * @return array{0:HangarViewer,1:array<string,mixed>}
     */
    private static function requireStaff(Request $req, string $slug): array
    {
        [$viewer, $org] = Auth::requireOrgMember($req, $slug);
        if ($org['role'] !== 'ADMIN' && empty($org['canModerate'])) {
            throw HttpException::notFound();
        }
        return [$viewer, $org];
    }

    /**
     * Kanäle und Rollen des Servers; null, wenn sie nicht zu holen sind (kein Bot, Discord-Fehler), dazu der Grund.
     * @return array{channels:?list<array{id:string,name:string,category:?string}>,voice:?list<array{id:string,name:string,category:?string}>,roles:?list<array{id:string,name:string,color:int}>,problem:?string}
     */
    private static function discordLists(string $orgId): array
    {
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$orgId]);
        if ($guild === '' || !DiscordBot::configured()) {
            return ['channels' => null, 'roles' => null, 'problem' => 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN). Kanäle und Rollen können nicht geladen werden.'];
        }
        try {
            $found = DiscordBot::textAndVoiceChannels($guild);
            return ['channels' => $found['text'], 'voice' => $found['voice'], 'roles' => DiscordBot::roles($guild), 'problem' => null];
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            return ['channels' => null, 'voice' => null, 'roles' => null, 'problem' => $e->getMessage()];
        }
    }

    public static function show(Request $req, array $p): Response
    {
        [, $org] = self::requireStaff($req, (string) $p['slug']);
        $fullAccess = $org['role'] === 'ADMIN';
        $owner = self::isOwner($org);
        $section = (string) $req->query('m', $fullAccess ? 'allgemein' : 'moderation');
        if (!isset(BotSettings::SECTIONS[$section]) && !isset(BotSettings::SUBSECTIONS[$section])) {
            $section = $fullAccess ? 'allgemein' : 'moderation';
        }
        // Moderatoren sehen nur ihre Bereiche, die Orga-Leitung (ohne Server-Admin) nicht die Server-Admin-Bereiche
        if (!$fullAccess && !in_array($section, self::MOD_SECTIONS, true)) {
            $section = 'moderation';
        }
        if (!$owner && in_array($section, self::OWNER_SECTIONS, true)) {
            $section = $fullAccess ? 'allgemein' : 'moderation';
        }
        $navSections = $fullAccess ? BotSettings::SECTIONS : array_intersect_key(BotSettings::SECTIONS, array_flip(self::MOD_SECTIONS));
        if (!$owner) {
            $navSections = array_diff_key($navSections, array_flip(self::OWNER_SECTIONS));
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
        $rights = null;
        if ($section === 'rechte' && $lists['problem'] === null) {
            try {
                $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
                $found = DiscordSetup::locate((string) $req->query('c', ''), DiscordBot::structure($guild));
                if ($found !== null) {
                    $details = DiscordBot::roleDetails($guild);
                    $rights = [
                        'item' => $found['row'], 'isCategory' => $found['category'], 'roles' => $details, 'guildId' => $guild,
                        'bot' => DiscordBot::botMember($guild, $details)['permissions'] ?? null, 'selected' => (string) $req->query('r', ''),
                    ];
                }
            } catch (DiscordAuthError | DiscordUnavailableError $e) {
                $lists['problem'] = $e->getMessage();
            }
        }
        $templates = [];
        $preview = null;
        if ($section === 'vorlagen') {
            $templates = self::templateList($org['id']);
            $wanted = (string) $req->query('t', '');
            $tpl = $wanted !== '' ? self::loadTemplate($org['id'], $wanted) : null;
            if ($tpl !== null && $lists['problem'] === null) {
                try {
                    $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
                    $details = DiscordBot::roleDetails($guild);
                    $plan = DiscordTemplates::plan($tpl['template'], DiscordBot::structure($guild), $details, DiscordBot::botMember($guild, $details), $guild, self::usedChannels($all));
                    $preview = ['template' => $tpl, 'plan' => $plan];
                } catch (DiscordAuthError | DiscordUnavailableError $e) {
                    $lists['problem'] = $e->getMessage();
                }
            }
        }
        $modQuery = (string) $req->query('q', '');
        $modAction = (string) $req->query('a', '');
        $modPage = max(1, (int) $req->query('p', '1'));
        $modCases = $section === 'faelle' ? Moderation::cases($org['id'], $modQuery, $modAction, $modPage) : ['rows' => [], 'total' => 0];
        $recruitQuestions = $section === 'recruiting' ? Recruiting::questions($org['id']) : [];
        $creators = $section === 'creator' ? Creators::list($org['id']) : [];
        $suggest = $section === 'zuordnung' ? BotSettings::suggest($all, $lists['channels'] ?? [], $lists['roles'] ?? []) : [];
        $cases = [];
        if ($section === 'moderation') {
            $cases = Db::all('SELECT target_name, action, reason, created_at FROM bot_mod_cases WHERE org_id = ? ORDER BY id DESC LIMIT 5', [$org['id']]);
        }
        return OrgController::orgPage($org, 'org_bot', [
            'section' => $section,
            'fullAccess' => $fullAccess,
            'ownerAccess' => $owner,
            'navSections' => $navSections,
            'settings' => $all,
            'automod' => BotSettings::automod($all),
            'status' => $owner ? BotSettings::status($all, $org['id']) : array_diff_key(BotSettings::status($all, $org['id']), array_flip(self::OWNER_SECTIONS)),
            'channels' => $lists['channels'] ?? [],
            'voiceChannels' => $lists['voice'] ?? [],
            'roles' => $lists['roles'] ?? [],
            'problem' => $lists['problem'],
            'messageContent' => BotSettings::messageContentActive($all),
            'structure' => $structure,
            'roleInfo' => $roleInfo,
            'usedChannels' => self::usedChannels($all),
            'templates' => $templates,
            'preview' => $preview,
            'cases' => $cases,
            'suggest' => $suggest,
            'creators' => $creators,
            'recruitQuestions' => $recruitQuestions,
            'rights' => $rights,
            'twitchActive' => BotSettings::twitchActive($all),
            'guildId' => (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]),
            'modCases' => $modCases,
            'modQuery' => $modQuery,
            'modAction' => $modAction,
            'modPage' => $modPage,
        ], 'Discord-Bot', 'bot');
    }

    /** Kanäle, die der Bot nutzt: Einstellung => Bezeichnung. */
    private const USED_CHANNELS = [
        BotSettings::ANNOUNCE_CHANNEL => 'Terminankündigungen',
        BotSettings::WELCOME_CHANNEL => 'Willkommen',
        BotSettings::RULES_CHANNEL => 'Regeln',
        BotSettings::MOD_LOG_CHANNEL => 'Moderations-Protokoll',
        BotSettings::ADMIN_CHANNEL => 'Bot-Einstellungen',
        BotSettings::TEMPVOICE_TRIGGER => 'Sprachkanal erstellen',
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
    private static function change(Request $req, array $p, string $section, callable $work, string $extra = ''): Response
    {
        [, $org] = in_array($section, self::OWNER_SECTIONS, true) ? self::requireOwner($req, (string) $p['slug']) : self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=' . $section . $extra;
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

    /** Maßnahme gegen eine Person (verwarnen, Timeout, Kick, Bann, aufheben). */
    public static function modAction(Request $req, array $p): Response
    {
        [$viewer, $org] = self::requireStaff($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=faelle';
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
        if ($guild === '' || !DiscordBot::configured()) {
            return Flash::error($to, 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN).');
        }
        try {
            $msg = Moderation::perform($org['id'], $guild, ['id' => $viewer->id, 'name' => $viewer->name, 'discord_id' => $viewer->discordId, 'admin' => $org['role'] === 'ADMIN'], [
                'do' => (string) $req->input('do', ''), 'person' => (string) $req->input('person', ''), 'reason' => (string) $req->input('reason', ''),
                'duration' => (string) $req->input('duration', ''), 'days' => (int) $req->input('days', '0'),
            ]);
        } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, $msg);
    }

    /** Veröffentlicht die Regeln mit „Bestätigen“-Knopf in einem Kanal. */
    public static function rulesPublish(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=regeln';
        $guild = (string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$org['id']]);
        if ($guild === '' || !DiscordBot::configured()) {
            return Flash::error($to, 'Kein Bot-Token eingerichtet (DISCORD_BOT_TOKEN).');
        }
        try {
            $url = DiscordRules::publish(
                $guild, trim((string) $req->input('channel', '')), trim((string) $req->input('role', '')),
                (string) $req->input('title', ''), str_replace("\r\n", "\n", (string) $req->input('text', '')),
            );
        } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, 'Die Regeln sind veröffentlicht: ' . $url);
    }

    /** Nimmt eine Verwarnung zurück. */
    public static function modUnwarn(Request $req, array $p): Response
    {
        [, $org] = self::requireStaff($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=faelle';
        $id = (int) $req->input('id', '0');
        try {
            Moderation::removeWarning($org['id'], $id);
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, "Verwarnung #$id ist zurückgenommen.");
    }

    /** Gemeinsame Prüfung der Creator-Formulare: Discord-Kanal (und Rolle) müssen auf dem Server existieren. @return array{0:string,1:?string} */
    private static function creatorTarget(Request $req, string $orgId): array
    {
        $channel = trim((string) $req->input('channel', ''));
        $role = trim((string) $req->input('role', ''));
        $lists = self::discordLists($orgId);
        if ($lists['channels'] === null) {
            throw new OrgError((string) $lists['problem']);
        }
        if (!in_array($channel, array_column($lists['channels'], 'id'), true)) {
            throw new OrgError('Bitte einen Discord-Kanal auswählen.');
        }
        if ($role !== '' && !in_array($role, array_column($lists['roles'] ?? [], 'id'), true)) {
            throw new OrgError('Diese Rolle gibt es auf dem Server nicht (mehr). Bitte die Seite neu laden.');
        }
        return [$channel, $role === '' ? null : $role];
    }

    /** Fügt einen YouTube-Kanal hinzu oder ändert ihn, wenn es ihn schon gibt. */
    public static function creatorAdd(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=creator';
        try {
            [$channel, $role] = self::creatorTarget($req, $org['id']);
            $platform = (string) $req->input('platform', 'youtube');
            if (!isset(Creators::PLATFORMS[$platform])) {
                throw new OrgError('Diese Plattform gibt es nicht.');
            }
            $r = Creators::add($org['id'], (string) $req->input('source', ''), $channel, $role, $req->input('skipShorts', '') === '1', $platform);
        } catch (OrgError $e) {
            return Flash::error($to, 'Nicht hinzugefügt: ' . $e->getMessage());
        }
        $what = $platform === 'twitch'
            ? 'Der Bot prüft den Kanal bei Twitch (innerhalb von 2 Minuten) und meldet dann jeden Stream, der live geht. Läuft der Kanal schon, wird erst der nächste gemeldet.'
            : 'Ab jetzt postet der Bot jedes neue Video (geprüft wird alle 10 Minuten). Bisherige Videos werden nicht nachträglich gepostet.';
        return Flash::ok($to, ($r['updated'] ? 'Geändert: ' : 'Hinzugefügt: ') . $r['name'] . '. ' . $what);
    }

    /** Ändert Discord-Kanal, Rolle und Shorts eines Creators. */
    public static function creatorUpdate(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=creator';
        try {
            [$channel, $role] = self::creatorTarget($req, $org['id']);
            Creators::update($org['id'], (string) $req->input('id', ''), $channel, $role, $req->input('skipShorts', '') === '1');
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, 'Creator geändert.');
    }

    public static function creatorDelete(Request $req, array $p): Response
    {
        [, $org] = self::requireAdmin($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=creator';
        return Creators::remove($org['id'], (string) $req->input('id', ''))
            ? Flash::ok($to, 'Creator entfernt. Neue Videos werden nicht mehr gepostet.')
            : Flash::error($to, 'Diesen Creator gibt es nicht (mehr).');
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
    /** Rechte einer Rolle in einem Kanal oder einer Kategorie ändern (nur deren Eintrag). */
    public static function channelRights(Request $req, array $p): Response
    {
        $extra = '&c=' . rawurlencode((string) $req->input('id', '')) . '&r=' . rawurlencode((string) $req->input('role', ''));
        return self::change($req, $p, 'rechte', static function (string $guild) use ($req): string {
            $details = DiscordBot::roleDetails($guild);
            $plan = DiscordRights::plan($req->post, DiscordBot::structure($guild), $details, DiscordBot::botMember($guild, $details));
            if ($plan['remove']) {
                DiscordBot::deleteOverwrite($plan['id'], $plan['roleId']);
            } else {
                DiscordBot::setOverwrite($plan['id'], $plan['roleId'], $plan['allow'], $plan['deny']);
            }
            return "Rechte von {$plan['roleName']} in „{$plan['name']}“ gespeichert.";
        }, $extra);
    }

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

    /**
     * Eine Vorlage laden: mitgelieferte ("builtin:schlüssel") oder gespeicherte der Orga.
     * @return ?array{id:string,name:string,description:string,builtin:bool,template:array<string,mixed>}
     */
    private static function loadTemplate(string $orgId, string $id): ?array
    {
        if (str_starts_with($id, 'builtin:')) {
            $b = DiscordTemplates::builtin()[substr($id, 8)] ?? null;
            return $b === null ? null : ['id' => $id, 'name' => $b['name'], 'description' => $b['description'], 'builtin' => true, 'template' => $b['template']];
        }
        $row = Db::one('SELECT id, name, description, data FROM discord_templates WHERE org_id = ? AND id = ?', [$orgId, $id]);
        if ($row === null) {
            return null;
        }
        try {
            $tpl = DiscordTemplates::normalize(json_decode((string) $row['data'], true));
        } catch (OrgError) {
            return null;
        }
        return ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''), 'builtin' => false, 'template' => $tpl];
    }

    /** Alle Vorlagen der Orga mit Größenangaben: erst die mitgelieferten, dann die eigenen. @return list<array<string,mixed>> */
    private static function templateList(string $orgId): array
    {
        $out = [];
        foreach (array_keys(DiscordTemplates::builtin()) as $key) {
            $t = self::loadTemplate($orgId, 'builtin:' . $key);
            $out[] = $t + ['counts' => DiscordTemplates::counts($t['template'])];
        }
        foreach (Db::all('SELECT id FROM discord_templates WHERE org_id = ? ORDER BY name', [$orgId]) as $row) {
            $t = self::loadTemplate($orgId, (string) $row['id']);
            if ($t !== null) {
                $out[] = $t + ['counts' => DiscordTemplates::counts($t['template'])];
            }
        }
        return $out;
    }

    /** Speichert eine geprüfte Vorlage der Orga. @param array<string,mixed> $template @throws OrgError */
    private static function storeTemplate(string $orgId, string $name, string $description, array $template): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new OrgError('Die Vorlage braucht einen Namen (höchstens 100 Zeichen).');
        }
        if ((int) Db::val('SELECT COUNT(*) FROM discord_templates WHERE org_id = ?', [$orgId]) >= DiscordTemplates::MAX_TEMPLATES) {
            throw new OrgError('Höchstens ' . DiscordTemplates::MAX_TEMPLATES . ' eigene Vorlagen. Bitte eine löschen.');
        }
        $names = array_map(static fn ($b) => mb_strtolower($b['name']), DiscordTemplates::builtin());
        if (in_array(mb_strtolower($name), $names, true) || Db::val('SELECT 1 FROM discord_templates WHERE org_id = ? AND name = ?', [$orgId, $name]) !== null) {
            throw new OrgError("Eine Vorlage „{$name}“ gibt es schon. Bitte einen anderen Namen wählen.");
        }
        $json = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > DiscordTemplates::MAX_JSON) {
            throw new OrgError('Die Vorlage ist zu groß.');
        }
        Db::insert('discord_templates', ['id' => new_id(), 'org_id' => $orgId, 'name' => $name, 'description' => mb_substr(trim($description), 0, 300) ?: null, 'data' => $json]);
    }

    /** Hält den aktuellen Aufbau des Servers als Vorlage fest. */
    public static function templateSave(Request $req, array $p): Response
    {
        return self::change($req, $p, 'vorlagen', static function (string $guild, array $org) use ($req): string {
            $details = DiscordBot::roleDetails($guild);
            $captured = DiscordTemplates::capture(DiscordBot::structure($guild), $details, $guild);
            self::storeTemplate($org['id'], (string) $req->input('name', ''), (string) $req->input('description', ''), $captured['template']);
            $c = DiscordTemplates::counts($captured['template']);
            return "Vorlage „" . trim((string) $req->input('name', '')) . "“ gespeichert ({$c['roles']} Rollen, {$c['categories']} Kategorien, {$c['channels']} Kanäle)."
                . ($captured['skipped'] > 0 ? " {$captured['skipped']} Bühnen- oder Forenkanäle wurden nicht aufgenommen." : '');
        });
    }

    /** Legt eine Vorlage aus eingefügtem JSON an (z. B. von einem anderen Server heruntergeladen). */
    public static function templateImport(Request $req, array $p): Response
    {
        [, $org] = self::requireOwner($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=vorlagen';
        try {
            $text = trim((string) $req->input('json', ''));
            if ($text === '' || strlen($text) > DiscordTemplates::MAX_JSON) {
                throw new OrgError('Bitte die Vorlage als JSON einfügen (höchstens ' . (int) (DiscordTemplates::MAX_JSON / 1000) . ' KB).');
            }
            $data = json_decode($text, true);
            if (!is_array($data)) {
                throw new OrgError('Das ist kein gültiges JSON.');
            }
            $template = DiscordTemplates::normalize($data['template'] ?? $data);
            self::storeTemplate($org['id'], (string) $req->input('name', ''), (string) $req->input('description', ''), $template);
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, 'Vorlage importiert.');
    }

    /** Lädt eine Vorlage als JSON-Datei herunter. */
    public static function templateExport(Request $req, array $p): Response
    {
        [, $org] = self::requireOwner($req, (string) $p['slug']);
        $t = self::loadTemplate($org['id'], (string) $req->query('id', ''));
        if ($t === null) {
            throw HttpException::notFound();
        }
        $file = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($t['name'])) ?: 'vorlage';
        return Response::text(
            (string) json_encode(['name' => $t['name'], 'description' => $t['description'], 'template' => $t['template']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            'application/json; charset=utf-8',
        )->withHeader('Content-Disposition', 'attachment; filename="vorlage-' . trim($file, '-') . '.json"');
    }

    /** Löscht eine eigene Vorlage (die mitgelieferten bleiben). */
    public static function templateDelete(Request $req, array $p): Response
    {
        [, $org] = self::requireOwner($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/bot?m=vorlagen';
        $t = self::loadTemplate($org['id'], (string) $req->input('id', ''));
        if ($t === null || $t['builtin']) {
            return Flash::error($to, 'Diese Vorlage gibt es nicht oder sie lässt sich nicht löschen.');
        }
        Db::run('DELETE FROM discord_templates WHERE org_id = ? AND id = ?', [$org['id'], $t['id']]);
        return Flash::ok($to, "Vorlage „{$t['name']}“ gelöscht.");
    }

    /** Wendet eine Vorlage an: nur die in der Vorschau gewählten Einträge, der Plan wird dazu frisch berechnet. */
    public static function templateApply(Request $req, array $p): Response
    {
        return self::change($req, $p, 'vorlagen', static function (string $guild, array $org) use ($req): string {
            $t = self::loadTemplate($org['id'], (string) $req->input('id', ''));
            if ($t === null) {
                throw new OrgError('Diese Vorlage gibt es nicht mehr.');
            }
            $selected = array_values(array_map('strval', is_array($req->post['do'] ?? null) ? $req->post['do'] : []));
            if ($selected === []) {
                throw new OrgError('Es ist nichts ausgewählt.');
            }
            $structure = DiscordBot::structure($guild);
            $details = DiscordBot::roleDetails($guild);
            $plan = DiscordTemplates::plan($t['template'], $structure, $details, DiscordBot::botMember($guild, $details), $guild, self::usedChannels(BotSettings::all($org['id'])));
            @set_time_limit(300);
            $done = DiscordTemplates::apply($guild, $plan, $selected, $structure, $details);
            return "Vorlage „{$t['name']}“ angewendet: " . DiscordTemplates::summary($done) . '. Unter „Zuordnung“ legst du fest, welche Kanäle und Rollen der Bot nutzt.';
        });
    }

    /** Legt Rollen mit Rechten an (erst alles prüfen, dann anlegen). */
    public static function roles(Request $req, array $p): Response
    {
        [, $org] = self::requireOwner($req, (string) $p['slug']);
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
        [, $org] = self::requireOwner($req, (string) $p['slug']);
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
        return Flash::ok($to, "Angelegt: {$done['categories']} Kategorien und {$done['channels']} Kanäle. Unter „Zuordnung“ legst du fest, welche der Bot nutzt.");
    }

    public static function save(Request $req, array $p): Response
    {
        [$viewer, $org] = self::requireAdmin($req, (string) $p['slug']);
        $section = (string) $req->input('section', '');
        // Sprachkanal-Auslöser und Zuordnung (Kanäle, Besucher-Rolle) gehören zu den Server-Admin-Bereichen
        if (in_array($section, self::OWNER_SECTIONS, true) && !self::isOwner($org)) {
            throw HttpException::notFound();
        }
        $to = '/o/' . $org['slug'] . '/bot' . (isset(BotSettings::SECTIONS[$section]) || isset(BotSettings::SUBSECTIONS[$section]) ? '?m=' . $section : '');
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
            // Beim Recruiting gehört der Fragenkatalog dazu: erst alles prüfen, dann speichern
            $questions = $section === 'recruiting' ? Recruiting::parse($req->post) : null;
            BotSettings::save($org['id'], $section, $req->post, $known($section === 'sprache' ? $lists['voice'] : $lists['channels']), $known($lists['roles']), $viewer->id);
            if ($questions !== null) {
                Recruiting::save($org['id'], $questions);
            }
        } catch (OrgError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Flash::ok($to, 'Gespeichert. Der Bot übernimmt die Änderung sofort (Automod nach spätestens 30 Sekunden).');
    }
}
