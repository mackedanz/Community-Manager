<?php
/** @var array{slug:string,name:string} $org */
/** @var string $section */
/** @var array<string,string> $settings */
/** @var array{spam:bool,invites:bool,links:bool,mentions:int,words:list<string>,domains:list<string>,exemptChannels:list<string>} $automod */
/** @var array<string,bool> $status */
/** @var list<array{id:string,name:string,category:?string}> $channels */
/** @var list<array{id:string,name:string,color:int}> $roles */
/** @var ?string $problem */
/** @var bool $messageContent */
/** @var list<array<string,mixed>> $templates */
/** @var ?array{template:array<string,mixed>,plan:array{items:list<array<string,mixed>>,warnings:list<string>}} $preview */
/** @var array<string,string> $suggest Einstellung => vorgeschlagene Kanal-/Rollen-ID (nur Zuordnung) */
/** @var array<string,string> $usedChannels Kanal-ID => wofür der Bot ihn nutzt */
/** @var ?array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure */
/** @var list<array<string,mixed>> $cases */
/** @var list<array<string,mixed>> $creators */
/** @var string $guildId */
/** @var bool $twitchActive */
/** @var ?array<string,mixed> $rights */
/** @var array{rows:list<array<string,mixed>>,total:int} $modCases */
/** @var string $modQuery */
/** @var string $modAction */
/** @var int $modPage */
/** @var string $csrf */
use Hangar\BotSettings;

$base = '/o/' . $org['slug'] . '/bot';
$navKey = BotSettings::SUBSECTIONS[$section] ?? $section; // Menüpunkt, unter dem die aktuelle Seite steht
$field = 'w-full rounded-md border border-cyan-800/60 bg-zinc-950/60 px-3 py-2 text-sm focus:border-cyan-400 focus:outline-none';
$card = 'rounded-lg border border-cyan-800/50 bg-teal-950/40';
$hint = 'text-xs text-zinc-500';
$set = static fn (string $k): string => $settings[$k] ?? '';

// Auswahl eines Textkanals, nach Kategorien gruppiert; ein gespeicherter Kanal, den es nicht mehr gibt, bleibt sichtbar.
$channelSelect = static function (string $name, string $current, string $empty = '– keiner –') use ($channels, $field): string {
    $html = '<select name="' . e($name) . '" class="' . $field . '"><option value="">' . e($empty) . '</option>';
    if ($current !== '' && !in_array($current, array_column($channels, 'id'), true)) {
        $html .= '<option value="' . e($current) . '" selected>Kanal nicht mehr verfügbar (' . e($current) . ')</option>';
    }
    $group = false;
    foreach ($channels as $c) {
        if ($group !== $c['category']) {
            $html .= ($group !== false ? '</optgroup>' : '') . '<optgroup label="' . e($c['category'] ?? 'Ohne Kategorie') . '">';
            $group = $c['category'];
        }
        $html .= '<option value="' . e($c['id']) . '"' . ($c['id'] === $current ? ' selected' : '') . '># ' . e($c['name']) . '</option>';
    }
    return $html . ($group !== false ? '</optgroup>' : '') . '</select>';
};
$roleSelect = static function (string $name, string $current) use ($roles, $field): string {
    $html = '<select name="' . e($name) . '" class="' . $field . '"><option value="">– keine –</option>';
    if ($current !== '' && !in_array($current, array_column($roles, 'id'), true)) {
        $html .= '<option value="' . e($current) . '" selected>Rolle nicht mehr verfügbar (' . e($current) . ')</option>';
    }
    foreach ($roles as $r) {
        $html .= '<option value="' . e($r['id']) . '"' . ($r['id'] === $current ? ' selected' : '') . '>@' . e($r['name']) . '</option>';
    }
    return $html . '</select>';
};
// Mehrfachauswahl als anklickbare Chips (Checkboxen, ohne JavaScript bedienbar).
$chips = static function (string $name, array $items, array $selected, string $prefix): string {
    $known = array_column($items, 'id');
    foreach ($selected as $id) {
        if (!in_array($id, $known, true)) {
            $items[] = ['id' => $id, 'name' => "nicht mehr verfügbar ($id)"];
        }
    }
    $html = '<div class="flex flex-wrap gap-2">';
    foreach ($items as $it) {
        $html .= '<label class="relative cursor-pointer"><input type="checkbox" name="' . e($name) . '[]" value="' . e($it['id']) . '" class="peer sr-only"'
            . (in_array($it['id'], $selected, true) ? ' checked' : '') . '>'
            . '<span class="inline-block rounded-full border border-zinc-700 px-3 py-1 text-sm text-zinc-400 transition hover:border-cyan-600 '
            . 'peer-checked:border-cyan-400/70 peer-checked:bg-cyan-500/15 peer-checked:text-cyan-300 peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-500">'
            . e($prefix . $it['name']) . '</span></label>';
    }
    return $html . '</div>';
};
// Ein/Aus-Schalter (Checkbox mit Wert 1).
$switch = static fn (string $name, bool $on, string $label): string => '<label class="flex cursor-pointer items-center justify-between gap-3">'
    . '<span class="text-sm">' . e($label) . '</span><span class="relative inline-flex shrink-0">'
    . '<input type="checkbox" name="' . e($name) . '" value="1" class="peer sr-only"' . ($on ? ' checked' : '') . '>'
    . '<span class="h-6 w-11 rounded-full border border-zinc-600 bg-zinc-800 transition peer-checked:border-cyan-400 peer-checked:bg-cyan-600/50 peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-500"></span>'
    . '<span class="pointer-events-none absolute left-1 top-1 h-4 w-4 rounded-full bg-zinc-400 transition peer-checked:translate-x-5 peer-checked:bg-cyan-200"></span>'
    . '</span></label>';
