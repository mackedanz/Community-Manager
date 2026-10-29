<?php
/** @var array<string,mixed> $full */
/** @var list<string> $memberRoleIds */
/** @var list<string> $plannerRoleIds */
/** @var list<string> $adminRoleIds */
/** @var array<string,string> $roleLabels */
/** @var string $csrf */
use Hangar\Http\View;

$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2';
?>
<div class="max-w-2xl space-y-8">
  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Orga verwalten</h2>
    <form method="post" action="/orgs/update" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <label class="block space-y-1">
        <span class="text-sm text-zinc-400">Name der Orga</span>
        <input name="name" required minlength="2" maxlength="60" value="<?= e($full['name']) ?>" class="<?= $input ?>">
      </label>
      <?= View::partial('partials/role_fields', ['values' => $adminRoleIds, 'name' => 'adminRoleIds', 'nameField' => 'adminRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Orga-Leitung (Admins der Orga)']) ?>
      <p class="text-xs text-zinc-500">
        Wer eine dieser Rollen hat, ist Admin der Orga im Manager (Orga verwalten, Discord-Bot, alle Bereiche), auch ohne das
        Discord-Recht „Administrator“ oder „Server verwalten“ und ohne Mitgliedsrolle. Server-Admins sind es immer. Nur an Vertrauenspersonen vergeben.
      </p>
      <?= View::partial('partials/role_fields', ['values' => $memberRoleIds, 'name' => 'memberRoleIds', 'nameField' => 'memberRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Mitgliedsrollen']) ?>
      <?= View::partial('partials/role_fields', ['values' => $plannerRoleIds, 'name' => 'plannerRoleIds', 'nameField' => 'plannerRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Planer (dürfen Events anlegen)']) ?>
      <p class="text-xs text-zinc-500">
        Wer eine dieser Rollen hat, darf bei den Terminen Events anlegen und bearbeiten. Orga-Admins
        dürfen das immer. Ohne Eintrag planen nur Admins.
      </p>
      <?= View::partial('partials/role_fields', ['values' => $recruiterRoleIds, 'name' => 'recruiterRoleIds', 'nameField' => 'recruiterRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Recruiter (sehen die RSI-Zugehörigkeit)']) ?>
      <p class="text-xs text-zinc-500">
        Recruiter und Orga-Admins sehen in der Mitgliederliste die Rahmen (Zugehörigkeit zur RSI-Orga), den Filter und die
        Hinweise zu geänderten Handles. Alle anderen Mitglieder sehen die Liste ohne. Ohne Eintrag sehen es nur Admins.
      </p>
      <?= View::partial('partials/role_fields', ['values' => $instructorRoleIds, 'name' => 'instructorRoleIds', 'nameField' => 'instructorRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Ausbilder (pflegen die Ausbildung)']) ?>
      <p class="text-xs text-zinc-500">
        Ausbilder und Orga-Admins legen die Ausbildungen an, ändern sie und bestätigen Abschlüsse. Ohne Eintrag können es nur Admins.
      </p>
      <?= View::partial('partials/role_fields', ['values' => $leadRoleIds, 'name' => 'leadRoleIds', 'nameField' => 'leadRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Abteilungsleiter (pflegen die Abteilungen)']) ?>
      <p class="text-xs text-zinc-500">
        Abteilungsleiter und Orga-Admins legen Abteilungen an und ändern sie. Ohne Eintrag ist der Bereich Abteilungen ausgeblendet.
      </p>
      <?= View::partial('partials/role_help') ?>
      <p class="text-xs text-zinc-500">Nach einer Änderung der Rollen werden alle Mitglieder bei ihrem nächsten Seitenaufruf neu geprüft.</p>
      <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Speichern</button>
    </form>
  </section>

  <?php if (!empty($botReady) || !empty($allowed)): ?>
  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">Zugangsliste (Discord-Bot)</h2>
    <p class="text-sm text-zinc-400">
      Wer eine der Rollen oben hat, wird vom Bot auf die Liste gesetzt (sobald sich Rollen ändern, stündlich und per Knopf).
      <?= !empty($gate) ? 'Nur Personen auf dieser Liste können sich anmelden.' : 'Die Anmeldung ist noch für alle offen (LOGIN_REQUIRES_ALLOWLIST ist aus).' ?>
      Die Rollen kannst du hier oder im Discord mit <b>/einrichten</b> ändern.
    </p>
    <?php if (!empty($botReady)): ?>
      <form method="post" action="/orgs/sync-allowlist">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
        <button class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800">Mitglieder jetzt abgleichen</button>
        <?php if (!empty($full['allowlist_synced_at'])): ?>
          <span class="ml-2 text-xs text-zinc-500">Letzter Abgleich: <?= e(dt(\Hangar\Time::parse($full['allowlist_synced_at']))) ?></span>
        <?php endif; ?>
      </form>
    <?php endif; ?>
    <details class="text-sm">
      <summary class="cursor-pointer text-zinc-300"><?= count($allowed) ?> Personen auf der Liste</summary>
      <ul class="mt-2 grid gap-1 sm:grid-cols-2">
        <?php foreach ($allowed as $m): ?>
          <li class="flex items-center gap-2">
            <?php if (!empty($m['avatar_url'])): ?><img src="<?= e($m['avatar_url']) ?>" alt="" class="h-6 w-6 rounded-full" loading="lazy"><?php endif; ?>
            <span><?= e($m['name'] ?? $m['discord_id']) ?></span>
            <?php if ($m['fixed']): ?><span class="text-xs text-zinc-500">(fest)</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  </section>
  <?php endif; ?>

  <?php if (!empty($botReady)): ?>
  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">Discord-Events</h2>
    <p class="text-sm text-zinc-400">
      Der Bot übernimmt die Server-Events aus Discord als Termine (alle paar Minuten). Änderungen und Absagen in Discord
      folgen automatisch, solange kein Planer Titel, Zeit, Ort oder Beschreibung im Hangar geändert hat. Schiffe, Plätze
      und Zusagen pflegst du im Hangar. Als Entwurf übernommene Termine sehen zuerst nur Planer.
    </p>
    <form method="post" action="/orgs/discord-events" class="flex flex-wrap items-end gap-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <label class="space-y-1">
        <span class="block text-sm text-zinc-400">Übernahme</span>
        <select name="mode" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
          <?php foreach (['OFF' => 'Aus', 'DRAFT' => 'Als Entwurf übernehmen', 'PUBLISHED' => 'Direkt sichtbar'] as $v => $label): ?>
            <option value="<?= e($v) ?>"<?= ($full['discord_events_mode'] ?? 'OFF') === $v ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800">Speichern und abgleichen</button>
    </form>
    <?php if (!empty($full['discord_events_synced_at'])): ?>
      <p class="text-xs text-zinc-500">Letzter Abgleich: <?= e(dt(\Hangar\Time::parse($full['discord_events_synced_at']))) ?></p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">Design</h2>
    <p class="text-sm text-zinc-400">
      Logo und Hintergrundbild der ganzen Installation (gilt für alle Orgas und die Anmeldeseite). Erlaubt sind PNG, JPG und WebP
      bis 5 MB. Das Logo wird auf 256 Pixel, der Hintergrund auf 2000 Pixel Breite verkleinert. Auch mit dem Discord-Befehl
      <b>/design</b> einstellbar. Die Deckkraft gibt an, wie stark das Hintergrundbild zu sehen ist (0 = unsichtbar, 100 = voll), getrennt für den dunklen und den hellen Modus.
    </p>
    <form method="post" action="/orgs/branding" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <div class="grid gap-6 sm:grid-cols-2">
        <?php foreach ([
            ['logo', 'Logo', $brand['logoCustom'] ? 'eigenes' : 'Standard', $brand['logoUrl'], 'h-24 w-24'],
            ['background', 'Hintergrund', $brand['backgroundCustom'] ? 'eigener' : 'Standard', $brand['backgroundUrl'], 'h-24 w-40 object-cover'],
        ] as [$name, $title, $state, $src, $size]): ?>
          <div class="relative space-y-2" data-pick>
            <span class="block text-sm text-zinc-400"><?= e($title) ?> (<?= e($state) ?>)</span>
            <input type="file" id="brand-<?= e($name) ?>" name="<?= e($name) ?>" accept="image/png,image/jpeg,image/webp" class="sr-only peer">
            <label for="brand-<?= e($name) ?>" title="Klicken, um ein Bild auszuwählen" class="block w-fit cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-500">
              <img data-preview src="<?= e($src) ?>" alt="<?= e($title) ?>" class="<?= e($size) ?> rounded border border-zinc-800 transition hover:border-cyan-500/80">
            </label>
            <div class="flex flex-wrap items-center gap-3">
              <label for="brand-<?= e($name) ?>" class="inline-block cursor-pointer rounded border border-cyan-700/70 bg-cyan-500/10 px-3 py-1.5 text-sm font-medium hover:bg-cyan-500/20">Bild auswählen …</label>
              <span data-name class="text-xs text-zinc-500">Keine Datei gewählt</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="flex flex-wrap gap-4">
        <label class="space-y-1">
          <span class="block text-sm text-zinc-400">Deckkraft dunkel (%)</span>
          <input type="number" name="opacityDark" min="0" max="100" value="<?= (int) $brand['opacityDark'] ?>" class="w-28 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
        </label>
        <label class="space-y-1">
          <span class="block text-sm text-zinc-400">Deckkraft hell (%)</span>
          <input type="number" name="opacityLight" min="0" max="100" value="<?= (int) $brand['opacityLight'] ?>" class="w-28 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
        </label>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500">Speichern</button>
        <?php foreach (['logo' => 'Logo zurücksetzen', 'background' => 'Hintergrund zurücksetzen', 'all' => 'Alles zurücksetzen'] as $v => $label): ?>
          <button name="reset" value="<?= e($v) ?>" formnovalidate class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    </form>
  </section>

  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">RSI-Orga</h2>
    <p class="text-sm text-zinc-400">
      Mit dem Kürzel deiner Orga auf RSI (z. B. <b>EXPG</b>, steht in der Adresse robertsspaceindustries.com/orgs/<b>EXPG</b>)
      färbt die App in der Mitgliederliste den Rahmen jedes Mitglieds nach seiner Zugehörigkeit zur RSI-Orga. Der RSI-Handle
      wird aus dem Server-Nickname abgeleitet, je nach Auswahl unten. Es werden nur öffentliche Daten
      gelesen; die Liste wird täglich aktualisiert. Leer lassen und speichern trennt die Verbindung.
    </p>
    <form method="post" action="/orgs/rsi" class="flex flex-wrap items-end gap-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <label class="space-y-1">
        <span class="block text-sm text-zinc-400">RSI-Kürzel</span>
        <input name="rsiSid" maxlength="20" value="<?= e($full['rsi_sid'] ?? '') ?>" class="w-40 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 uppercase">
      </label>
      <button class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800">Speichern und abgleichen</button>
    </form>
    <form method="post" action="/orgs/handle-style" class="flex flex-wrap items-end gap-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <label class="space-y-1">
        <span class="block text-sm text-zinc-400">Wo steht der RSI-Handle im Nickname?</span>
        <select name="handleStyle" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
          <?php foreach (\Hangar\RsiOrg::HANDLE_STYLES as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= \Hangar\RsiOrg::cleanHandleStyle($full['handle_style'] ?? null) === $k ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800">Speichern</button>
    </form>
    <?php if (!empty($full['rsi_sid'])): ?>
      <p class="text-xs text-zinc-500">
        Verbunden mit <b class="text-zinc-300"><?= e($full['rsi_org_name'] ?? $full['rsi_sid']) ?></b>:
        <?= (int) $rsiRoster ?> sichtbare Mitglieder, <?= (int) $full['rsi_redacted'] ?> mit verborgener Zugehörigkeit<?= !empty($full['rsi_synced_at']) ? '; Stand ' . e(dt(\Hangar\Time::parse($full['rsi_synced_at']))) : '; noch nicht abgeglichen' ?>.
      </p>
    <?php endif; ?>
  </section>

  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold text-red-400">Orga löschen</h2>
    <p class="text-sm text-zinc-400">
      Entfernt die Orga und alle Mitgliedschaften aus der App. Konten und Hangars der
      Mitglieder bleiben erhalten. Tippe zur Bestätigung <b>LÖSCHEN</b>.
    </p>
    <form method="post" action="/orgs/delete" class="space-y-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <div class="flex gap-2">
        <input name="confirm" autocomplete="off" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
        <button class="rounded bg-red-700 px-3 py-2 text-sm font-medium hover:bg-red-600">Orga löschen</button>
      </div>
    </form>
  </section>
</div>
<script>
(function () {
  document.querySelectorAll("[data-pick]").forEach(function (box) {
    var input = box.querySelector("input[type=file]"), img = box.querySelector("[data-preview]"), name = box.querySelector("[data-name]");
    input.addEventListener("change", function () {
      var f = input.files && input.files[0];
      if (!f) { name.textContent = "Keine Datei gewählt"; return; }
      name.textContent = f.name + " (noch nicht gespeichert)";
      img.src = URL.createObjectURL(f);
      // Bei den Ausbildungsbereichen verschwindet das Standard-Symbol, sobald ein Bild gewählt ist
      img.classList.remove("hidden");
      var std = box.querySelector("[data-default]");
      if (std) std.classList.add("hidden");
    });
  });
})();
</script>
