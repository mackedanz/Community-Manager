<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Regeln mit „Bestätigen“-Knopf in einem Kanal veröffentlichen (Gegenstück zu /serverregeln im Bot, BOT/src/commands/serverregeln.js).
 * Die Rollen-ID steckt im Knopf selbst (custom_id rules:ok:<Rolle>); der Bot vergibt die Rolle, auch nach einem Neustart.
 */
final class DiscordRules
{
    public const MAX_TITLE = 256;
    public const MAX_TEXT = 4000;
    public const DEFAULT_TITLE = 'Serverregeln';
    /** Wie der Standardtext von /discord-setup. */
    public const DEFAULT_TEXT = "**1.** Geh respektvoll miteinander um. Beleidigungen, Hetze und Diskriminierung gibt es hier nicht.\n"
        . "**2.** Kein Spam, keine Werbung und keine Einladungslinks zu anderen Servern.\n"
        . "**3.** Bleib beim Thema des jeweiligen Kanals.\n"
        . "**4.** Den Anweisungen der Moderatoren ist zu folgen.\n\n"
        . 'Mit „Bestätigen“ erklärst du dich einverstanden und bekommst Zugang.';

    /** Nachricht mit den Regeln und dem Knopf „Bestätigen“. @return array<string,mixed> */
    public static function message(string $title, string $text, string $roleId): array
    {
        return [
            'embeds' => [['title' => $title !== '' ? $title : self::DEFAULT_TITLE, 'description' => $text, 'color' => 0x0ea5e9]],
            'components' => [['type' => 1, 'components' => [['type' => 2, 'style' => 3, 'label' => 'Bestätigen', 'custom_id' => 'rules:ok:' . $roleId]]]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /**
     * Grund, warum der Bot diese Rolle nicht vergeben kann (null = in Ordnung).
     * @param list<array{id:string,managed:bool,position:int}> $roles Ergebnis von DiscordBot::roleDetails()
     * @param ?array{permissions:int,top:int} $bot Ergebnis von DiscordBot::botMember()
     */
    public static function roleProblem(string $guildId, string $roleId, array $roles, ?array $bot): ?string
    {
        if ($roleId === $guildId) {
            return 'Die Rolle @everyone hat ohnehin jeder. Bitte eine andere Rolle wählen.';
        }
        $role = null;
        foreach ($roles as $r) {
            if ($r['id'] === $roleId) {
                $role = $r;
            }
        }
        if ($role === null) {
            return 'Diese Rolle gibt es auf dem Server nicht (mehr). Bitte die Seite neu laden.';
        }
        if ($role['managed']) {
            return 'Diese Rolle gehört zu einer Integration oder einem Bot und lässt sich nicht vergeben.';
        }
        if ($bot !== null) {
            if ($bot['permissions'] !== -1 && ($bot['permissions'] & (1 << 28)) === 0) {
                return 'Dem Bot fehlt das Recht „Rollen verwalten“. Bitte in den Servereinstellungen bei der Rolle des Bots einschalten.';
            }
            if ($bot['top'] <= $role['position']) {
                return 'Die Rolle des Bots steht unter der gewählten Rolle. Bitte in den Servereinstellungen unter „Rollen“ die Rolle des Bots darüber ziehen.';
            }
        }
        return null;
    }

    /**
     * Prüft und veröffentlicht. @return string Link zur Nachricht
     * @throws OrgError|DiscordAuthError|DiscordUnavailableError
     */
    public static function publish(string $guildId, string $channelId, string $roleId, string $title, string $text): string
    {
        $title = trim($title);
        $text = trim($text);
        if ($text === '') {
            throw new OrgError('Bitte den Text der Regeln eingeben.');
        }
        if (mb_strlen($title) > self::MAX_TITLE || mb_strlen($text) > self::MAX_TEXT) {
            throw new OrgError('Überschrift höchstens ' . self::MAX_TITLE . ' und Text höchstens ' . self::MAX_TEXT . ' Zeichen.');
        }
        if (!in_array($channelId, array_column(DiscordBot::textChannels($guildId), 'id'), true)) {
            throw new OrgError('Bitte einen Kanal auswählen.');
        }
        $roles = DiscordBot::roleDetails($guildId);
        $problem = self::roleProblem($guildId, $roleId, $roles, DiscordBot::botMember($guildId, $roles));
        if ($problem !== null) {
            throw new OrgError($problem);
        }
        $id = DiscordBot::sendMessage($channelId, self::message($title, $text, $roleId));
        return "https://discord.com/channels/$guildId/$channelId/$id";
    }
}
