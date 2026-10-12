<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Branding;
use Hangar\Config;
use Hangar\Db;
use Hangar\Discord;
use Hangar\DiscordAuthError;
use Hangar\DiscordBot;
use Hangar\DiscordUnavailableError;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\Onboarding;
use Hangar\RsiOrg;
use Hangar\Time;

/**
 * POST /discord/interactions: Endpunkt für die Slash-Befehle des Onboarding-Bots (kein Dauerprozess nötig).
 * Jede Anfrage ist von Discord per Ed25519 signiert; Server-ID und aufrufende Person kommen aus der
 * signierten Interaktion, nicht aus Eingaben.
 */
final class DiscordController extends Controller
{
    private const PING = 1;
    private const COMMAND = 2;
    private const COMPONENT = 3;
    private const REPLY = 4;
    private const DEFERRED = 5;
    /** Mindestabstand zwischen zwei Abgleichen derselben Orga per /abgleichen. */
    private const SYNC_COOLDOWN_SECONDS = 30;
    private const UPDATE = 7;
    private const EPHEMERAL = 64;

    public static function interactions(Request $req): Response
    {
        if (!DiscordBot::verifySignature($req->body, $req->header('x-signature-ed25519'), $req->header('x-signature-timestamp'))) {
            return Response::text('invalid request signature', 401);
        }
        $i = json_decode($req->body, true);
        if (!is_array($i)) {
            return Response::text('bad request', 400);
        }
        $type = (int) ($i['type'] ?? 0);
        if ($type === self::PING) {
            return Response::json(['type' => 1]);
        }
        if ($type !== self::COMMAND && $type !== self::COMPONENT) {
            return Response::text('unsupported', 400);
        }

        $guildId = (string) ($i['guild_id'] ?? '');
        $user = $i['member']['user'] ?? null;
        if ($guildId === '' || !is_array($user) || empty($user['id'])) {
            return self::say('Dieser Befehl funktioniert nur auf einem Discord-Server.');
        }
        if ($type === self::COMMAND && ($i['data']['name'] ?? '') === 'abgleichen') {
            return self::syncCommand($guildId, $i);
        }
        // Discord liefert die Rechte der aufrufenden Person in der Interaktion mit (Owner eingeschlossen).
        if (!Discord::isGuildAdmin(['permissions' => $i['member']['permissions'] ?? '0'])) {
            return self::say('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).');
        }
        if ($type === self::COMMAND && ($i['data']['name'] ?? '') === 'design') {
            return self::designCommand($guildId, $i);
        }
        $invokerId = (string) $user['id'];
        $invokerName = (string) (($i['member']['nick'] ?? null) ?: ($user['global_name'] ?? null) ?: ($user['username'] ?? 'Discord-Nutzer'));

        try {
            $org = Onboarding::ensureOrg($guildId, $invokerId, $invokerName);
            if ($type === self::COMMAND) {
                return self::setupCommand($org, $i);
            }
            return self::component($org, $i);
        } catch (OrgError $e) {
            return self::say($e->getMessage());
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            return self::say($e->getMessage());
        }
    }

