<?php
/** @var array{slug:string,name:string} $org */
/** @var string $section */
/** @var array<string,string> $settings */
/** @var array{spam:bool,invites:bool,links:bool,mentions:int,words:list<string>,domains:list<string>,exemptChannels:list<string>} $automod */
/** @var array<string,bool> $status */
/** @var list<array{id:string,name:string,category:?string}> $channels */
/** @var list<array{id:string,name:string,color:int}> $roles */
/** @var ?string $problem */
/** @var list<array<string,mixed>> $cases */
/** @var string $csrf */
use Hangar\BotSettings;

$base = '/o/' . $org['slug'] . '/bot';
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
        $html .= '<label class="cursor-pointer"><input type="checkbox" name="' . e($name) . '[]" value="' . e($it['id']) . '" class="peer sr-only"'
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
    'allgemein' => 'Grundeinstellungen, die mehrere Bereiche nutzen.',
    'termine' => 'Der Bot kündigt Termine an, erinnert 24 und 1 Stunde vorher und lässt Plätze per Knopf wählen.',
    'willkommen' => 'Begrüßt neue Mitglieder. „Bestätigen“ unter den Regeln vergibt die Besucher-Rolle.',
    'moderation' => 'Wer mit /mod verwarnen, stummschalten, kicken und bannen darf und wohin der Bot das protokolliert.',
    'automod' => 'Prüft Nachrichten automatisch. Drei Verstöße in einer Stunde führen zu 10 Minuten Timeout. Admins, Moderatoren und Bots sind ausgenommen.',
];
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
        <option value="<?= e($k) ?>"<?= $k === $section ? ' selected' : '' ?>><?= e($label) ?><?= isset($status[$k]) ? ($status[$k] ? ' · eingerichtet' : ' · aus') : '' ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="mt-2 rounded border border-zinc-600 px-3 py-1 text-sm">Anzeigen</button></noscript>
  </form>

  <div class="grid gap-6 md:grid-cols-[230px_minmax(0,1fr)]">
    <nav class="hidden self-start rounded-lg border border-cyan-800/50 bg-teal-950/30 p-2 md:block" aria-label="Bereiche">
      <?php foreach (BotSettings::SECTIONS as $k => $label): ?>
        <a href="<?= e($base . '?m=' . $k) ?>"<?= $k === $section ? ' aria-current="page"' : '' ?>
           class="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm transition <?= $k === $section ? 'border-cyan-400/50 bg-teal-900/50 text-cyan-300' : 'border-transparent text-zinc-300 hover:bg-teal-900/30' ?>">
          <span><?= e($label) ?></span><?= isset($status[$k]) ? $dot($status[$k]) : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <form method="post" action="<?= e($base . '/save') ?>" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="section" value="<?= e($section) ?>">
      <section class="<?= $card ?>">
        <header class="flex items-start justify-between gap-4 border-b border-cyan-900/50 px-5 py-4">
          <div>
            <h2 class="text-lg font-semibold"><?= e(BotSettings::SECTIONS[$section]) ?></h2>
            <p class="text-sm text-zinc-400"><?= e($descriptions[$section]) ?></p>
          </div>
          <?php if (isset($status[$section])): ?>
            <span class="flex shrink-0 items-center gap-2 text-xs text-zinc-400"><?= $dot($status[$section]) ?><?= $status[$section] ? 'eingerichtet' : 'aus' ?></span>
          <?php endif; ?>
        </header>

        <div class="space-y-5 p-5">
        <?php if ($section === 'allgemein'): ?>
          <div class="grid gap-5 sm:grid-cols-2">
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Admin-Kanal</span>
              <?= $channelSelect('adminChannel', $set(BotSettings::ADMIN_CHANNEL)) ?>
              <span class="block <?= $hint ?>">Kanal für Bot-Einstellungen, nur für Admins sichtbar. Legt /discord-setup an.</span></label>
            <label class="block space-y-1.5"><span class="text-sm text-zinc-300">Besucher-Rolle</span>
              <?= $roleSelect('visitorRole', $set(BotSettings::VISITOR_ROLE)) ?>
              <span class="block <?= $hint ?>">Bekommt man mit „Bestätigen“ unter den Regeln. Sie macht die öffentlichen Kategorien sichtbar.</span></label>
          </div>

        <?php elseif ($section === 'termine'): ?>
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

        <?php elseif ($section === 'automod'): ?>
          <p class="rounded-md border border-amber-500/40 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
            Die Automod arbeitet nur, wenn der Bot mit <b>BOT_MESSAGE_CONTENT=1</b> läuft und im Developer-Portal der „Message Content Intent“ an ist.
          </p>
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
})();
</script>
