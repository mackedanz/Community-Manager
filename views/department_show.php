<?php
/** @var array{slug:string,name:string,canPlan:bool} $org */
/** @var array{id:string,name:string,description:string,image:?string} $dept */
/** @var list<array{id:string,title:string,startsAt:DateTimeImmutable,kind:?string,kindName:string}> $events Kommende Trainings und Übungen */
/** @var bool $manage Admin oder Abteilungsleiter */
/** @var string $csrf */
use Hangar\EventTime;
use Hangar\Training;

$base = '/o/' . $org['slug'] . '/departments';
?>
<div class="space-y-8">
  <div class="space-y-2">
    <a href="<?= e($base) ?>" class="text-sm text-indigo-400 hover:underline">← Abteilungen</a>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-center gap-4">
        <?php if ($dept['image']): ?><img src="<?= e($dept['image']) ?>" alt="" class="h-16 w-16 object-contain"><?php endif; ?>
        <h1 class="text-2xl font-bold"><?= e($dept['name']) ?></h1>
      </div>
      <?php if ($manage): ?>
        <div class="flex items-center gap-3 text-sm">
          <a href="<?= e($base . '?edit=' . $dept['id']) ?>#form" class="text-indigo-400 hover:underline">Bearbeiten</a>
          <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="Abteilung „<?= e($dept['name']) ?>“ wirklich löschen? Ihre Termine bleiben als normale Termine bestehen.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= e($dept['id']) ?>">
            <button class="text-red-400 hover:underline">Löschen</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($dept['description'] !== ''): ?>
      <div class="whitespace-pre-line text-sm text-zinc-300"><?= Training::renderContent($dept['description']) ?></div>
    <?php endif; ?>
  </div>

  <section class="space-y-3" aria-labelledby="events-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="events-title" class="text-lg font-semibold tracking-wide">Kommende Trainings und Übungen</h2>
      <?php if ($org['canPlan']): ?><a href="/o/<?= e($org['slug']) ?>/events/new" class="text-sm text-indigo-400 hover:underline">Termin anlegen</a><?php endif; ?>
    </div>
    <?php if (!$events): ?>
      <p class="rounded-lg border border-cyan-900/50 bg-teal-950/30 p-4 text-sm text-zinc-400">Es sind keine Termine geplant.</p>
    <?php else: ?>
      <ul class="divide-y divide-cyan-900/40 rounded-lg border border-cyan-800/50 bg-teal-950/40 text-sm">
        <?php foreach ($events as $ev): ?>
          <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
            <span><span class="mr-2 rounded border border-cyan-700/60 px-2 py-0.5 text-xs text-cyan-300"><?= e($ev['kindName']) ?></span><a href="/o/<?= e($org['slug']) ?>/events/<?= e($ev['id']) ?>" class="font-medium hover:underline"><?= e($ev['title']) ?></a></span>
            <span class="text-xs text-zinc-400"><?= e(EventTime::format($ev['startsAt'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