    /**
     * /abgleichen: gleicht die Zugangsliste mit den Discord-Rollen ab. Dürfen: Server-Admins und alle mit einer
     * Nutzungs-Rolle der Orga. Legt nie eine Orga an (das geht nur über /einrichten).
     * @param array<string,mixed> $i
     */
    private static function syncCommand(string $guildId, array $i): Response
    {
        $org = Db::one('SELECT * FROM organizations WHERE discord_guild_id = ?', [$guildId]);
        if ($org === null) {
            return self::say('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.');
        }
        $allowedGuilds = Config::onboardingGuildIds();
        if ($allowedGuilds !== [] && !in_array($guildId, $allowedGuilds, true)) {
            return self::say('Dieser Server ist nicht freigeschaltet.');
        }
        $roles = array_map('strval', (array) ($i['member']['roles'] ?? []));
        $mayUse = array_intersect(Orgs::parseRoleIds($org['member_role_ids']), $roles) !== [];
        if (!$mayUse && !Discord::isGuildAdmin(['permissions' => $i['member']['permissions'] ?? '0'])) {
            return self::say('Das dürfen nur Mitglieder mit einer Nutzungs-Rolle der Orga und Server-Admins.');
        }
        $last = Time::parse($org['allowlist_synced_at'] ?? null);
        if ($last !== null && Time::now()->getTimestamp() - $last->getTimestamp() < self::SYNC_COOLDOWN_SECONDS) {
            return self::say('Die Mitglieder wurden gerade erst abgeglichen. Bitte warte kurz.');
        }
        if (Orgs::parseRoleIds($org['member_role_ids']) === []) {
            return self::say('Es ist noch keine Rolle gewählt, die das Tool nutzen darf. Das erledigt ein Server-Admin mit /einrichten.');
        }

        $orgId = (string) $org['id'];
        $token = (string) ($i['token'] ?? '');
        // Der Abgleich kann länger als die 3 Sekunden dauern, die Discord für die Antwort lässt.
        $res = Response::json(['type' => self::DEFERRED, 'data' => ['flags' => self::EPHEMERAL]]);
        $res->afterSend(static function () use ($orgId, $token): void {
            try {
                $r = Onboarding::syncAllowlist($orgId);
                $note = "Fertig: {$r['total']} Mitglieder dürfen sich anmelden (+{$r['added']}, −{$r['removed']})" . Onboarding::deletionNote($r) . '.';
            } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
                $note = 'Abgleich nicht möglich: ' . $e->getMessage();
            }
            DiscordBot::editOriginal($token, ['content' => $note, 'allowed_mentions' => ['parse' => []]]);
        });
        return $res;
    }

    /**
     * /design: Logo, Hintergrund und Deckkraft der Installation. Nur Server-Admins (vorher geprüft) eines eingerichteten
     * Servers. Das Laden und Umrechnen der Bilder läuft nach der Antwort, weil Discord nur 3 Sekunden Zeit lässt.
     * @param array<string,mixed> $i
     */
    private static function designCommand(string $guildId, array $i): Response
    {
        if (Db::val('SELECT 1 FROM organizations WHERE discord_guild_id = ?', [$guildId]) === null) {
            return self::say('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.');
        }
        $opts = [];
        foreach ((array) ($i['data']['options'] ?? []) as $opt) {
            if (is_array($opt) && isset($opt['name'])) {
                $opts[(string) $opt['name']] = $opt['value'] ?? null;
            }
        }
        $url = static function (string $name) use ($opts, $i): ?string {
            $a = $opts[$name] ?? null;
            $att = is_string($a) ? ($i['data']['resolved']['attachments'][$a] ?? null) : null;
            return is_array($att) && isset($att['url']) ? (string) $att['url'] : null;
        };
        $logo = $url('logo');
        $background = $url('hintergrund');
        $dark = isset($opts['deckkraft_dunkel']) ? (int) $opts['deckkraft_dunkel'] : null;
        $light = isset($opts['deckkraft_hell']) ? (int) $opts['deckkraft_hell'] : null;
        $reset = isset($opts['zuruecksetzen']) ? (string) $opts['zuruecksetzen'] : null;

        if ($logo === null && $background === null && $dark === null && $light === null && $reset === null) {
            $b = Branding::current();
            return self::say(
                'Aktuell: Logo ' . ($b['logoCustom'] ? 'eigenes' : 'Standard') . ', Hintergrund ' . ($b['backgroundCustom'] ? 'eigener' : 'Standard')
                . ", Deckkraft dunkel {$b['opacityDark']} %, hell {$b['opacityLight']} %.\n"
                . 'Mit /design kannst du ein Logo, ein Hintergrundbild und die Deckkraft (getrennt für dunkel und hell) setzen oder auf Standard zurücksetzen.',
            );
        }
        $token = (string) ($i['token'] ?? '');
        $res = Response::json(['type' => self::DEFERRED, 'data' => ['flags' => self::EPHEMERAL]]);
        $res->afterSend(static function () use ($logo, $background, $dark, $light, $reset, $token): void {
            $done = [];
            try {
                if ($reset !== null) {
                    Branding::reset($reset);
                    $done[] = 'zurückgesetzt (' . ['logo' => 'Logo', 'background' => 'Hintergrund', 'all' => 'alles'][$reset] . ')';
                }
                if ($logo !== null) {
                    Branding::setImage('logo', Branding::fetch($logo));
                    $done[] = 'Logo gesetzt';
                }
                if ($background !== null) {
                    Branding::setImage('background', Branding::fetch($background));
                    $done[] = 'Hintergrund gesetzt';
                }
                if ($dark !== null || $light !== null) {
                    Branding::setOpacity($dark, $light);
                    $done[] = 'Deckkraft ' . implode(', ', array_filter([$dark !== null ? "dunkel $dark %" : null, $light !== null ? "hell $light %" : null]));
                }
                $note = 'Fertig: ' . implode('; ', $done) . '. Im Browser hilft ggf. Strg+F5.';
            } catch (OrgError $e) {
                $note = ($done ? 'Teilweise erledigt (' . implode('; ', $done) . '). ' : '') . 'Nicht möglich: ' . $e->getMessage();
            }
            DiscordBot::editOriginal($token, ['content' => $note, 'allowed_mentions' => ['parse' => []]]);
        });
        return $res;
    }

    /**
     * /einrichten mit dem RSI-Kürzel der Orga (Pflichtfeld, „-“ = keine): wird sofort geprüft (nur Seite 1 der Mitgliederliste),
     * der volle Abgleich läuft danach im Hintergrund und aktualisiert die Nachricht.
     * @param array<string,mixed> $org @param array<string,mixed> $i
     */
    private static function setupCommand(array $org, array $i): Response
    {
        $sid = null;
        foreach ((array) ($i['data']['options'] ?? []) as $opt) {
            if (is_array($opt) && ($opt['name'] ?? '') === 'rsi_kuerzel') {
                $sid = trim((string) ($opt['value'] ?? ''));
            }
            if (is_array($opt) && ($opt['name'] ?? '') === 'handle_stil') {
                try {
                    Onboarding::setHandleStyle((string) $org['id'], (string) ($opt['value'] ?? ''));
                    $org = Orgs::find((string) $org['id']) ?? $org;
                } catch (OrgError $e) {
                    return self::say($e->getMessage());
                }
            }
        }
        // „-“ heißt: keine RSI-Orga (das Feld ist Pflicht, damit es beim Einrichten nicht übersehen wird).
        if ($sid === null || $sid === '' || $sid === '-') {
            return self::panel($org, self::REPLY, null);
        }
        try {
            $name = RsiOrg::connect((string) $org['id'], $sid);
        } catch (OrgError $e) {
            return self::panel($org, self::REPLY, 'RSI-Orga nicht übernommen: ' . $e->getMessage());
        }
        $org = Orgs::find((string) $org['id']) ?? $org;
        $res = self::panel($org, self::REPLY, "RSI-Orga „{$name}“ verbunden, die Mitgliederliste wird abgeglichen …");
        $orgId = (string) $org['id'];
        $token = (string) ($i['token'] ?? '');
        // Der Abgleich dauert einige Sekunden (eine Abfrage je 32 Mitglieder), Discord lässt aber nur 3 Sekunden für die Antwort.
        $res->afterSend(static function () use ($orgId, $token, $name): void {
            try {
                $r = RsiOrg::sync($orgId);
                $note = "RSI-Orga „{$name}“ verbunden: {$r['members']} sichtbare Mitglieder, {$r['redacted']} verborgen.";
            } catch (OrgError $e) {
                $note = 'RSI-Abgleich nicht möglich: ' . $e->getMessage();
            }
            $o = Orgs::find($orgId);
            if ($o !== null) {
                DiscordBot::editOriginal($token, self::panelData($o, $note));
            }
        });
        return $res;
    }

    /** @param array<string,mixed> $org @param array<string,mixed> $i */
    private static function component(array $org, array $i): Response
    {
        $id = (string) ($i['data']['custom_id'] ?? '');
        $token = (string) ($i['token'] ?? '');
        $sync = false;
        if ($id === 'onb:use' || $id === 'onb:plan' || $id === 'onb:recruit' || $id === 'onb:instruct' || $id === 'onb:lead') {
            $values = array_values(array_map('strval', (array) ($i['data']['values'] ?? [])));
            $names = [];
            foreach ((array) ($i['data']['resolved']['roles'] ?? []) as $rid => $role) {
                if (is_array($role) && isset($role['name'])) {
                    $names[(string) $rid] = (string) $role['name'];
                }
            }
            Onboarding::setRoles($org['id'], ['onb:use' => 'use', 'onb:plan' => 'plan', 'onb:recruit' => 'recruit', 'onb:instruct' => 'instruct', 'onb:lead' => 'lead'][$id], $values, $names);
            $org = Orgs::find($org['id']) ?? $org;
            $sync = Orgs::parseRoleIds($org['member_role_ids']) !== [];
        } elseif ($id === 'onb:sync') {
            $sync = true;
        } else {
            return self::say('Unbekannte Aktion.');
        }

        $res = self::panel($org, self::UPDATE, $sync ? 'Mitglieder werden abgeglichen …' : null);
        if ($sync) {
            $orgId = (string) $org['id'];
            // Der Abgleich kann länger als die 3 Sekunden dauern, die Discord für die Antwort lässt.
            $res->afterSend(static function () use ($orgId, $token): void {
                try {
                    $r = Onboarding::syncAllowlist($orgId);
                    $note = "Fertig: {$r['total']} Mitglieder dürfen sich anmelden (+{$r['added']}, −{$r['removed']})" . Onboarding::deletionNote($r) . '.';
                } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
                    $note = 'Abgleich nicht möglich: ' . $e->getMessage();
                }
                $org = Orgs::find($orgId);
                if ($org !== null) {
                    DiscordBot::editOriginal($token, self::panelData($org, $note));
                }
            });
        }
        return $res;
    }

    /** @param array<string,mixed> $org */
    private static function panel(array $org, int $type, ?string $note): Response
    {
        return Response::json(['type' => $type, 'data' => self::panelData($org, $note)]);
    }

    /**
     * Nachricht mit zwei Rollenauswahlen (Nutzung, Planung) und der Schaltfläche für den Abgleich.
     * @param array<string,mixed> $org @return array<string,mixed>
     */
    public static function panelData(array $org, ?string $note): array
    {
        $labels = Orgs::parseRoleLabels($org['role_labels']);
        $select = static function (string $customId, string $placeholder, array $ids): array {
            $s = ['type' => 6, 'custom_id' => $customId, 'placeholder' => $placeholder, 'min_values' => 0, 'max_values' => Orgs::MAX_ROLES];
            if ($ids !== []) {
                $s['default_values'] = array_map(static fn (string $id): array => ['id' => $id, 'type' => 'role'], $ids);
            }
            return ['type' => 1, 'components' => [$s]];
        };
        $use = Orgs::parseRoleIds($org['member_role_ids']);
        $plan = Orgs::parseRoleIds($org['planner_role_ids']);
        $recruit = Orgs::parseRoleIds($org['recruiter_role_ids'] ?? null);
        $instruct = Orgs::parseRoleIds($org['instructor_role_ids'] ?? null);
        $lead = Orgs::parseRoleIds($org['lead_role_ids'] ?? null);
        $names = static fn (array $ids): string => $ids === [] ? '–' : implode(', ', array_map(static fn (string $id): string => $labels[$id] ?? "<@&$id>", $ids));
        $text = "**Community-Hangar: {$org['name']}**\n"
            . "Dürfen das Tool nutzen: {$names($use)}\n"
            . "Dürfen Events planen: {$names($plan)}\n"
            . "Recruiter (sehen die RSI-Zugehörigkeit): {$names($recruit)}\n"
            . "Ausbilder (pflegen die Ausbildung): {$names($instruct)}\n"
            . "Abteilungsleiter (pflegen die Abteilungen): {$names($lead)}\n"
            . (!empty($org['rsi_sid']) ? "RSI-Orga: {$org['rsi_org_name']} ({$org['rsi_sid']})\n" : '')
            . 'RSI-Handle im Nickname: ' . \Hangar\RsiOrg::HANDLE_STYLES[\Hangar\RsiOrg::cleanHandleStyle($org['handle_style'] ?? null)] . "\n"
            . "\n"
            . 'Wähle unten die Rollen. Wer eine Nutzungs-, Planer-, Recruiter-, Ausbilder- oder Abteilungsleiter-Rolle hat, darf sich auf ' . \Hangar\Config::appUrl() . ' anmelden.'
            . ($note !== null ? "\n\n$note" : '');
        return [
            'flags' => self::EPHEMERAL,
            'content' => $text,
            'allowed_mentions' => ['parse' => []],
            'components' => [
                $select('onb:use', 'Wer darf das Tool nutzen?', $use),
                $select('onb:plan', 'Wer darf Events planen? (optional)', $plan),
                $select('onb:recruit', 'Wer sind die Recruiter? (optional)', $recruit),
                $select('onb:instruct', 'Wer sind die Ausbilder? (optional)', $instruct),
                // Discord erlaubt höchstens 5 Zeilen: Der Abgleich läuft nach jeder Rollenänderung und per /abgleichen.
                $select('onb:lead', 'Wer sind die Abteilungsleiter? (optional)', $lead),
            ],
        ];
    }

    private static function say(string $text): Response
    {
        return Response::json(['type' => self::REPLY, 'data' => ['content' => $text, 'flags' => self::EPHEMERAL, 'allowed_mentions' => ['parse' => []]]]);
    }
}
