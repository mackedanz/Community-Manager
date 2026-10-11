<?php
/** @var \Hangar\Viewer $viewer */
/** @var array<string,mixed> $user */
/** @var ?\DateTimeImmutable $checkedAt */
use Hangar\Http\View;

$labels = ['PRIVATE' => 'Nur ich', 'MEMBERS' => 'Meine Orga(s)'];
$select = function (string $name, string $value) use ($labels): string {
    $o = '';
    foreach ($labels as $v => $l) {
        $o .= '<option value="' . e($v) . '"' . ($v === $value ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return '<select name="' . e($name) . '" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2">' . $o . '</select>';
};
?>
<div class="max-w-md space-y-8">
  <h1 class="text-2xl font-bold">Einstellungen</h1>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Meine Orgas</h2>
    <?= View::partial('partials/membership_notice', ['status' => $viewer->membershipStatus, 'csrf' => $viewer->csrf]) ?>
    <?php if (!$viewer->orgs): ?>
      <p class="text-sm text-zinc-400">Du bist in keiner Orga bestätigt.</p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800 text-sm">
        <?php foreach ($viewer->orgs as $o): ?>
          <li class="flex items-center gap-3 p-3">
            <?= View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl']]) ?>
            <a href="/o/<?= e($o['slug']) ?>" class="flex-1 hover:underline"><?= e($o['name']) ?></a>
            <?php if ($o['role'] === 'ADMIN'): ?><span class="text-xs text-amber-400">Admin</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="text-xs text-zinc-500">
      Die Mitgliedschaft wird über euren Discord-Server geprüft (Server und Mitgliedsrolle),
      beim Login und danach alle 24 Stunden. Letzte Prüfung: <?= $checkedAt ? e(dt($checkedAt)) : 'noch nie' ?>.
    </p>
    <div class="flex flex-wrap gap-3">
      <form method="post" action="/recheck">
        <input type="hidden" name="_csrf" value="<?= e($viewer->csrf) ?>">
        <input type="hidden" name="back" value="settings">
        <button class="rounded bg-indigo-600 px-3 py-2 text-sm font-medium hover:bg-indigo-500">Mitgliedschaft jetzt prüfen</button>
      </form>
    </div>
  </section>

  <section class="space-y-4 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">Sichtbarkeit</h2>
    <p class="text-sm text-zinc-400">RSI-Handle: <b class="text-zinc-200"><?= e($user['rsi_handle'] ?? '–') ?></b> <span class="text-xs">(wird beim RSI-Sync übernommen)</span></p>
    <form method="post" action="/settings" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($viewer->csrf) ?>">
      <label class="block space-y-1"><span class="text-sm text-zinc-400">Hangar sichtbar für</span><?= $select('hangarVisibility', $user['hangar_visibility']) ?></label>
      <label class="block space-y-1"><span class="text-sm text-zinc-400">Errungenschaften sichtbar für</span><?= $select('achievementsVisibility', $user['achievements_visibility']) ?></label>
      <p class="text-xs text-zinc-500">
        „Meine Orga(s)“ heißt: sichtbar für die bestätigten Mitglieder jeder Orga, in der du
        bist. Fremde Orgas und nicht angemeldete Besucher sehen nie etwas. Deine Schiffe
        fließen außerdem immer anonym in den Orga-Hangar deiner Orgas ein (nur Schiffsname und
        Gesamtzahl, ohne deinen Namen).
      </p>
      <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Speichern</button>
    </form>
  </section>

  <p class="text-sm"><a href="/settings/api" class="text-indigo-400 hover:underline">API-Tokens verwalten →</a></p>

  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold text-red-400">Konto löschen</h2>
    <p class="text-sm text-zinc-400">
      Löscht dein Konto mit Hangar, Errungenschaften, Orga-Mitgliedschaften und Tokens
      unwiderruflich aus dieser App. Dein Discord-Konto bleibt unberührt. Tippe zur
      Bestätigung <b>LÖSCHEN</b>.
    </p>
    <form method="post" action="/settings/delete-account" class="flex gap-2">
      <input type="hidden" name="_csrf" value="<?= e($viewer->csrf) ?>">
      <input name="confirm" autocomplete="off" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
      <button class="rounded bg-red-700 px-3 py-2 text-sm font-medium hover:bg-red-600">Endgültig löschen</button>
    </form>
  </section>
</div>
