<?php
/** @var array{slug:string,name:string} $org */
/** @var array<string,int> $counts Anzahl der Ausbildungen je Bereich */
use Hangar\Http\View;
use Hangar\Training;

$base = '/o/' . $org['slug'] . '/training';
?>
<div class="space-y-6">
  <div>
    <h1 class="text-2xl font-bold">Ausbildung</h1>
    <p class="text-sm text-zinc-400">Wähle einen Bereich. Die Ausbildungen bauen aufeinander auf: Zu jedem Thema kommt erst Stufe 1, dann Stufe 2 und so weiter.</p>
  </div>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach (Training::AREAS as $key => [$name, $about]): $n = $counts[$key] ?? 0; ?>
      <a href="<?= e($base . '/' . $key) ?>" class="group flex min-h-56 flex-col items-center justify-center gap-2 rounded-lg border border-cyan-800/50 bg-teal-950/50 px-4 py-8 text-center transition hover:border-cyan-400/80 hover:bg-teal-900/50 hover:shadow-[0_0_28px_-6px_rgba(34,211,238,0.45)]">
        <span class="text-cyan-300 transition group-hover:scale-105"><?= View::partial('partials/training_icon', ['area' => $key, 'size' => '56']) ?></span>
        <span class="text-lg font-semibold tracking-wide"><?= e($name) ?></span>
        <span class="text-xs text-zinc-400"><?= e($about) ?></span>
        <span class="text-sm text-cyan-100"><?= $n === 0 ? 'Noch keine Ausbildung' : ($n === 1 ? '1 Ausbildung' : $n . ' Ausbildungen') ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
