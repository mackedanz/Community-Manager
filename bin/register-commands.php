<?php

declare(strict_types=1);

// Registriert die Slash-Befehle des Onboarding-Bots bei Discord (einmalig und nach Änderungen).
// Braucht AUTH_DISCORD_ID und DISCORD_BOT_TOKEN. Nutzung: php bin/register-commands.php
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (Hangar\Env::get('DISCORD_BOT_TOKEN') === null || Hangar\Env::get('AUTH_DISCORD_ID') === null) {
    fwrite(STDERR, "DISCORD_BOT_TOKEN und AUTH_DISCORD_ID müssen gesetzt sein.\n");
    exit(1);
}
$status = Hangar\DiscordBot::registerCommands([
    [
        'name' => 'einrichten',
        'description' => 'Community-Hangar für diesen Server einrichten: Rollen wählen und Mitglieder übernehmen',
        'type' => 1,
        'options' => [[
            'type' => 3, // STRING
            'name' => 'rsi_kuerzel',
            'description' => 'Kürzel der Orga auf RSI (z. B. EXPG). Gibt es keine RSI-Orga: - eingeben',
            'required' => true,
            'max_length' => 20,
        ], [
            'type' => 3,
            'name' => 'handle_stil',
            'description' => 'Wo steht der RSI-Handle im Server-Nickname? (Standard: außerhalb aller Klammern)',
            'required' => false,
            'choices' => [
                ['name' => 'Außerhalb aller Klammern', 'value' => 'plain'],
                ['name' => 'In runden Klammern ( )', 'value' => 'round'],
                ['name' => 'In eckigen Klammern [ ]', 'value' => 'square'],
                ['name' => 'In geschweiften Klammern { }', 'value' => 'curly'],
                ['name' => 'In spitzen Klammern < >', 'value' => 'angle'],
            ],
        ]],
        'default_member_permissions' => '32', // "Server verwalten"; die App prüft die Rechte zusätzlich selbst
        'dm_permission' => false,
    ],
    [
        'name' => 'design',
        'description' => 'Aussehen der App: Logo, Hintergrundbild und Deckkraft (nur Server-Admins)',
        'type' => 1,
        'options' => [
            ['type' => 11, 'name' => 'logo', 'description' => 'Neues Logo (PNG, JPG oder WebP)', 'required' => false],
            ['type' => 11, 'name' => 'hintergrund', 'description' => 'Neues Hintergrundbild (PNG, JPG oder WebP)', 'required' => false],
            ['type' => 4, 'name' => 'deckkraft_dunkel', 'description' => 'Deckkraft des Hintergrunds im dunklen Modus in Prozent (Standard 10)', 'required' => false, 'min_value' => 0, 'max_value' => 100],
            ['type' => 4, 'name' => 'deckkraft_hell', 'description' => 'Deckkraft des Hintergrunds im hellen Modus in Prozent (Standard 40)', 'required' => false, 'min_value' => 0, 'max_value' => 100],
            ['type' => 3, 'name' => 'zuruecksetzen', 'description' => 'Auf die Standardwerte zurücksetzen', 'required' => false, 'choices' => [
                ['name' => 'Logo', 'value' => 'logo'], ['name' => 'Hintergrund', 'value' => 'background'], ['name' => 'Alles', 'value' => 'all'],
            ]],
        ],
        'default_member_permissions' => '32',
        'dm_permission' => false,
    ],
    [
        'name' => 'abgleichen',
        'description' => 'Mitglieder mit den Discord-Rollen abgleichen: wer darf sich im Community-Hangar anmelden',
        'type' => 1,
        'dm_permission' => false, // für alle sichtbar; die App erlaubt es nur Mitgliedern der Orga und Server-Admins
    ],
]);
echo $status >= 200 && $status < 300 ? "Befehle registriert.\n" : "Discord antwortet mit Status $status.\n";
exit($status >= 200 && $status < 300 ? 0 : 1);
