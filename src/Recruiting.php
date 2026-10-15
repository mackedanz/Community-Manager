<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Fragenkatalog für /recruit im Discord-Bot (Tabelle bot_recruit_questions, Migration 026). Kanal und berechtigte Rollen stehen in bot_settings
 * (recruit_channel_id, recruit_role_ids). Grenzen wie in BOT/src/lib/recruit.js: Discord erlaubt 45 Zeichen für die Frage im Eingabefenster,
 * 100 für den Hilfetext und 5 Felder je Fenster, der Bot teilt den Katalog deshalb in Teile; mehr als 25 Fragen (5 Fenster) gibt es nicht.
 */
final class Recruiting
{
    public const MAX_QUESTIONS = 25;
    public const MAX_LABEL = 45;
    public const MAX_HINT = 100;
    public const PER_MODAL = 5;

    /** @return list<array{label:string,hint:string,required:bool,long:bool}> */
    public static function questions(string $orgId): array
    {
        $rows = Db::all('SELECT label, hint, required, long_answer FROM bot_recruit_questions WHERE org_id = ? ORDER BY position ASC, id ASC LIMIT ' . self::MAX_QUESTIONS, [$orgId]);
        return array_map(static fn ($r) => ['label' => (string) $r['label'], 'hint' => (string) ($r['hint'] ?? ''), 'required' => (int) $r['required'] === 1, 'long' => (int) $r['long_answer'] === 1], $rows);
    }

    /** Wie viele Eingabefenster braucht ein Katalog dieser Länge? */
    public static function parts(int $questions): int
    {
        return (int) ceil($questions / self::PER_MODAL);
    }

    /**
     * Die Fragen aus dem Formular in der Reihenfolge, in der sie abgeschickt wurden. Ganz leere Zeilen zählen nicht.
     * @param array<string,mixed> $post q[n][label|hint|required|long]
     * @return list<array{label:string,hint:string,required:bool,long:bool}>
     * @throws OrgError
     */
    public static function parse(array $post): array
    {
        $out = [];
        foreach (is_array($post['q'] ?? null) ? array_values($post['q']) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $hint = trim((string) ($row['hint'] ?? ''));
            if ($label === '' && $hint === '') {
                continue;
            }
            $n = count($out) + 1;
            if ($label === '') {
                throw new OrgError("Frage $n hat einen Hilfetext, aber keine Frage.");
            }
            if (mb_strlen($label) > self::MAX_LABEL) {
                throw new OrgError('Frage ' . $n . ' ist zu lang: Discord erlaubt im Eingabefenster höchstens ' . self::MAX_LABEL . ' Zeichen. Längere Erklärungen gehören in den Hilfetext.');
            }
            if (mb_strlen($hint) > self::MAX_HINT) {
                throw new OrgError("Der Hilfetext von Frage $n ist zu lang (höchstens " . self::MAX_HINT . ' Zeichen).');
            }
            $out[] = ['label' => $label, 'hint' => $hint, 'required' => ($row['required'] ?? '') === '1', 'long' => ($row['long'] ?? '') === '1'];
        }
        if (count($out) > self::MAX_QUESTIONS) {
            throw new OrgError('Höchstens ' . self::MAX_QUESTIONS . ' Fragen (5 Eingabefenster mit je 5 Fragen).');
        }
        return $out;
    }

    /** Ersetzt den Fragenkatalog der Orga. @param list<array{label:string,hint:string,required:bool,long:bool}> $questions */
    public static function save(string $orgId, array $questions): void
    {
        Db::transaction(static function () use ($orgId, $questions): void {
            Db::run('DELETE FROM bot_recruit_questions WHERE org_id = ?', [$orgId]);
            foreach ($questions as $i => $q) {
                Db::insert('bot_recruit_questions', [
                    'id' => new_id(), 'org_id' => $orgId, 'position' => $i + 1, 'label' => $q['label'], 'hint' => $q['hint'] === '' ? null : $q['hint'],
                    'required' => $q['required'] ? 1 : 0, 'long_answer' => $q['long'] ? 1 : 0,
                ]);
            }
        });
    }
}