$dot = static fn (bool $on): string => '<span class="h-2 w-2 shrink-0 rounded-full ' . ($on ? 'bg-cyan-400 shadow-[0_0_8px_rgba(34,211,238,0.9)]' : 'bg-zinc-600') . '"></span>';
$caseLabels = ['WARN' => 'Verwarnung', 'TIMEOUT' => 'Timeout', 'UNTIMEOUT' => 'Timeout aufgehoben', 'KICK' => 'Kick', 'BAN' => 'Bann', 'UNBAN' => 'Bann aufgehoben', 'PURGE' => 'Nachrichten gelöscht', 'AUTOMOD' => 'Automod'];
$descriptions = [
    'allgemein' => 'Wichtige Hinweise zu den Einstellungen des Bots.',
    'termine' => 'Der Bot kündigt Termine an, erinnert 24 und 1 Stunde vorher und lässt Plätze per Knopf wählen.',
    'willkommen' => 'Begrüßt neue Mitglieder. „Bestätigen“ unter den Regeln vergibt die Besucher-Rolle.',
    'moderation' => 'Wer mit /mod verwarnen, stummschalten, kicken und bannen darf und wohin der Bot das protokolliert.',
    'automod' => 'Prüft Nachrichten automatisch. Drei Verstöße in einer Stunde führen zu 10 Minuten Timeout. Admins, Moderatoren und Bots sind ausgenommen.',
    'setup' => 'Kategorien und Kanäle (Text, Sprache, Ankündigung) mit Rechten auf dem Server anlegen, vorhandene ändern, verschieben oder löschen.',
    'creator' => 'YouTube-Kanäle, deren neue Videos der Bot in einen Discord-Kanal postet (Prüfung alle 10 Minuten, ältere Videos nicht), und Twitch-Kanäle, bei denen er meldet, wenn sie live gehen (Prüfung alle 2 Minuten).',
    'regeln' => 'Veröffentlicht einen Regeltext in einem Kanal, darunter der Knopf „Bestätigen“. Wer ihn drückt, bekommt die gewählte Rolle (meist die Besucher-Rolle).',
    'faelle' => 'Verwarnen, Timeout, Kick und Bann direkt im Manager und die Liste aller Fälle.',
    'zuordnung' => 'Legt fest, welchen Kanal und welche Rolle der Bot wofür nutzt. Nach dem Aufbau des Servers kannst du hier alles auf einmal eintragen; die Vorschläge kommen aus den Namen.',
    'vorlagen' => 'Den Aufbau des Servers (Rollen, Kategorien, Kanäle mit Rechten) als Vorlage festhalten und eine Vorlage auf den Server anwenden. Vor dem Anwenden siehst du, was sich ändert; gelöscht wird nur, was du ausdrücklich ankreuzt.',
    'rollen' => 'Neue Rollen mit Rechten auf dem Server anlegen: Name, Farbe und was die Rolle darf. Vorhandene Rollen lassen sich ändern und löschen.',
];
// Reiter innerhalb eines Bereichs (Discord-Setup, Moderation).
$tabsOf = static function (array $items, string $active) use ($base): string {
    $html = '<div class="flex gap-2 border-b border-cyan-900/50 text-sm" role="tablist">';
    foreach ($items as $k => $label) {
        $html .= '<a href="' . e($base . '?m=' . $k) . '" role="tab"' . ($k === $active ? ' aria-selected="true"' : '')
            . ' class="-mb-px border-b-2 px-4 py-2 transition ' . ($k === $active ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-zinc-400 hover:text-zinc-200') . '">' . e($label) . '</a>';
    }
    return $html . '</div>';
};
$setupTabs = static fn (string $active): string => $tabsOf(['setup' => 'Server aufbauen', 'vorlagen' => 'Vorlagen', 'zuordnung' => 'Zuordnung'], $active);
$welcomeTabs = static fn (string $active): string => $tabsOf(['willkommen' => 'Begrüßung', 'regeln' => 'Regeln veröffentlichen'], $active);
$modTabs = static fn (string $active): string => $tabsOf(['moderation' => 'Einstellungen', 'faelle' => 'Fälle und Maßnahmen'], $active);
$typeIcon = [0 => '#', 2 => '🔊', 5 => '📢', 13 => '🎙', 15 => '💬'];
// Rollen als anklickbare Chips für das Setup; der Name enthält Platzhalter, die das JavaScript beim Kopieren der Vorlage ersetzt.
$roleBoxes = static fn (string $name): string => $roles === [] ? '<p class="' . $hint . '">Keine Rollen geladen.</p>' : $chips($name, $roles, [], '@');
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-end justify-between gap-2">
    <div>
      <h1 class="text-2xl font-semibold tracking-wide">Discord-Bot</h1>
      <p class="text-sm text-zinc-400">Einstellungen für deinen Discord-Server. Die Slash-Befehle funktionieren weiterhin.</p>
    </div>
    <span class="text-sm text-zinc-400"><?= count(array_filter($status)) ?> von <?= count($status) ?> Bereichen eingerichtet</span>
  </div>

  <?php if ($problem !== null): ?>
    <p class="rounded-md border border-amber-500/40 bg-amber-950/40 px-4 py-3 text-sm text-amber-200"><?= e($problem) ?> Gespeicherte Werte bleiben erhalten.</p>
  <?php endif; ?>

  <!-- Schmale Bildschirme: Bereich per Auswahlliste -->
  <form method="get" action="<?= e($base) ?>" class="md:hidden">
    <select name="m" class="<?= $field ?>" onchange="this.form.submit()" aria-label="Bereich">
      <?php foreach (BotSettings::SECTIONS as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= $k === $navKey ? ' selected' : '' ?>><?= e($label) ?><?= isset($status[$k]) ? ($status[$k] ? ' · eingerichtet' : ' · aus') : '' ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="mt-2 rounded border border-zinc-600 px-3 py-1 text-sm">Anzeigen</button></noscript>
  </form>

  <div class="grid gap-6 md:grid-cols-[230px_minmax(0,1fr)]">
    <nav class="hidden self-start rounded-lg border border-cyan-800/50 bg-teal-950/30 p-2 md:block" aria-label="Bereiche">
      <?php foreach (BotSettings::SECTIONS as $k => $label): ?>
        <a href="<?= e($base . '?m=' . $k) ?>"<?= $k === $navKey ? ' aria-current="page"' : '' ?>
           class="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm transition <?= $k === $navKey ? 'border-cyan-400/50 bg-teal-900/50 text-cyan-300' : 'border-transparent text-zinc-300 hover:bg-teal-900/30' ?>">
          <span><?= e($label) ?></span><?= isset($status[$k]) ? $dot($status[$k]) : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($section === 'rollen'): ?>
    <?php
      $botPerms = $roleInfo['bot'] ?? null;
      $mayGrant = static fn (int $bit): bool => $botPerms === null || ($botPerms & (1 << $bit)) !== 0;
      $groups = [];
      foreach (\Hangar\DiscordRoles::PERMISSIONS as $bit => [$title, $group]) {
          $groups[$group][$bit] = $title;
      }
      // Rechte als Kästchen; $have = bereits gesetzte Rechte (Bitmaske). Was der Bot nicht vergeben darf, ist gesperrt und wird nicht gesendet.
      $permBoxes = static function (string $name, int $have = 0) use ($groups, $mayGrant): string {
          $html = '';
          foreach ($groups as $group => $items) {
              $html .= '<fieldset class="space-y-1"><legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">' . e($group) . '</legend>';
              foreach ($items as $bit => $title) {
                  $ok = $mayGrant($bit);
                  $html .= '<label class="flex items-center gap-2 text-sm ' . ($ok ? 'text-zinc-300' : 'cursor-not-allowed text-zinc-600') . '"'
                      . ($ok ? '' : ' title="Der Bot hat dieses Recht selbst nicht und kann es nicht ändern"') . '>'
                      . '<input type="checkbox" name="' . e($name) . '" value="' . (int) $bit . '" class="accent-cyan-400"'
                      . (($have & (1 << $bit)) !== 0 ? ' checked' : '') . ($ok ? '' : ' disabled') . '> ' . e($title) . '</label>';
              }
              $html .= '</fieldset>';
          }
          return $html;
      };
      $presets = [];
      foreach (\Hangar\DiscordRoles::PRESETS as $title => $bits) {
          $presets[$title] = $bits;
      }
    ?>
    <div class="space-y-4">
      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold"><?= e(BotSettings::SECTIONS['rollen']) ?></h2>
          <p class="text-sm text-zinc-400"><?= e($descriptions['rollen']) ?></p>
        </header>
        <div class="space-y-4 p-5">
          <?php if ($roleInfo === null): ?>
            <p class="<?= $hint ?>">Die Rollen konnten nicht geladen werden, deshalb ist das Anlegen gesperrt.</p>
          <?php else: ?>
            <p class="<?= $hint ?>"><?php
              if ($botPerms === null) { echo 'Welche Rechte der Bot vergeben darf, ließ sich nicht ermitteln; Discord prüft das beim Anlegen.'; }
              elseif ($botPerms === -1) { echo 'Der Bot hat Administrator-Rechte und kann jedes Recht vergeben.'; }
              else { echo 'Der Bot kann nur Rechte vergeben, die er selbst hat. Ausgegraute Rechte fehlen ihm.'; }
            ?></p>
            <details class="rounded-md border border-cyan-900/50">
              <summary class="cursor-pointer px-4 py-2 text-sm text-zinc-300">Vorhandene Rollen (<?= count(array_filter($roleInfo['roles'], static fn ($r) => $r['name'] !== '@everyone')) ?>)</summary>
              <ul class="divide-y divide-cyan-900/40 border-t border-cyan-900/50 text-sm">
                <?php foreach ($roleInfo['roles'] as $r): ?>
                  <?php
                    $labels = \Hangar\DiscordRoles::labels($r['permissions']);
                    $editable = \Hangar\DiscordRoles::editable($r, $roleInfo['top'] ?? null);
                    $hex = $r['color'] > 0 ? sprintf('#%06x', $r['color']) : '';
                  ?>
                  <li class="px-4 py-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                      <span class="flex items-center gap-2"><span class="h-3 w-3 shrink-0 rounded-full border border-zinc-600" style="background: <?= $hex !== '' ? e($hex) : 'transparent' ?>"></span>
                        <?= e($r['name']) ?><?= $r['managed'] ? ' <span class="text-xs text-zinc-500">(Bot oder Integration)</span>' : '' ?></span>
                      <span class="text-xs text-zinc-500"><?= e($labels === [] ? 'keine besonderen Rechte' : implode(', ', array_slice($labels, 0, 5)) . (count($labels) > 5 ? ' … +' . (count($labels) - 5) : '')) ?></span>
                    </div>
                    <?php if ($editable): ?>
                      <details class="mt-2 rounded-md border border-cyan-900/50">
                        <summary class="cursor-pointer px-3 py-1.5 text-sm text-cyan-300">Bearbeiten</summary>
                        <form method="post" action="<?= e($base . '/role/update') ?>" class="space-y-3 border-t border-cyan-900/50 p-3">
                          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                          <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
                            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Name</span>
                              <input type="text" name="name" value="<?= e($r['name']) ?>" maxlength="<?= \Hangar\DiscordRoles::MAX_NAME ?>" class="<?= $field ?>"></label>
                            <div class="space-y-1.5"><span class="block text-sm text-zinc-300">Farbe</span>
                              <span class="flex items-center gap-2"><input type="checkbox" name="usecolor" value="1" class="accent-cyan-400" aria-label="Farbe setzen" data-usecolor<?= $hex !== '' ? ' checked' : '' ?>>
                                <input type="color" name="color" value="<?= e($hex !== '' ? $hex : '#3b82f6') ?>"<?= $hex === '' ? ' disabled' : '' ?> class="h-9 w-14 cursor-pointer rounded border border-cyan-800/60 bg-zinc-950/60" data-color></span></div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 pb-2 text-sm text-zinc-300">
                              <label class="flex items-center gap-2"><input type="checkbox" name="hoist" value="1" class="accent-cyan-400"<?= $r['hoist'] ? ' checked' : '' ?>> getrennt zeigen</label>
                              <label class="flex items-center gap-2"><input type="checkbox" name="mentionable" value="1" class="accent-cyan-400"<?= $r['mentionable'] ? ' checked' : '' ?>> erwähnbar</label>
                            </div>
                          </div>
                          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><?= $permBoxes('perms[]', $r['permissions']) ?></div>
                          <?php if (($r['permissions'] & (1 << 3)) !== 0): ?><p class="text-xs text-amber-300">Diese Rolle hat Administrator-Rechte. Die bleiben unverändert, auch wenn du hier speicherst.</p><?php endif; ?>
                          <div class="flex flex-wrap items-center justify-between gap-3">
                            <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-4 py-1.5 text-sm hover:bg-cyan-500/30">Speichern</button>
                            <button type="submit" form="del-role-<?= e($r['id']) ?>" class="text-sm text-red-400 hover:text-red-300">Rolle löschen</button>
                          </div>
                        </form>
                        <form id="del-role-<?= e($r['id']) ?>" method="post" action="<?= e($base . '/role/delete') ?>"
                              onsubmit="return confirm('Die Rolle &quot;<?= e(addslashes($r['name'])) ?>&quot; endgültig löschen? Alle, die sie haben, verlieren sie. Das lässt sich nicht rückgängig machen.')">
                          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                        </form>
                      </details>
                    <?php elseif ($r['position'] > 0): ?>
                      <p class="mt-1 text-xs text-zinc-600"><?= $r['managed'] ? 'Gehört einem Bot oder einer Integration und lässt sich hier nicht ändern.' : 'Steht über der Rolle des Bots, deshalb kann er sie nicht ändern. Die Bot-Rolle in Discord nach oben ziehen.' ?></p>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($roleInfo !== null): ?>
      <form method="post" action="<?= e($base . '/roles') ?>" class="space-y-4" data-roles-form data-presets="<?= e(json_encode($presets, JSON_UNESCAPED_UNICODE)) ?>" onsubmit="return confirm('Rollen jetzt auf dem Discord-Server anlegen?')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <section class="<?= $card ?>">
          <header class="border-b border-cyan-900/50 px-5 py-4">
            <h2 class="text-lg font-semibold">Neue Rollen</h2>
            <p class="text-sm text-zinc-400">Die Rollen entstehen unten in der Rollenliste. Die Mitglieder weist du in Discord zu; in „Discord-Setup“ stehen die neuen Rollen für Sichtbarkeit zur Auswahl.</p>
          </header>
          <div class="space-y-4 p-5">
            <noscript><p class="text-sm text-amber-300">Zum Zusammenstellen der Rollen braucht diese Seite JavaScript.</p></noscript>
            <div class="space-y-4" data-role-list></div>
            <button type="button" class="rounded-md border border-cyan-700/60 px-4 py-2 text-sm text-cyan-300 hover:bg-cyan-500/10" data-add-role>+ Rolle hinzufügen</button>
          </div>
        </section>
        <div class="flex items-center justify-between gap-4">
          <span class="<?= $hint ?>">Administrator, Server verwalten, Rollen verwalten und Webhooks gibt es hier mit Absicht nicht; das stellst du bei Bedarf direkt in Discord ein.</span>
          <button class="shrink-0 rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Anlegen</button>
        </div>
      </form>

      <template id="tpl-role">
        <div class="space-y-3 rounded-lg border border-cyan-800/50 bg-teal-950/30 p-4" data-role>
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium text-cyan-300">Rolle</span>
            <button type="button" class="text-xs text-zinc-400 hover:text-red-400" data-remove-role>entfernen</button>
          </div>
          <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Name</span>
              <input type="text" name="roles[__R__][name]" maxlength="<?= \Hangar\DiscordRoles::MAX_NAME ?>" class="<?= $field ?>" placeholder="z. B. Pilot"></label>
            <div class="space-y-1.5"><span class="block text-sm text-zinc-300">Farbe</span>
              <span class="flex items-center gap-2"><input type="checkbox" class="accent-cyan-400" aria-label="Farbe setzen" data-usecolor>
                <input type="color" name="roles[__R__][color]" value="#3b82f6" disabled class="h-9 w-14 cursor-pointer rounded border border-cyan-800/60 bg-zinc-950/60" data-color></span></div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 pb-2 text-sm text-zinc-300">
              <label class="flex items-center gap-2"><input type="checkbox" name="roles[__R__][hoist]" value="1" class="accent-cyan-400"> in der Liste getrennt zeigen</label>
              <label class="flex items-center gap-2"><input type="checkbox" name="roles[__R__][mentionable]" value="1" class="accent-cyan-400"> erwähnbar</label>
            </div>
          </div>
          <label class="block max-w-xs space-y-1.5"><span class="text-sm text-zinc-300">Rechte aus Vorlage</span>
            <select class="<?= $field ?>" data-preset>
              <option value="">– Vorlage wählen –</option>
              <?php foreach (array_keys($presets) as $title): ?><option value="<?= e($title) ?>"><?= e($title) ?></option><?php endforeach; ?>
            </select></label>
          <details class="rounded-md border border-cyan-900/50">
            <summary class="cursor-pointer px-3 py-2 text-sm text-zinc-300">Rechte einzeln wählen (<span data-permcount>0</span> gewählt)</summary>
            <div class="grid gap-4 border-t border-cyan-900/50 p-3 sm:grid-cols-2 lg:grid-cols-3"><?= $permBoxes('roles[__R__][perms][]') ?></div>
          </details>
        </div>
      </template>
      <?php endif; ?>
    </div>
    <?php elseif ($section === 'vorlagen'): ?>
    <div class="space-y-4">
      <?= $setupTabs('vorlagen') ?>
      <?php if ($preview !== null): ?>
        <?php
          $groups = [];
          foreach ($preview['plan']['items'] as $it) {
              $groups[$it['section']][] = $it;
          }
        ?>
        <section class="<?= $card ?> border-cyan-400/50" id="vorschau">
          <header class="border-b border-cyan-900/50 px-5 py-4">
            <h2 class="text-lg font-semibold">Vorlage „<?= e($preview['template']['name']) ?>“ anwenden</h2>
            <p class="text-sm text-zinc-400">So würde der Server angeglichen. Nimm den Haken weg, was du nicht willst. Löschen ist nie vorgewählt.</p>
          </header>
          <div class="space-y-4 p-5">
            <?php foreach ($preview['plan']['warnings'] as $w): ?>
              <p class="rounded-md border border-amber-500/40 bg-amber-950/40 px-4 py-2 text-sm text-amber-200"><?= e($w) ?></p>
            <?php endforeach; ?>
            <?php if ($groups === []): ?>
              <p class="text-sm text-cyan-300">Der Server entspricht schon der Vorlage. Es gibt nichts anzugleichen.</p>
              <a href="<?= e($base . '?m=vorlagen') ?>" class="inline-block text-sm text-zinc-400 hover:text-cyan-300">Zurück zur Liste</a>
            <?php else: ?>
              <form method="post" action="<?= e($base . '/template/apply') ?>" class="space-y-4" onsubmit="return confirm('Die gewählten Änderungen jetzt auf dem Discord-Server durchführen? Gelöschtes lässt sich nicht zurückholen.')">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= e($preview['template']['id']) ?>">
                <?php foreach ($groups as $title => $items): ?>
                  <fieldset class="rounded-md border border-cyan-900/50">
                    <legend class="ml-3 px-2 text-sm font-medium text-cyan-300"><?= e($title) ?> (<?= count($items) ?>)</legend>
                    <div class="flex gap-3 px-4 pt-1 text-xs text-zinc-500">
                      <button type="button" class="hover:text-cyan-300" data-check-all="1">alle</button>
                      <button type="button" class="hover:text-cyan-300" data-check-all="0">keine</button>
                    </div>
                    <ul class="space-y-1 p-4 pt-2 text-sm">
                      <?php foreach ($items as $it): ?>
                        <li><label class="flex items-start gap-2 <?= $it['locked'] !== null ? 'cursor-not-allowed text-zinc-600' : 'text-zinc-300' ?>">
                          <input type="checkbox" name="do[]" value="<?= e($it['key']) ?>" class="mt-1 accent-cyan-400"<?= $it['default'] ? ' checked' : '' ?><?= $it['locked'] !== null ? ' disabled' : '' ?>>
                          <span><?= e($it['label']) ?><?= $it['locked'] !== null ? ' <span class="text-xs text-zinc-500">(' . e($it['locked']) . ')</span>' : '' ?></span></label></li>
                      <?php endforeach; ?>
                    </ul>
                  </fieldset>
                <?php endforeach; ?>
                <p class="<?= $hint ?>">Die Reihenfolge der Kanäle wird nicht angeglichen, neue Einträge kommen ans Ende. Rechte einzelner Mitglieder bleiben unberührt. Bei vielen neuen Kanälen bremst Discord, dann dauert es etwas.</p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Auswahl durchführen</button>
                  <a href="<?= e($base . '?m=vorlagen') ?>" class="text-sm text-zinc-400 hover:text-cyan-300">Abbrechen</a>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold">Vorlagen</h2>
          <p class="text-sm text-zinc-400"><?= e($descriptions['vorlagen']) ?></p>
        </header>
        <ul class="divide-y divide-cyan-900/40">
          <?php foreach ($templates as $t): ?>
            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
              <div class="min-w-0 max-w-2xl">
                <div class="font-medium"><?= e($t['name']) ?><?= $t['builtin'] ? ' <span class="ml-1 rounded-full border border-cyan-700/50 px-2 text-xs font-normal text-cyan-300">mitgeliefert</span>' : '' ?></div>
                <?php if ($t['description'] !== ''): ?><p class="text-sm text-zinc-400"><?= e($t['description']) ?></p><?php endif; ?>
                <p class="<?= $hint ?>"><?= (int) $t['counts']['roles'] ?> Rollen · <?= (int) $t['counts']['categories'] ?> Kategorien · <?= (int) $t['counts']['channels'] ?> Kanäle</p>
              </div>
              <div class="flex flex-wrap items-center gap-3 text-sm">
                <a href="<?= e($base . '?m=vorlagen&t=' . rawurlencode($t['id']) . '#vorschau') ?>" class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-4 py-1.5 hover:bg-cyan-500/30">Anwenden …</a>
                <a href="<?= e($base . '/template/export?id=' . rawurlencode($t['id'])) ?>" class="text-zinc-400 hover:text-cyan-300">Herunterladen</a>
                <?php if (!$t['builtin']): ?>
                  <form method="post" action="<?= e($base . '/template/delete') ?>" onsubmit="return confirm('Die Vorlage &quot;<?= e(addslashes($t['name'])) ?>&quot; löschen? Der Server bleibt, wie er ist.')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <button class="text-red-400 hover:text-red-300">Löschen</button>
                  </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold">Aktuellen Server als Vorlage speichern</h2>
          <p class="text-sm text-zinc-400">Hält Rollen (mit Farbe und Rechten), Kategorien und Kanäle (Art, Thema, Sichtbarkeit, „nur lesen“) fest. Rechte einzelner Mitglieder, Bühnen- und Forenkanäle und die Reihenfolge werden nicht gespeichert.</p>
        </header>
        <form method="post" action="<?= e($base . '/template/save') ?>" class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] sm:items-end" onsubmit="return confirm('Den aktuellen Aufbau des Servers als Vorlage speichern?')">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Name</span>
            <input type="text" name="name" maxlength="100" required class="<?= $field ?>" placeholder="z. B. Unser Server"></label>
          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Beschreibung (optional)</span>
            <input type="text" name="description" maxlength="300" class="<?= $field ?>"></label>
          <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Speichern</button>
        </form>
      </section>

      <details class="<?= $card ?>">
        <summary class="cursor-pointer px-5 py-4 text-sm text-zinc-300">Vorlage importieren (JSON einfügen)</summary>
        <form method="post" action="<?= e($base . '/template/import') ?>" class="space-y-3 border-t border-cyan-900/50 p-5">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Name</span>
              <input type="text" name="name" maxlength="100" required class="<?= $field ?>"></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Beschreibung (optional)</span>
              <input type="text" name="description" maxlength="300" class="<?= $field ?>"></label>
          </div>
          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Vorlage (JSON, wie von „Herunterladen“)</span>
            <textarea name="json" rows="6" required class="<?= $field ?> font-mono text-xs"></textarea></label>
          <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Importieren</button>
        </form>
      </details>
    </div>
    <?php elseif ($section === 'setup'): ?>
    <div class="space-y-4">
      <?= $setupTabs('setup') ?>
      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold"><?= e(BotSettings::SECTIONS['setup']) ?></h2>
          <p class="text-sm text-zinc-400"><?= e($descriptions['setup']) ?></p>
        </header>
        <div class="space-y-4 p-5">
          <?php if ($structure === null): ?>
            <p class="<?= $hint ?>">Der Aufbau des Servers konnte nicht geladen werden, deshalb ist das Anlegen gesperrt.</p>
          <?php else: ?>
            <?php
              // Ein Eintrag des Servers (Kategorie oder Kanal) zum Aufklappen: Name, Thema, Kategorie, Rechte ändern oder löschen.
              $editor = static function (array $item, bool $isCategory, ?array $category = null) use ($csrf, $base, $field, $hint, $typeIcon, $structure, $usedChannels, $chips, $roles, $guildId): string {
                  $id = (string) $item['id'];
                  $cur = \Hangar\DiscordSetup::currentAccess($item, $isCategory, $category, $guildId);
                  $type = $isCategory ? 4 : (int) $item['type'];
                  $used = $usedChannels[$id] ?? null;
                  $confirm = $isCategory
                      ? 'Kategorie „' . $item['name'] . '“ löschen? Die ' . count($item['channels']) . ' Kanäle darin bleiben bestehen und liegen danach ohne Kategorie ganz oben.'
                      : 'Kanal „' . $item['name'] . '“ löschen? Alle Nachrichten darin gehen verloren.' . ($used !== null ? ' Der Bot nutzt ihn für: ' . $used . '.' : '');
                  ob_start(); ?>
                  <details class="rounded-md border border-transparent open:border-cyan-900/50">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 rounded px-2 py-1 text-sm hover:bg-teal-900/30">
                      <span class="w-5 text-center text-zinc-500"><?= $isCategory ? '▾' : e($typeIcon[$type] ?? '#') ?></span>
                      <span class="<?= $isCategory ? 'font-medium text-cyan-300' : 'text-zinc-300' ?>"><?= e($item['name']) ?></span>
                      <?php if ($used !== null): ?><span class="rounded-full border border-cyan-700/50 px-2 text-xs text-cyan-300" title="Der Bot nutzt diesen Kanal">Bot: <?= e($used) ?></span><?php endif; ?>
                    </summary>
                    <form method="post" action="<?= e($base . '/channel/update') ?>" class="space-y-3 border-t border-cyan-900/50 p-3">
                      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= e($id) ?>">
                      <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Name</span>
                          <input type="text" name="name" value="<?= e($item['name']) ?>" maxlength="<?= \Hangar\DiscordSetup::MAX_NAME ?>" class="<?= $field ?>"></label>
                        <?php if (!$isCategory && in_array($type, [0, 5, 15], true)): ?>
                          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Thema</span>
                            <input type="text" name="topic" value="<?= e($item['topic'] ?? '') ?>" maxlength="<?= \Hangar\DiscordSetup::MAX_TOPIC ?>" class="<?= $field ?>"></label>
                        <?php endif; ?>
                        <?php if (!$isCategory): ?>
                          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Kategorie</span>
                            <select name="parent" class="<?= $field ?>">
                              <option value="-"<?= ($item['parent'] ?? null) === null ? ' selected' : '' ?>>Ohne Kategorie</option>
                              <?php foreach ($structure['categories'] as $cat): ?><option value="<?= e($cat['id']) ?>"<?= (string) ($item['parent'] ?? '') === (string) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
                            </select></label>
                        <?php endif; ?>
                        <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Sichtbar für</span>
                          <select name="access" class="<?= $field ?>" data-edit-access>
                            <?php if (!$isCategory): ?><option value="inherit"<?= $cur['access'] === 'inherit' ? ' selected' : '' ?>>wie die Kategorie</option><?php endif; ?>
                            <option value="all"<?= $cur['access'] === 'all' ? ' selected' : '' ?>>alle</option>
                            <option value="roles"<?= $cur['access'] === 'roles' ? ' selected' : '' ?>>nur bestimmte Rollen</option>
                          </select></label>
                        <?php if (!$isCategory && $type !== 2): ?>
                          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Nur lesen</span>
                            <select name="readonly" class="<?= $field ?>">
                              <option value="1"<?= $cur['readonly'] ? ' selected' : '' ?>>ja (nur Bot und Admins schreiben)</option>
                              <option value="0"<?= $cur['readonly'] ? '' : ' selected' ?>>nein</option>
                            </select></label>
                        <?php endif; ?>
                      </div>
                      <div<?= $cur['access'] === 'roles' ? '' : ' hidden' ?> data-edit-roles><?= $roles === [] ? '<p class="' . $hint . '">Keine Rollen geladen.</p>' : $chips('roles', $roles, array_values(array_intersect($cur['roles'], array_column($roles, 'id'))), '@') ?></div>
                      <p class="<?= $hint ?>"><a href="<?= e($base . '?m=rechte&c=' . rawurlencode($id)) ?>" class="text-cyan-300 hover:underline">Rechte einzelner Rollen …</a> (z. B. wer hier schreiben oder sprechen darf)</p>
                      <p class="<?= $hint ?>">Die Felder zeigen den aktuellen Stand. Wer „Sichtbar für“ oder „Nur lesen“ ändert, ersetzt die bisherigen Rechte <?= $isCategory ? 'dieser Kategorie' : 'dieses Kanals' ?>; bleiben beide, wie sie sind, ändern sich die Rechte nicht.</p>
                      <div class="flex flex-wrap items-center justify-between gap-3">
                        <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-4 py-1.5 text-sm hover:bg-cyan-500/30">Speichern</button>
                        <button type="submit" form="del-<?= e($id) ?>" class="text-sm text-red-400 hover:text-red-300"><?= $isCategory ? 'Kategorie' : 'Kanal' ?> löschen</button>
                      </div>
                    </form>
                    <form id="del-<?= e($id) ?>" method="post" action="<?= e($base . '/channel/delete') ?>"
                          onsubmit="return confirm('<?= e(addslashes($confirm . ' Das lässt sich nicht rückgängig machen.')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= e($id) ?>">
                    </form>
                  </details>
                  <?php return (string) ob_get_clean();
              };
            ?>
            <p class="<?= $hint ?>">Auf einen Eintrag klicken, um ihn zu ändern oder zu löschen. Vorhandenes wird nur geändert, wenn du es hier ausdrücklich speicherst.</p>
            <div class="space-y-3">
              <?php if ($structure['loose'] !== []): ?>
                <section class="rounded-md border border-cyan-900/50 p-2">
                  <div class="px-2 py-1 text-sm font-medium text-zinc-400">Ohne Kategorie</div>
                  <div class="space-y-0.5 pl-3"><?php foreach ($structure['loose'] as $c): ?><?= $editor($c, false, null) ?><?php endforeach; ?></div>
                </section>
              <?php endif; ?>
              <?php foreach ($structure['categories'] as $cat): ?>
                <section class="rounded-md border border-cyan-900/50 p-2">
                  <?= $editor($cat, true) ?>
                  <div class="space-y-0.5 pl-6">
                    <?php foreach ($cat['channels'] as $c): ?><?= $editor($c, false, $cat) ?><?php endforeach; ?>
                    <?php if ($cat['channels'] === []): ?><div class="px-2 py-1 text-sm text-zinc-600">leer</div><?php endif; ?>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($structure !== null): ?>
      <form method="post" action="<?= e($base . '/setup') ?>" class="space-y-4" data-setup onsubmit="return confirm('Kategorien und Kanäle jetzt auf dem Discord-Server anlegen?')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <section class="<?= $card ?>">
          <header class="border-b border-cyan-900/50 px-5 py-4">
            <h2 class="text-lg font-semibold">Neu anlegen</h2>
            <p class="text-sm text-zinc-400">Jeder Block ist eine Kategorie mit ihren Kanälen. Es wird erst alles geprüft und dann angelegt. Vorhandenes bleibt dabei unverändert.</p>
          </header>
          <div class="space-y-4 p-5">
            <label class="block max-w-xs space-y-1.5"><span class="text-sm text-zinc-300">Zeichen vor jedem Kanalnamen</span>
              <input type="text" name="prefix" value="<?= e(\Hangar\DiscordSetup::DEFAULT_PREFIX) ?>" maxlength="<?= \Hangar\DiscordSetup::MAX_PREFIX ?>" class="<?= $field ?>">
              <span class="block <?= $hint ?>">Wie bei „┋chat“. Leer lassen für keins. Text- und Ankündigungskanäle schreibt Discord klein und mit „-“ statt Leerzeichen.</span></label>
            <noscript><p class="text-sm text-amber-300">Zum Zusammenstellen der Blöcke braucht diese Seite JavaScript.</p></noscript>
            <div class="space-y-4" data-blocks></div>
            <button type="button" class="rounded-md border border-cyan-700/60 px-4 py-2 text-sm text-cyan-300 hover:bg-cyan-500/10" data-add-block>+ Kategorie hinzufügen</button>
          </div>
        </section>
        <div class="flex items-center justify-between gap-4">
          <span class="<?= $hint ?>">Rechte: „Sichtbar für“ begrenzt, wer die Kategorie oder den Kanal sieht (alle anderen sehen ihn nicht). „Nur lesen“ lässt nur den Bot und Admins schreiben. Rollen und weitere Rechte folgen später.</span>
          <button class="shrink-0 rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Anlegen</button>
        </div>
      </form>

      <template id="tpl-block">
        <div class="space-y-3 rounded-lg border border-cyan-800/50 bg-teal-950/30 p-4" data-block>
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium text-cyan-300">Kategorie</span>
            <button type="button" class="text-xs text-zinc-400 hover:text-red-400" data-remove-block>Block entfernen</button>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Kategorie</span>
              <select name="blocks[__B__][target]" class="<?= $field ?>" data-target>
                <option value="">➕ Neue Kategorie anlegen</option>
                <option value="-">Ohne Kategorie (Kanäle ganz oben)</option>
                <?php if ($structure['categories'] !== []): ?><optgroup label="Vorhandene Kategorie ergänzen">
                  <?php foreach ($structure['categories'] as $cat): ?><option value="<?= e($cat['id']) ?>"><?= e($cat['name']) ?></option><?php endforeach; ?>
                </optgroup><?php endif; ?>
              </select></label>
            <label class="block space-y-1.5" data-newcat><span class="text-sm text-zinc-300">Name der neuen Kategorie</span>
              <input type="text" name="blocks[__B__][name]" maxlength="<?= \Hangar\DiscordSetup::MAX_NAME ?>" class="<?= $field ?>" placeholder="z. B. Events"></label>
          </div>
          <div class="space-y-2" data-newcat>
            <label class="block max-w-xs space-y-1.5"><span class="text-sm text-zinc-300">Sichtbar für</span>
              <select name="blocks[__B__][access]" class="<?= $field ?>" data-access>
                <option value="all">Alle</option>
                <option value="roles">Nur bestimmte Rollen</option>
              </select></label>
            <div hidden data-roles><?= $roleBoxes('blocks[__B__][roles]') ?></div>
          </div>
          <div class="space-y-2" data-channels></div>
          <button type="button" class="rounded-md border border-zinc-700 px-3 py-1.5 text-sm text-zinc-300 hover:border-cyan-600" data-add-channel>+ Kanal</button>
        </div>
      </template>

      <template id="tpl-channel">
        <div class="grid gap-2 rounded-md border border-cyan-900/50 p-3 sm:grid-cols-[150px_minmax(0,1fr)_minmax(0,1fr)]" data-channel>
          <select name="blocks[__B__][channels][__C__][type]" class="<?= $field ?>" data-type aria-label="Kanalart">
            <option value="text"># Text</option>
            <option value="voice">🔊 Sprache</option>
            <option value="announce">📢 Ankündigung</option>
          </select>
          <input type="text" name="blocks[__B__][channels][__C__][name]" maxlength="<?= \Hangar\DiscordSetup::MAX_NAME ?>" class="<?= $field ?>" placeholder="Kanalname" aria-label="Kanalname">
          <input type="text" name="blocks[__B__][channels][__C__][topic]" maxlength="<?= \Hangar\DiscordSetup::MAX_TOPIC ?>" class="<?= $field ?>" placeholder="Thema (optional)" aria-label="Thema" data-topic>
          <div class="flex flex-wrap items-center gap-x-5 gap-y-2 sm:col-span-3">
            <label class="flex items-center gap-2 text-sm text-zinc-300">Sichtbar für
              <select name="blocks[__B__][channels][__C__][access]" class="rounded-md border border-cyan-800/60 bg-zinc-950/60 px-2 py-1 text-sm" data-access>
                <option value="inherit">wie die Kategorie</option>
                <option value="all">alle</option>
                <option value="roles">nur bestimmte Rollen</option>
              </select></label>
            <label class="flex items-center gap-2 text-sm text-zinc-300" data-readonly>
              <input type="checkbox" name="blocks[__B__][channels][__C__][readonly]" value="1" class="accent-cyan-400"> nur lesen</label>
            <button type="button" class="ml-auto text-xs text-zinc-400 hover:text-red-400" data-remove-channel>entfernen</button>
          </div>
          <div hidden class="sm:col-span-3" data-roles><?= $roleBoxes('blocks[__B__][channels][__C__][roles]') ?></div>
        </div>
      </template>
      <?php endif; ?>
    </div>
    <?php elseif ($section === 'regeln'): ?>
    <div class="space-y-4">
      <?= $welcomeTabs('regeln') ?>
      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold">Regeln veröffentlichen</h2>
          <p class="text-sm text-zinc-400"><?= e($descriptions['regeln']) ?></p>
        </header>
        <?php if ($channels === [] || $roles === []): ?>
          <p class="p-5 text-sm text-zinc-400">Kanäle und Rollen des Servers konnten nicht geladen werden, deshalb ist das Veröffentlichen gesperrt.</p>
        <?php else: ?>
        <form method="post" action="<?= e($base . '/rules/publish') ?>" class="space-y-4 p-5" onsubmit="return confirm('Die Regeln jetzt im gewählten Kanal veröffentlichen?')">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Kanal</span>
              <?= $channelSelect('channel', $set(BotSettings::RULES_CHANNEL), '– Kanal wählen –') ?>
              <span class="block <?= $hint ?>">Vorgewählt ist der Regelkanal aus den Einstellungen. Der Bot braucht dort „Kanal ansehen“, „Nachrichten senden“ und „Links einbetten“.</span></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Rolle für „Bestätigen“</span>
              <?= $roleSelect('role', $set(BotSettings::VISITOR_ROLE)) ?>
              <span class="block <?= $hint ?>">Wer „Bestätigen“ drückt, bekommt diese Rolle. Vorgewählt ist die Besucher-Rolle. Die Rolle des Bots muss im Server darüber stehen.</span></label>
          </div>
          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Überschrift</span>
            <input type="text" name="title" value="<?= e(\Hangar\DiscordRules::DEFAULT_TITLE) ?>" maxlength="<?= \Hangar\DiscordRules::MAX_TITLE ?>" class="<?= $field ?>"></label>
          <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Text der Regeln</span>
            <textarea name="text" rows="10" required maxlength="<?= \Hangar\DiscordRules::MAX_TEXT ?>" class="<?= $field ?>"><?= e(\Hangar\DiscordRules::DEFAULT_TEXT) ?></textarea>
            <span class="block <?= $hint ?>">Discord-Formatierung wie **fett** geht. Höchstens <?= \Hangar\DiscordRules::MAX_TEXT ?> Zeichen. Der Standardtext ist derselbe wie bei /discord-setup, ändere ihn nach Bedarf.</span></label>
          <p class="<?= $hint ?>">Jedes Veröffentlichen schreibt eine neue Nachricht. Eine ältere Regelnachricht lässt du bei Bedarf in Discord von Hand löschen; ihr Knopf bleibt sonst weiter wirksam.</p>
          <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Veröffentlichen</button>
        </form>
        <?php endif; ?>
      </section>
    </div>
    <?php elseif ($section === 'faelle'): ?>
    <div class="space-y-4">
      <?= $modTabs('faelle') ?>
      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold">Maßnahme</h2>
          <p class="text-sm text-zinc-400">Wirkt sofort auf dem Discord-Server, wird als Fall gespeichert und, wenn ein Protokollkanal eingestellt ist, dorthin geschrieben. Die Person bekommt bei Verwarnung, Timeout, Kick und Bann eine Direktnachricht, wenn sie das zulässt.</p>
        </header>
        <form method="post" action="<?= e($base . '/mod/action') ?>" class="space-y-4 p-5" data-mod-form>
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Person</span>
              <input type="text" name="person" required maxlength="100" class="<?= $field ?>" placeholder="Name oder Discord-ID">
              <span class="block <?= $hint ?>">Der Name muss genau eine Person treffen, sonst nimm die Discord-ID. Zum Aufheben eines Banns ist die ID nötig.</span></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Maßnahme</span>
              <select name="do" class="<?= $field ?>" data-mod-do>
                <?php foreach (\Hangar\Moderation::DO as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select></label>
          </div>
          <div class="grid gap-4 sm:grid-cols-3">
            <label class="block space-y-1.5 sm:col-span-3"><span class="text-sm text-zinc-300">Grund <span class="text-zinc-500">(bei Verwarnung nötig; die Person sieht ihn)</span></span>
              <input type="text" name="reason" maxlength="500" class="<?= $field ?>"></label>
            <label class="block space-y-1.5" data-for="timeout"><span class="text-sm text-zinc-300">Dauer (Timeout)</span>
              <input type="text" name="duration" maxlength="10" class="<?= $field ?>" placeholder="z. B. 30m, 2h, 1d, 1w">
              <span class="block <?= $hint ?>">Höchstens 28 Tage.</span></label>
            <label class="block space-y-1.5" data-for="ban"><span class="text-sm text-zinc-300">Nachrichten löschen (Bann)</span>
              <select name="days" class="<?= $field ?>">
                <?php foreach ([0 => 'keine', 1 => 'letzter Tag', 2 => 'letzte 2 Tage', 3 => 'letzte 3 Tage', 7 => 'letzte 7 Tage'] as $d => $label): ?><option value="<?= $d ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select></label>
          </div>
          <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Ausführen</button>
        </form>
      </section>

      <section class="<?= $card ?>">
        <header class="flex flex-wrap items-end justify-between gap-3 border-b border-cyan-900/50 px-5 py-4">
          <div>
            <h2 class="text-lg font-semibold">Fälle <span class="text-sm font-normal text-zinc-500">(<?= (int) $modCases['total'] ?>)</span></h2>
            <p class="text-sm text-zinc-400">Alle Maßnahmen, auch die aus Discord (/mod) und von der Automod.</p>
          </div>
          <form method="get" action="<?= e($base) ?>" class="flex flex-wrap items-center gap-2 text-sm">
            <input type="hidden" name="m" value="faelle">
            <input type="text" name="q" value="<?= e($modQuery) ?>" maxlength="100" placeholder="Name oder ID" class="<?= $field ?> w-44" aria-label="Person suchen">
            <select name="a" class="<?= $field ?> w-auto" aria-label="Art">
              <option value="">alle Arten</option>
              <?php foreach (\Hangar\Moderation::ACTIONS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $modAction ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <button class="rounded-md border border-zinc-600 px-3 py-2 hover:border-cyan-500">Filtern</button>
          </form>
        </header>
        <?php if ($modCases['rows'] === []): ?>
          <p class="px-5 py-4 text-sm text-zinc-400">Keine Fälle gefunden.</p>
        <?php else: ?>
        <ul class="divide-y divide-cyan-900/40 text-sm">
          <?php foreach ($modCases['rows'] as $c): ?>
            <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 py-3">
              <div class="min-w-0 max-w-2xl space-y-0.5">
                <div><span class="font-mono text-zinc-500">#<?= (int) $c['id'] ?></span>
                  <span class="<?= $c['removed_at'] !== null ? 'text-zinc-500 line-through' : 'text-amber-400' ?>"><?= e(\Hangar\Moderation::ACTIONS[$c['action']] ?? $c['action']) ?></span>
                  · <?= e($c['target_name']) ?> <span class="font-mono text-xs text-zinc-500"><?= e($c['target_id']) ?></span></div>
                <?php if (!empty($c['detail'])): ?><div class="text-zinc-400"><?= e($c['detail']) ?></div><?php endif; ?>
                <?php if (!empty($c['reason'])): ?><div class="text-zinc-300">Grund: <?= e($c['reason']) ?></div><?php endif; ?>
                <div class="<?= $hint ?>"><?= e(dt(\Hangar\Time::parse((string) $c['created_at']))) ?>
                  <?php if (!empty($c['moderator_id'])): ?> · von <?= e($c['moderator_name'] ?: $c['moderator_id']) ?><?php endif; ?>
                  <?= $c['removed_at'] !== null ? ' · zurückgenommen' : '' ?></div>
              </div>
              <?php if ($c['action'] === 'WARN' && $c['removed_at'] === null): ?>
                <form method="post" action="<?= e($base . '/mod/unwarn') ?>" onsubmit="return confirm('Verwarnung #<?= (int) $c['id'] ?> zurücknehmen?')">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="text-zinc-400 hover:text-red-300">Zurücknehmen</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php
          $pages = max(1, (int) ceil($modCases['total'] / \Hangar\Moderation::PER_PAGE));
          $link = static fn (int $p) => e($base . '?' . http_build_query(['m' => 'faelle', 'q' => $modQuery, 'a' => $modAction, 'p' => $p]));
        ?>
        <?php if ($pages > 1): ?>
          <nav class="flex items-center justify-between border-t border-cyan-900/50 px-5 py-3 text-sm text-zinc-400" aria-label="Seiten">
            <span><?= $modPage > 1 ? '<a class="hover:text-cyan-300" href="' . $link($modPage - 1) . '">← Neuere</a>' : '' ?></span>
            <span>Seite <?= $modPage ?> von <?= $pages ?></span>
            <span><?= $modPage < $pages ? '<a class="hover:text-cyan-300" href="' . $link($modPage + 1) . '">Ältere →</a>' : '' ?></span>
          </nav>
        <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>
    <?php elseif ($section === 'rechte'): ?>
    <div class="space-y-4">
      <?php if ($rights === null): ?>
        <section class="<?= $card ?>">
          <div class="space-y-2 p-5 text-sm text-zinc-300">
            <p>Diesen Kanal gibt es nicht (mehr) oder der Aufbau des Servers konnte nicht geladen werden.</p>
            <a href="<?= e($base . '?m=setup') ?>" class="text-cyan-300 hover:underline">Zurück zum Discord-Setup</a>
          </div>
        </section>
      <?php else: ?>
        <?php
          $item = $rights['item'];
          $type = $rights['isCategory'] ? 4 : (int) $item['type'];
          $free = \Hangar\DiscordRights::grantable($type, $rights['bot']);
          $guildRole = $rights['guildId'];
          // @everyone zuerst, danach die Rollen von oben nach unten; Rollen von Bots und Integrationen lassen sich nicht ändern
          $list = array_values(array_filter($rights['roles'], static fn ($r) => !$r['managed'] && $r['id'] === $guildRole));
          foreach ($rights['roles'] as $r) {
              if (!$r['managed'] && $r['id'] !== $guildRole) {
                  $list[] = $r;
              }
          }
          $selected = $guildRole;
          foreach ($list as $r) {
              if ($r['id'] === $rights['selected']) {
                  $selected = $r['id'];
              }
          }
          if ($rights['selected'] === '') {
              foreach ($list as $r) {
                  if (\Hangar\DiscordRights::current($item['overwrites'] ?? [], $r['id'])['exists']) {
                      $selected = $r['id'];
                      break;
                  }
              }
          }
          $cur = \Hangar\DiscordRights::current($item['overwrites'] ?? [], $selected);
          $selName = '@everyone';
          foreach ($list as $r) {
              if ($r['id'] === $selected) {
                  $selName = $r['id'] === $guildRole ? '@everyone' : '@' . $r['name'];
              }
          }
          $kindName = $rights['isCategory'] ? 'Kategorie' : 'Kanal';
        ?>
        <section class="<?= $card ?>">
          <header class="border-b border-cyan-900/50 px-5 py-4">
            <a href="<?= e($base . '?m=setup') ?>" class="text-sm text-zinc-400 hover:text-cyan-300">← Zurück zum Discord-Setup</a>
            <h2 class="mt-1 text-lg font-semibold">Rechte einzelner Rollen: <?= e($kindName) ?> „<?= e($item['name']) ?>“</h2>
            <p class="text-sm text-zinc-400">Wähle links eine Rolle und stelle rechts ein, was sie in diesem <?= e($kindName) ?> darf. ✓ erlaubt es, „nicht festgelegt“ lässt es bei dem, was die Rolle sonst darf, ✗ verbietet es. Ein ✓ einer Rolle gilt vor einem ✗ einer anderen; nur @everyone wird zuerst angewendet. Änderungen wirken sofort.</p>
          </header>
          <div class="grid gap-0 md:grid-cols-[230px_minmax(0,1fr)]">
            <nav class="border-b border-cyan-900/50 p-3 md:border-b-0 md:border-r" aria-label="Rollen">
              <ul class="space-y-1">
                <?php foreach ($list as $r): ?>
                  <?php $has = \Hangar\DiscordRights::current($item['overwrites'] ?? [], $r['id'])['exists']; ?>
                  <li><a href="<?= e($base . '?m=rechte&c=' . rawurlencode((string) $item['id']) . '&r=' . rawurlencode($r['id'])) ?>"
                         class="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-sm <?= $r['id'] === $selected ? 'border-cyan-400/50 bg-teal-900/50 text-cyan-300' : 'border-transparent text-zinc-300 hover:bg-teal-900/30' ?>">
                    <span><?= $r['id'] === $guildRole ? '@everyone' : '@' . e($r['name']) ?></span><?= $has ? $dot(true) : '' ?></a></li>
                <?php endforeach; ?>
              </ul>
              <p class="mt-3 text-xs text-zinc-500">Ein Punkt heißt: Für diese Rolle gibt es hier schon Einstellungen.</p>
            </nav>
            <form method="post" action="<?= e($base . '/channel/rights') ?>" class="space-y-4 p-5">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= e((string) $item['id']) ?>">
              <input type="hidden" name="role" value="<?= e($selected) ?>">
              <h3 class="font-medium text-cyan-300"><?= e($selName) ?></h3>
              <?php foreach (\Hangar\DiscordRights::groupsFor($type) as $group => $perms): ?>
                <fieldset class="space-y-1.5">
                  <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500"><?= e($group) ?></legend>
                  <?php foreach ($perms as $bit => $title): ?>
                    <?php
                      $ok = ($free & (1 << $bit)) !== 0;
                      $state = ($cur['allow'] & (1 << $bit)) !== 0 ? 'allow' : (($cur['deny'] & (1 << $bit)) !== 0 ? 'deny' : '');
                    ?>
                    <label class="flex items-center justify-between gap-3 text-sm <?= $ok ? 'text-zinc-300' : 'text-zinc-600' ?>"<?= $ok ? '' : ' title="Der Bot hat dieses Recht selbst nicht und kann es nicht ändern"' ?>>
                      <span><?= e($title) ?></span>
                      <select name="perm[<?= (int) $bit ?>]" class="<?= $field ?> w-auto min-w-[11rem]"<?= $ok ? '' : ' disabled' ?>>
                        <option value=""<?= $state === '' ? ' selected' : '' ?>>– nicht festgelegt</option>
                        <option value="allow"<?= $state === 'allow' ? ' selected' : '' ?>>✓ erlauben</option>
                        <option value="deny"<?= $state === 'deny' ? ' selected' : '' ?>>✗ verbieten</option>
                      </select>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endforeach; ?>
              <p class="<?= $hint ?>">Wer bei @everyone „Kanäle ansehen“ verbietet, macht den <?= e($kindName) ?> für alle unsichtbar, die ihn nicht über eine Rolle wieder erlaubt bekommen. Rechte einzelner Mitglieder und Rechte, die der Bot selbst nicht hat (ausgegraut), bleiben unverändert. Administrator, Rollen, Berechtigungen und Webhooks verwalten gibt es hier mit Absicht nicht.</p>
              <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Speichern</button>
            </form>
          </div>
        </section>
      <?php endif; ?>
    </div>
    <?php elseif ($section === 'creator'): ?>
    <div class="space-y-4">
      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold"><?= e(BotSettings::SECTIONS['creator']) ?> <span class="text-sm font-normal text-zinc-500">(<?= count($creators) ?>/<?= \Hangar\Creators::MAX ?>)</span></h2>
          <p class="text-sm text-zinc-400"><?= e($descriptions['creator']) ?></p>
        </header>
        <?php if ($creators === []): ?>
          <p class="px-5 py-4 text-sm text-zinc-400">Noch keine Creator. Unten einen YouTube-Kanal hinzufügen.</p>
        <?php else: ?>
        <ul class="divide-y divide-cyan-900/40">
          <?php foreach ($creators as $c): ?>
            <?php
              $chName = current(array_column(array_filter($channels, static fn ($ch) => $ch['id'] === $c['discord_channel_id']), 'name'));
              $roleName = $c['mention_role_id'] ? current(array_column(array_filter($roles, static fn ($r) => $r['id'] === $c['mention_role_id']), 'name')) : null;
            ?>
            <li class="px-5 py-4">
              <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span><a href="<?= $c['platform'] === 'twitch' ? 'https://www.twitch.tv/' . e($c['channel_id']) : 'https://www.youtube.com/channel/' . e($c['channel_id']) ?>" target="_blank" rel="noopener" class="font-medium text-cyan-300 hover:underline"><?= e($c['name']) ?></a>
                  <span class="ml-1 rounded-full border border-cyan-700/50 px-2 text-xs text-cyan-300"><?= e(\Hangar\Creators::PLATFORMS[$c['platform']] ?? $c['platform']) ?></span></span>
                <span class="text-xs <?= !empty($c['last_error']) ? 'text-amber-300' : 'text-zinc-500' ?>"><?php
                  if (!empty($c['last_error'])) { echo '⚠ ' . e($c['last_error']); }
                  elseif ($c['platform'] === 'twitch' && !(int) $c['resolved']) { echo 'wird vom Bot bei Twitch geprüft'; }
                  elseif (!empty($c['checked_at'])) { echo 'zuletzt geprüft ' . e(dt(\Hangar\Time::parse((string) $c['checked_at']))); }
                  else { echo 'noch nicht geprüft'; }
                ?></span>
              </div>
              <p class="mt-1 text-sm text-zinc-400">→ <?= $chName ? '#' . e($chName) : 'Kanal nicht mehr verfügbar' ?><?= $c['mention_role_id'] ? ', erwähnt @' . e($roleName ?: 'Rolle nicht mehr verfügbar') : '' ?><?= (int) $c['skip_shorts'] === 1 ? ', ohne Shorts' : '' ?><?= $c['platform'] === 'twitch' ? ', meldet Live-Streams' : '' ?></p>
              <details class="mt-2 rounded-md border border-cyan-900/50">
                <summary class="cursor-pointer px-3 py-1.5 text-sm text-cyan-300">Bearbeiten</summary>
                <form method="post" action="<?= e($base . '/creator/update') ?>" class="space-y-3 border-t border-cyan-900/50 p-3">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                  <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Discord-Kanal</span><?= $channelSelect('channel', (string) $c['discord_channel_id']) ?></label>
                    <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Rolle erwähnen (freiwillig)</span><?= $roleSelect('role', (string) ($c['mention_role_id'] ?? '')) ?></label>
                  </div>
                  <?php if ($c['platform'] !== 'twitch'): ?>
                    <label class="flex items-center gap-2 text-sm text-zinc-300"><input type="checkbox" name="skipShorts" value="1" class="accent-cyan-400"<?= (int) $c['skip_shorts'] === 1 ? ' checked' : '' ?>> Shorts nicht posten</label>
                  <?php endif; ?>
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-4 py-1.5 text-sm hover:bg-cyan-500/30">Speichern</button>
                    <button type="submit" form="del-creator-<?= e($c['id']) ?>" class="text-sm text-red-400 hover:text-red-300">Creator entfernen</button>
                  </div>
                </form>
                <form id="del-creator-<?= e($c['id']) ?>" method="post" action="<?= e($base . '/creator/delete') ?>"
                      onsubmit="return confirm('Den Creator &quot;<?= e(addslashes($c['name'])) ?>&quot; entfernen? Neue Videos werden dann nicht mehr gepostet.')">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                </form>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <section class="<?= $card ?>">
        <header class="border-b border-cyan-900/50 px-5 py-4">
          <h2 class="text-lg font-semibold">Creator hinzufügen</h2>
          <p class="text-sm text-zinc-400">Gibt es den Kanal schon in der Liste, werden Discord-Kanal, Rolle und Shorts geändert.</p>
          <?php if (!$twitchActive): ?>
            <p class="mt-2 rounded-md border border-amber-500/40 bg-amber-950/40 px-3 py-2 text-sm text-amber-200">Twitch ist im Bot noch nicht eingerichtet: Ohne TWITCH_CLIENT_ID und TWITCH_CLIENT_SECRET in der .env (und einen Neustart des Bots) meldet er keinen Stream. Eintragen kannst du Kanäle schon jetzt.</p>
          <?php endif; ?>
        </header>
        <?php if ($channels === []): ?>
          <p class="p-5 text-sm text-zinc-400">Die Discord-Kanäle konnten nicht geladen werden, deshalb ist das Hinzufügen gesperrt.</p>
        <?php else: ?>
        <form method="post" action="<?= e($base . '/creator/add') ?>" class="space-y-4 p-5">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="grid gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Plattform</span>
              <select name="platform" class="<?= $field ?>"><?php foreach (\Hangar\Creators::PLATFORMS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Kanal</span>
              <input type="text" name="source" required maxlength="200" class="<?= $field ?>" placeholder="YouTube: @Name, Link oder Kanal-ID · Twitch: Kanalname oder Link">
              <span class="block <?= $hint ?>">YouTube wird sofort geprüft, das dauert einen Moment. Twitch prüft der Bot gleich danach.</span></label>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Discord-Kanal für neue Videos und Live-Meldungen</span><?= $channelSelect('channel', '', '– Kanal wählen –') ?>
              <span class="block <?= $hint ?>">Der Bot braucht dort „Kanal ansehen“, „Nachrichten senden“ und „Links einbetten“.</span></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Rolle erwähnen (freiwillig)</span><?= $roleSelect('role', '') ?></label>
          </div>
          <label class="flex items-center gap-2 text-sm text-zinc-300"><input type="checkbox" name="skipShorts" value="1" class="accent-cyan-400"> Shorts nicht posten (nur YouTube)</label>
          <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Hinzufügen</button>
        </form>
        <?php endif; ?>
      </section>
    </div>
    <?php elseif ($section === 'allgemein'): ?>
    <div class="space-y-4">
      <section class="rounded-lg border border-red-500/50 bg-red-950/30">
        <header class="flex items-start gap-3 px-5 py-4">
          <span class="text-2xl leading-none" aria-hidden="true">⚠</span>
          <div class="space-y-2">
            <h2 class="text-lg font-semibold text-red-200">Achtung: Änderungen auf eigene Gefahr</h2>
            <p class="text-sm text-red-100/90">Änderungen werden nach dem Speichern sofort umgesetzt, auf deinem Discord-Server und für alle Mitglieder. Einiges davon lässt sich nicht rückgängig machen.</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-red-100/80">
              <li>Gelöschte Rollen, Kategorien und Kanäle sind weg, mit allen Nachrichten in den Kanälen.</li>
              <li>Kick, Bann und Timeout wirken sofort; die Person bekommt eine Direktnachricht.</li>
              <li>Veröffentlichte Regeln und angewendete Vorlagen ändern den Server auf einen Schlag.</li>
            </ul>
          </div>
        </header>
      </section>
      <section class="<?= $card ?>">
        <div class="space-y-2 p-5 text-sm text-zinc-300">
          <p>Hier gibt es nichts einzustellen. Welcher Kanal und welche Rolle für den Bot gelten, legst du unter
            <a href="<?= e($base . '?m=zuordnung') ?>" class="text-cyan-300 hover:underline">Discord-Setup, Zuordnung</a> fest.
            Die Funktionen (Termine, Willkommen und Regeln, Moderation, Automod) schaltest du in ihren eigenen Bereichen ein und aus.</p>
        </div>
      </section>
    </div>
    <?php else: ?>
    <form method="post" action="<?= e($base . '/save') ?>" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="section" value="<?= e($section) ?>">
      <?= $section === 'zuordnung' ? $setupTabs('zuordnung') : ($section === 'moderation' ? $modTabs('moderation') : ($section === 'willkommen' ? $welcomeTabs('willkommen') : '')) ?>
      <section class="<?= $card ?>">
        <header class="flex items-start justify-between gap-4 border-b border-cyan-900/50 px-5 py-4">
          <div>
            <h2 class="text-lg font-semibold"><?= e(BotSettings::SECTIONS[$section] ?? 'Zuordnung') ?></h2>
            <p class="text-sm text-zinc-400"><?= e($descriptions[$section]) ?></p>
          </div>
          <?php if (isset($status[$section])): ?>
            <span class="flex shrink-0 items-center gap-2 text-xs text-zinc-400"><?= $dot($status[$section]) ?><?= $status[$section] ? 'eingerichtet' : 'aus' ?></span>
          <?php endif; ?>
        </header>

        <div class="space-y-5 p-5">
        <?php if ($section === 'termine'): ?>
          <label class="block max-w-md space-y-1.5"><span class="text-sm text-zinc-300">Ankündigungskanal</span>
            <?= $channelSelect('announceChannel', $set(BotSettings::ANNOUNCE_CHANNEL), '– keiner (Ankündigungen aus) –') ?>
            <span class="block <?= $hint ?>">Hier erscheinen neue Termine mit Schiffen und Plätzen. Ohne Kanal kündigt der Bot nichts an.</span></label>

        <?php elseif ($section === 'willkommen'): ?>
          <?php $text = $set(BotSettings::WELCOME_TEXT) !== '' ? $set(BotSettings::WELCOME_TEXT) : BotSettings::DEFAULT_WELCOME; ?>
          <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
              <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Willkommenskanal</span>
                <?= $channelSelect('welcomeChannel', $set(BotSettings::WELCOME_CHANNEL), '– keiner (Begrüßung aus) –') ?></label>
              <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Regelkanal</span>
                <?= $channelSelect('rulesChannel', $set(BotSettings::RULES_CHANNEL)) ?>
                <span class="block <?= $hint ?>">Für den Platzhalter {regeln}. Die Regelnachricht selbst postest du mit /serverregeln.</span></label>
              <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Begrüßungstext</span>
                <textarea name="welcomeText" rows="4" maxlength="<?= BotSettings::MAX_WELCOME_TEXT ?>" class="<?= $field ?>" data-welcome-text><?= e($text) ?></textarea>
                <span class="block <?= $hint ?>">Platzhalter: {user} {server} {regeln}. **fett** wie in Discord. Höchstens <?= BotSettings::MAX_WELCOME_TEXT ?> Zeichen.</span></label>
            </div>
            <div class="space-y-1.5">
              <span class="text-sm text-zinc-300">Vorschau</span>
              <div class="rounded-md bg-[#313338] p-4 text-sm text-[#dbdee1]">
                <div class="flex gap-3">
                  <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-cyan-700 text-xs font-bold text-white">BOT</div>
                  <div class="min-w-0">
                    <div><span class="font-semibold text-white">Bot</span> <span class="ml-1 rounded bg-[#5865f2] px-1 text-[10px] font-semibold text-white">APP</span> <span class="ml-1 text-xs text-[#949ba4]">Heute</span></div>
                    <p class="mt-1 whitespace-pre-wrap break-words" data-welcome-preview data-server="<?= e($org['name']) ?>"></p>
                  </div>
                </div>
              </div>
            </div>
          </div>

        <?php elseif ($section === 'moderation'): ?>
          <div class="space-y-1.5"><span class="text-sm text-zinc-300">Moderatoren-Rollen</span>
            <?= $roles === [] && $set(BotSettings::MOD_ROLES) === '' ? '<p class="' . $hint . '">Keine Rollen geladen.</p>' : $chips('modRoles', $roles, array_values(array_filter(explode(',', $set(BotSettings::MOD_ROLES)))), '@') ?>
            <span class="block <?= $hint ?>">Anklicken zum Auswählen, höchstens <?= BotSettings::MAX_MOD_ROLES ?>. Admins dürfen immer moderieren.</span></div>
          <label class="block max-w-md space-y-1.5"><span class="text-sm text-zinc-300">Protokollkanal</span>
            <?= $channelSelect('modLogChannel', $set(BotSettings::MOD_LOG_CHANNEL), '– keiner (kein Protokoll) –') ?>
            <span class="block <?= $hint ?>">Fälle, Beitritte und Austritte, mit BOT_MESSAGE_CONTENT auch gelöschte und bearbeitete Nachrichten.</span></label>
          <?php if ($cases !== []): ?>
            <div class="rounded-md border border-cyan-900/50">
              <div class="border-b border-cyan-900/50 px-4 py-2 text-sm text-zinc-400">Letzte Fälle</div>
              <ul class="divide-y divide-cyan-900/40 text-sm">
                <?php foreach ($cases as $c): ?>
                  <li class="flex flex-wrap justify-between gap-2 px-4 py-2">
                    <span><span class="text-amber-400"><?= e($caseLabels[$c['action']] ?? $c['action']) ?></span> · <?= e($c['target_name']) ?><?= !empty($c['reason']) ? ' · <span class="text-zinc-400">' . e(mb_strimwidth((string) $c['reason'], 0, 80, '…')) . '</span>' : '' ?></span>
                    <span class="text-zinc-500"><?= e(dt(\Hangar\Time::parse((string) $c['created_at']))) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

        <?php elseif ($section === 'zuordnung'): ?>
          <?php
            // Aktueller Wert, sonst der Vorschlag nach dem Namen; $from sagt, woher der Wert kommt.
            $pick = static fn (string $name): array => $set($name) !== '' ? [$set($name), 'gesetzt'] : (isset($suggest[$name]) ? [$suggest[$name], 'Vorschlag'] : ['', '']);
            $rows = [
                ['adminChannel', BotSettings::ADMIN_CHANNEL, 'Kanal für Bot-Einstellungen', 'c', 'Nur für Admins sichtbar. Hier legt /discord-setup die Hinweise zu den Bot-Einstellungen ab.'],
                ['announceChannel', BotSettings::ANNOUNCE_CHANNEL, 'Ankündigungskanal', 'c', 'Neue Termine mit Schiffen und Plätzen.'],
                ['welcomeChannel', BotSettings::WELCOME_CHANNEL, 'Willkommenskanal', 'c', 'Hier begrüßt der Bot neue Mitglieder.'],
                ['rulesChannel', BotSettings::RULES_CHANNEL, 'Regelkanal', 'c', 'Für {regeln} im Begrüßungstext.'],
                ['modLogChannel', BotSettings::MOD_LOG_CHANNEL, 'Protokollkanal', 'c', 'Moderations-Fälle, Beitritte und Austritte.'],
                ['visitorRole', BotSettings::VISITOR_ROLE, 'Besucher-Rolle', 'r', 'Bekommt man mit „Bestätigen“ unter den Regeln.'],
            ];
          ?>
          <p class="<?= $hint ?>">Leere Felder bleiben, wie sie sind. Gespeichert wird erst mit „Speichern“. Vorschläge sind vorausgewählt, prüfe sie vorher.</p>
          <div class="grid gap-5 sm:grid-cols-2">
            <?php foreach ($rows as [$field_, $setting, $label, $kind, $help]): ?>
              <?php [$value, $from] = $pick($setting); ?>
              <label class="block space-y-1.5"><span class="text-sm text-zinc-300"><?= e($label) ?>
                  <?php if ($from === 'Vorschlag'): ?><span class="ml-1 rounded-full border border-cyan-700/50 px-2 text-xs text-cyan-300">Vorschlag</span><?php endif; ?></span>
                <?= $kind === 'c' ? $channelSelect($field_, $value, '– nicht zuordnen –') : $roleSelect($field_, $value) ?>
                <span class="block <?= $hint ?>"><?= e($help) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php
            $modNow = array_values(array_filter(explode(',', $set(BotSettings::MOD_ROLES))));
            $modFrom = $modNow !== [] ? 'gesetzt' : (isset($suggest[BotSettings::MOD_ROLES]) ? 'Vorschlag' : '');
            if ($modNow === [] && $modFrom === 'Vorschlag') {
                $modNow = [$suggest[BotSettings::MOD_ROLES]];
            }
          ?>
          <div class="space-y-1.5"><span class="text-sm text-zinc-300">Moderatoren-Rollen
              <?php if ($modFrom === 'Vorschlag'): ?><span class="ml-1 rounded-full border border-cyan-700/50 px-2 text-xs text-cyan-300">Vorschlag</span><?php endif; ?></span>
            <?= $roles === [] ? '<p class="' . $hint . '">Keine Rollen geladen.</p>' : $chips('modRoles', $roles, $modNow, '@') ?>
            <span class="block <?= $hint ?>">Dürfen mit /mod verwarnen, stummschalten, kicken und bannen. Ohne Auswahl bleibt die bisherige Einstellung.</span></div>
          <p class="rounded-md border border-cyan-900/50 px-4 py-3 text-sm text-zinc-400">Die Orga-Rollen (Mitglied, Planer, Rekrutierer, Ausbilder, Abteilungsleiter) stellst du unter
            <a href="/o/<?= e($org['slug']) ?>/settings" class="text-cyan-300 hover:underline">Orga verwalten</a> ein. Sie bestimmen, wer in der Web-App was darf, und werden deshalb nicht automatisch gesetzt.</p>

        <?php elseif ($section === 'automod'): ?>
          <?php if ($messageContent): ?>
            <p class="flex items-center gap-2 text-sm text-cyan-300"><?= $dot(true) ?>Der Bot liest Nachrichten mit: Automod und Nachrichten-Protokoll sind aktiv (Stand beim letzten Start des Bots).</p>
          <?php else: ?>
            <p class="rounded-md border border-amber-500/40 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
              Die Automod arbeitet nur, wenn der Bot mit <b>BOT_MESSAGE_CONTENT=1</b> läuft und im Developer-Portal der „Message Content Intent“ an ist.
              Der Bot meldet das beim Start; nach der Umstellung muss er neu gestartet werden, dann verschwindet dieser Hinweis.
            </p>
          <?php endif; ?>
          <div class="grid gap-3 sm:grid-cols-3">
            <?php foreach (['spam' => 'Spam', 'invites' => 'Einladungslinks', 'links' => 'Links'] as $k => $label): ?>
              <div class="rounded-md border border-cyan-900/50 px-4 py-3"><?= $switch($k, $automod[$k], $label) ?></div>
            <?php endforeach; ?>
          </div>
          <p class="<?= $hint ?>">Spam: viele Nachrichten kurz hintereinander. Einladungslinks: Links zu anderen Discord-Servern. Links: alle Links außer den erlaubten Domains.</p>
          <label class="block space-y-1.5">
            <span class="text-sm text-zinc-300">Massen-Erwähnungen: <b class="text-cyan-300" data-range-out><?= $automod['mentions'] > 0 ? 'mehr als ' . $automod['mentions'] : 'aus' ?></b></span>
            <input type="range" name="mentions" min="0" max="<?= BotSettings::MAX_MENTIONS ?>" step="1" value="<?= (int) $automod['mentions'] ?>" class="w-full accent-cyan-400" data-range>
            <span class="block <?= $hint ?>">0 = aus, sonst ab 3. Wer mehr Personen oder Rollen in einer Nachricht erwähnt, verstößt gegen die Regel.</span>
          </label>
          <div class="grid gap-5 lg:grid-cols-3">
            <?php foreach ([
                ['words', 'Verbotene Wörter', $automod['words'], BotSettings::MAX_WORDS, 'wort'],
                ['domains', 'Erlaubte Domains (für Links)', $automod['domains'], BotSettings::MAX_DOMAINS, 'youtube.com'],
            ] as [$name, $label, $values, $max, $placeholder]): ?>
              <div class="space-y-2" data-taglist="<?= e($name) ?>" data-max="<?= (int) $max ?>">
                <span class="text-sm text-zinc-300"><?= e($label) ?> <span class="text-zinc-500">(<span data-count><?= count($values) ?></span>/<?= (int) $max ?>)</span></span>
                <textarea name="<?= e($name) ?>" rows="4" class="<?= $field ?>" placeholder="ein Eintrag pro Zeile" data-source><?= e(implode("\n", $values)) ?></textarea>
                <div class="hidden flex-wrap gap-2" data-chips></div>
                <input type="text" class="hidden <?= $field ?>" placeholder="<?= e($placeholder) ?> eingeben, Enter" data-add>
              </div>
            <?php endforeach; ?>
            <div class="space-y-2">
              <span class="text-sm text-zinc-300">Ausgenommene Kanäle</span>
              <div class="max-h-48 overflow-y-auto rounded-md border border-cyan-900/50 p-2">
                <?= $channels === [] && $automod['exemptChannels'] === [] ? '<p class="' . $hint . '">Keine Kanäle geladen.</p>' : $chips('exemptChannels', $channels, $automod['exemptChannels'], '# ') ?>
              </div>
              <span class="block <?= $hint ?>">Höchstens <?= BotSettings::MAX_EXEMPT_CHANNELS ?>. Dort prüft die Automod nichts.</span>
            </div>
          </div>
        <?php endif; ?>
        </div>
      </section>

      <div class="flex justify-end">
        <button class="rounded-md border border-cyan-400/60 bg-cyan-500/20 px-5 py-2 text-sm font-medium hover:bg-cyan-500/30">Speichern</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  // Vorschau der Begrüßung: Platzhalter wie im Bot, **fett** wie in Discord.
  var text = document.querySelector("[data-welcome-text]"), prev = document.querySelector("[data-welcome-preview]");
  if (text && prev) {
    var esc = function (s) { return s.replace(/[&<>"]/g, function (c) { return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;"}[c]; }); };
    var pill = function (s) { return '<span class="rounded bg-[#5865f2]/30 px-1 text-[#c9cdfb]">' + s + "</span>"; };
    var render = function () {
      prev.innerHTML = esc(text.value)
        .split("{user}").join(pill("@Neuling"))
        .split("{server}").join(esc(prev.dataset.server))
        .split("{regeln}").join(pill("#regeln"))
        .replace(/\*\*(.+?)\*\*/g, "<b>$1</b>");
    };
    text.addEventListener("input", render);
    render();
  }
  // Maßnahme: nur die passenden Felder zeigen, vor dem Ausführen nachfragen.
  var mod = document.querySelector("[data-mod-form]");
  if (mod) {
    var doSel = mod.querySelector("[data-mod-do]");
    var sync = function () { mod.querySelectorAll("[data-for]").forEach(function (el) { el.hidden = el.dataset.for !== doSel.value; }); };
    doSel.addEventListener("change", sync);
    sync();
    mod.addEventListener("submit", function (ev) {
      var who = mod.querySelector("[name=person]").value.trim();
      if (!confirm(doSel.options[doSel.selectedIndex].text + ": " + who + "? Das wirkt sofort auf dem Discord-Server.")) ev.preventDefault();
    });
  }
  var range = document.querySelector("[data-range]"), out = document.querySelector("[data-range-out]");
  if (range && out) {
    var show = function () { var n = +range.value; if (n > 0 && n < 3) { n = 3; range.value = 3; } out.textContent = n > 0 ? "mehr als " + n : "aus"; };
    range.addEventListener("input", show);
  }
  // Listen als Chips: das Textfeld bleibt die Quelle (ohne JavaScript bedienbar), JavaScript zeigt Chips und ein Eingabefeld.
  document.querySelectorAll("[data-taglist]").forEach(function (box) {
    var src = box.querySelector("[data-source]"), chips = box.querySelector("[data-chips]"), add = box.querySelector("[data-add]"), count = box.querySelector("[data-count]");
    var max = +box.dataset.max;
    var items = function () { return src.value.split(/\n+/).map(function (s) { return s.trim(); }).filter(Boolean); };
    var draw = function () {
      var list = items();
      chips.innerHTML = "";
      list.forEach(function (v, i) {
        var chip = document.createElement("span");
        chip.className = "inline-flex items-center gap-1.5 rounded-full border border-cyan-400/40 bg-cyan-500/15 px-3 py-1 text-sm text-cyan-300";
        chip.textContent = v;
        var x = document.createElement("button");
        x.type = "button"; x.textContent = "×"; x.setAttribute("aria-label", v + " entfernen"); x.className = "hover:text-red-400";
        x.addEventListener("click", function () { list.splice(i, 1); src.value = list.join("\n"); draw(); });
        chip.appendChild(x);
        chips.appendChild(chip);
      });
      count.textContent = list.length;
      add.disabled = list.length >= max;
    };
    src.classList.add("hidden"); chips.classList.remove("hidden"); chips.classList.add("flex"); add.classList.remove("hidden");
    add.addEventListener("keydown", function (e) {
      if (e.key !== "Enter") { return; }
      e.preventDefault();
      // Gleiche Form wie beim Speichern: Wörter klein, bei Domains nur der Host ohne www.
      var v = add.value.trim().toLowerCase(), list = items();
      if (box.dataset.taglist === "domains") { v = v.replace(/^[a-z]+:\/\//, "").replace(/^www\./, "").split(/[\/?#:\s]/)[0]; }
      if (v !== "" && list.indexOf(v) < 0 && list.length < max) { list.push(v); src.value = list.join("\n"); }
      add.value = ""; draw();
    });
    draw();
  });

  // Vorlagen: alle Haken eines Blocks setzen oder entfernen (gesperrte bleiben aus)
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-check-all]");
    if (!b) { return; }
    b.closest("fieldset").querySelectorAll('input[type=checkbox]:not(:disabled)').forEach(function (c) { c.checked = b.dataset.checkAll === "1"; });
  });

  // Vorhandenes bearbeiten: Rollen-Auswahl nur bei „nur bestimmte Rollen“, Farbfeld nur mit gesetztem Haken
  document.addEventListener("change", function (e) {
    var t = e.target;
    if (t.matches("[data-edit-access]")) {
      t.closest("form").querySelector("[data-edit-roles]").hidden = t.value !== "roles";
    } else if (t.matches("[data-usecolor]")) {
      var color = t.closest("span").querySelector("[data-color]");
      if (color) { color.disabled = !t.checked; }
    }
  });

  // Discord-Setup: Blöcke (Kategorie mit Kanälen) aus Vorlagen zusammenstellen; __B__ und __C__ sind die laufenden Nummern für die Feldnamen.
  var form = document.querySelector("[data-setup]");
  if (form) {
    var blocks = form.querySelector("[data-blocks]"), nextBlock = 0;
    var make = function (id, b, c) {
      var wrap = document.createElement("div");
      wrap.innerHTML = document.getElementById(id).innerHTML.split("__B__").join(b).split("__C__").join(c);
      return wrap.firstElementChild;
    };
    var toggle = function (el, show) { el.hidden = !show; };
    var addChannel = function (block, b, focus) {
      var list = block.querySelector("[data-channels]"), row = make("tpl-channel", b, list.children.length + "-" + Date.now());
      var type = row.querySelector("[data-type]"), access = row.querySelector("[data-access]"), roles = row.querySelector("[data-roles]");
      type.addEventListener("change", function () {
        var voice = type.value === "voice";
        toggle(row.querySelector("[data-topic]"), !voice);
        toggle(row.querySelector("[data-readonly]"), !voice);
      });
      access.addEventListener("change", function () { toggle(roles, access.value === "roles"); });
      row.querySelector("[data-remove-channel]").addEventListener("click", function () { row.remove(); });
      list.appendChild(row);
      if (focus) { row.querySelector("input[type=text]:not([data-topic])").focus(); }
    };
    var addBlock = function () {
      var b = nextBlock++, block = make("tpl-block", b, "0");
      var target = block.querySelector("[data-target]"), access = block.querySelector("[data-newcat] [data-access]"), roles = block.querySelector("[data-newcat] [data-roles]");
      target.addEventListener("change", function () {
        block.querySelectorAll("[data-newcat]").forEach(function (el) { toggle(el, target.value === ""); });
      });
      access.addEventListener("change", function () { toggle(roles, access.value === "roles"); });
      block.querySelector("[data-add-channel]").addEventListener("click", function () { addChannel(block, b, true); });
      block.querySelector("[data-remove-block]").addEventListener("click", function () { block.remove(); });
      blocks.appendChild(block);
      addChannel(block, b);
    };
    form.querySelector("[data-add-block]").addEventListener("click", addBlock);
    addBlock();
  }

  // Rollen und Rechte: Rollen aus einer Vorlage zusammenstellen; __R__ ist die laufende Nummer für die Feldnamen.
  var rform = document.querySelector("[data-roles-form]");
  if (rform) {
    var list = rform.querySelector("[data-role-list]"), nextRole = 0, presets = JSON.parse(rform.dataset.presets || "{}");
    var addRole = function () {
      var wrap = document.createElement("div");
      wrap.innerHTML = document.getElementById("tpl-role").innerHTML.split("__R__").join(nextRole++);
      var role = wrap.firstElementChild;
      var boxes = role.querySelectorAll('input[name$="[perms][]"]'), count = role.querySelector("[data-permcount]");
      var recount = function () { count.textContent = role.querySelectorAll('input[name$="[perms][]"]:checked').length; };
      boxes.forEach(function (b) { b.addEventListener("change", recount); });
      role.querySelector("[data-preset]").addEventListener("change", function (e) {
        var bits = (presets[e.target.value] || []).map(String);
        if (!bits.length) { return; }
        // Nur Rechte ankreuzen, die der Bot vergeben darf (gesperrte Kästchen bleiben aus)
        boxes.forEach(function (b) { if (!b.disabled) { b.checked = bits.indexOf(b.value) >= 0; } });
        recount();
      });
      var color = role.querySelector("[data-color]");
      role.querySelector("[data-usecolor]").addEventListener("change", function (e) { color.disabled = !e.target.checked; });
      role.querySelector("[data-remove-role]").addEventListener("click", function () { role.remove(); });
      list.appendChild(role);
    };
    rform.querySelector("[data-add-role]").addEventListener("click", addRole);
    addRole();
  }
})();
</script>
