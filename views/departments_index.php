<?php
/** @var array{slug:string,name:string} $org */
/** @var list<array{id:string,name:string,description:string,upcoming:int}> $departments */
/** @var bool $manage Admin oder Abteilungsleiter */
/** @var ?array{id:string,name:string,description:string} $edit Abteilung, die gerade bearbeitet wird */
/** @var string $csrf */
$base = '/o/' . $org['slug'] . '/departments';
$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm';
?>
<div class="space-y-8">
  <div>
    <h1 class="text-2xl font-bold">Abteilungen</h1>
    <p class="text-sm text-zinc-400">Jede Abteilung hat ihre Trainings und Übungen. Sie erscheinen im Kalender, die Planer ordnen sie beim Termin einer Abteilung zu.</p>
  </div>

  <?php if (!$departments): ?>
    <p class="rounded-lg border border-cyan-900/50 bg-teal-950/30 p-4 text-sm text-zinc-400">Es gibt noch keine Abteilung.<?= $manage ? ' Lege unten die erste an.' : '' ?></p>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($departments as $d): ?>
        <a href="<?= e($base . '/' . $d['id']) ?>" class="group flex min-h-48 flex-col items-center justify-center gap-2 rounded-lg border border-cyan-800/50 bg-teal-950/50 px-4 py-8 text-center transition hover:border-cyan-400/80 hover:bg-teal-900/50 hover:shadow-[0_0_28px_-6px_rgba(34,211,238,0.45)]">
          <span class="text-lg font-semibold tracking-wide"><?= e($d['name']) ?></span>
          <?php if ($d['description'] !== ''): ?><span class="line-clamp-2 max-w-full text-xs text-zinc-400"><?= e($d['description']) ?></span><?php endif; ?>
          <span class="text-sm text-cyan-100"><?= $d['upcoming'] === 0 ? 'Keine Termine geplant' : ($d['upcoming'] === 1 ? '1 kommender Termin' : $d['upcoming'] . ' kommende Termine') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($manage): ?>
    <section id="form" class="space-y-3 border-t border-zinc-800 pt-6">
      <h2 class="text-lg font-semibold"><?= $edit ? 'Abteilung bearbeiten' : 'Neue Abteilung' ?></h2>
      <form method="post" action="<?= e($base . '/save') ?>" class="max-w-3xl space-y-4">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Name</span>
          <input name="name" required minlength="2" maxlength="80" value="<?= e($edit['name'] ?? '') ?>" class="<?= $input ?>">
        </label>
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Beschreibung (Aufgaben der Abteilung, Hinweise, Links sind erlaubt)</span>
          <textarea name="description" rows="6" maxlength="8000" class="<?= $input ?>"><?= e($edit['description'] ?? '') ?></textarea>
        </label>
        <div class="flex items-center gap-3">
          <button class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Speichern</button>
          <?php if ($edit): ?><a href="<?= e($base) ?>" class="text-sm text-zinc-400 hover:underline">Abbrechen</a><?php endif; ?>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
