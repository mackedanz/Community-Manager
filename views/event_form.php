<?php
/** @var array{slug:string} $org */
/** @var string $heading */
/** @var ?string $eventId */
/** @var array<string,mixed> $initial */
/** @var array<string,mixed> $config */
/** @var string $csrf */
$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm';
$small = 'rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm';
?>
<div class="space-y-4">
  <h2 class="text-lg font-semibold"><?= e($heading) ?></h2>
  <form id="event-form" method="post" action="/o/<?= e($org['slug']) ?>/events/save" class="space-y-8" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="eventId" value="<?= e($eventId ?? '') ?>">
    <input type="hidden" name="payload" value="">

    <section class="max-w-2xl space-y-3">
      <label class="block space-y-1"><span class="text-sm text-zinc-400">Titel</span>
        <input name="title" value="<?= e($initial['title']) ?>" required maxlength="100" class="<?= $input ?>"></label>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block space-y-1"><span class="text-sm text-zinc-400">Beginn (Uhrzeit in Deutschland)</span>
          <input type="datetime-local" name="startsAt" value="<?= e($initial['startsAt']) ?>" required class="<?= $input ?>"></label>
        <label class="block space-y-1"><span class="text-sm text-zinc-400">Ende (optional)</span>
          <input type="datetime-local" name="endsAt" value="<?= e($initial['endsAt']) ?>" class="<?= $input ?>"></label>
      </div>
      <label class="block space-y-1"><span class="text-sm text-zinc-400">Treffpunkt / Ort im Spiel (optional)</span>
        <input name="location" value="<?= e($initial['location']) ?>" maxlength="100" class="<?= $input ?>"></label>
      <label class="block space-y-1"><span class="text-sm text-zinc-400">Briefing: Ziele, Ablauf, Hinweise (optional)</span>
        <textarea name="description" rows="6" maxlength="3000" class="<?= $input ?>"><?= e($initial['description']) ?></textarea></label>
    </section>

    <section class="space-y-3">
      <h3 class="text-lg font-semibold">Schiffe (<span data-ship-count>0</span>)</h3>
      <p data-no-ships class="text-sm text-zinc-500">Noch keine Schiffe gewählt.</p>
      <ul data-ships class="space-y-3"></ul>

      <div class="space-y-3 rounded border border-zinc-800 p-3">
        <h4 class="text-sm font-medium">Schiff aus dem Orga-Hangar hinzufügen</h4>
        <div class="flex flex-wrap gap-2">
          <input data-search placeholder="Suchen (Name, Hersteller)" aria-label="Schiff suchen" class="<?= $small ?> w-56">
          <select data-career aria-label="Karriere" class="<?= $small ?>"><option value="">Alle Karrieren</option></select>
        </div>
        <p data-no-fleet class="hidden text-sm text-zinc-500">Im Orga-Hangar sind noch keine Schiffe.</p>
        <ul data-fleet class="max-h-64 divide-y divide-zinc-800 overflow-y-auto rounded border border-zinc-800"></ul>
        <div class="flex flex-wrap gap-2">
          <input data-custom placeholder="Anderes Schiff (freier Name)" aria-label="Freier Schiffsname" maxlength="80" class="<?= $small ?> w-56">
          <button type="button" data-add-custom class="rounded border border-zinc-700 px-2 py-1 text-xs hover:bg-zinc-800">Hinzufügen</button>
        </div>
      </div>
    </section>

    <div class="space-y-3">
      <label class="flex max-w-2xl items-start gap-2 text-sm">
        <input type="checkbox" name="draft" value="1" class="mt-1"<?= !empty($initial['draft']) ? ' checked' : '' ?>>
        <span><span class="font-medium">Als Entwurf speichern</span><br><span class="text-zinc-400">Ein Entwurf ist nur für Planer und Admins sichtbar. Alle anderen Mitglieder sehen das Event erst, wenn du den Haken entfernst und speicherst.</span></span>
      </label>
      <?php if (!empty($discordBot) && empty($initial['discordImported'])): ?>
        <label class="flex max-w-2xl items-start gap-2 text-sm">
          <input type="checkbox" name="discord" value="1" class="mt-1"<?= !empty($initial['discord']) ? ' checked' : '' ?>>
          <span><span class="font-medium">Auch als Discord-Event anlegen</span><br><span class="text-zinc-400">Der Bot legt das Event innerhalb weniger Minuten auf dem Discord-Server an (als externes Event mit dem Treffpunkt als Ort) und hält es aktuell, auch bei Absage und Löschen. Entwürfe erscheinen erst nach dem Veröffentlichen.</span></span>
        </label>
      <?php endif; ?>
      <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500"><?= $eventId ? 'Änderungen speichern' : 'Event anlegen' ?></button>
    </div>
  </form>
  <script src="/js/event-form.js" defer></script>
</div>
