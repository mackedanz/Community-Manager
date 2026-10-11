<?php
/** @var array{slug:string,name:string} $org */
/** @var string $area Schlüssel des Bereichs */
/** @var array<string,list<array<string,mixed>>> $topics Ausbildungen nach Thema, je Thema nach Stufe */
/** @var bool $manage Admin oder Ausbilder */
/** @var ?array<string,mixed> $edit Ausbildung, die gerade bearbeitet wird */
/** @var string $csrf */
use Hangar\Http\View;
use Hangar\Training;

[$name, $about] = Training::AREAS[$area];
$base = '/o/' . $org['slug'] . '/training';
$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm';
?>
<div class="space-y-8">
  <div class="space-y-2">
    <a href="<?= e($base) ?>" class="text-sm text-indigo-400 hover:underline">← Ausbildung</a>
    <div class="flex items-center gap-4">
      <span class="text-cyan-300"><?= View::partial('partials/training_icon', ['area' => $area, 'size' => '48']) ?></span>
      <div>
        <h1 class="text-2xl font-bold"><?= e($name) ?></h1>
        <p class="text-sm text-zinc-400"><?= e($about) ?></p>
      </div>
    </div>
  </div>

  <?php if (!$topics): ?>
    <p class="rounded-lg border border-cyan-900/50 bg-teal-950/30 p-4 text-sm text-zinc-400">Hier gibt es noch keine Ausbildung.<?= $manage ? ' Lege unten die erste an.' : '' ?></p>
  <?php endif; ?>

  <?php foreach ($topics as $topic => $courses): ?>
    <section class="space-y-3" aria-label="<?= e($topic) ?>">
      <h2 class="text-lg font-semibold tracking-wide"><?= e($topic) ?></h2>
      <ol class="space-y-3">
        <?php foreach ($courses as $c): ?>
          <li class="rounded-lg border border-cyan-800/50 bg-teal-950/40 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-cyan-700/60 bg-zinc-950/50 text-sm font-bold text-cyan-200" title="Stufe <?= (int) $c['level'] ?>"><?= (int) $c['level'] ?></span>
                <h3 class="font-semibold"><?= e($c['title']) ?></h3>
              </div>
              <?php if ($manage): ?>
                <div class="flex items-center gap-3 text-sm">
                  <a href="<?= e($base . '/' . $area . '?edit=' . $c['id']) ?>#form" class="text-indigo-400 hover:underline">Bearbeiten</a>
                  <form method="post" action="<?= e($base . '/' . $area . '/delete') ?>" data-confirm="Ausbildung „<?= e($c['title']) ?>“ wirklich löschen?">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                    <button class="text-red-400 hover:underline">Löschen</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
            <?php if ($c['content'] !== ''): ?>
              <div class="mt-3 whitespace-pre-line text-sm text-zinc-300"><?= Training::renderContent($c['content']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endforeach; ?>

  <?php if ($manage): ?>
    <section id="form" class="space-y-3 border-t border-zinc-800 pt-6">
      <h2 class="text-lg font-semibold"><?= $edit ? 'Ausbildung bearbeiten' : 'Neue Ausbildung' ?></h2>
      <form method="post" action="<?= e($base . '/' . $area . '/save') ?>" class="max-w-3xl space-y-4">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <label class="block space-y-1">
            <span class="text-sm text-zinc-400">Thema (die Reihe, in der die Stufen aufeinander aufbauen)</span>
            <input name="topic" required minlength="2" maxlength="80" list="topics" value="<?= e($edit['topic'] ?? '') ?>" class="<?= $input ?>">
            <datalist id="topics"><?php foreach (array_keys($topics) as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
          </label>
          <label class="block space-y-1">
            <span class="text-sm text-zinc-400">Stufe</span>
            <input name="level" type="number" required min="1" max="<?= Training::MAX_LEVEL ?>" value="<?= e($edit['level'] ?? '1') ?>" class="<?= $input ?>">
          </label>
        </div>
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Titel</span>
          <input name="title" required minlength="2" maxlength="120" value="<?= e($edit['title'] ?? '') ?>" class="<?= $input ?>">
        </label>
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Inhalt (Beschreibung und Lerninhalte, Links sind erlaubt)</span>
          <textarea name="content" rows="8" maxlength="8000" class="<?= $input ?>"><?= e($edit['content'] ?? '') ?></textarea>
        </label>
        <div class="flex items-center gap-3">
          <button class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Speichern</button>
          <?php if ($edit): ?><a href="<?= e($base . '/' . $area) ?>" class="text-sm text-zinc-400 hover:underline">Abbrechen</a><?php endif; ?>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
