<?php
/** @var array<string,mixed> $profile */
use Hangar\FleetFilter;
use Hangar\Hangar;
use Hangar\Http\View;

$u = $profile['user'];
/** @var ?list<array<string,mixed>> $ships nur Schiffe, null = Hangar nicht sichtbar */
$ships = $profile['items'];
$shipCount = $ships === null ? null : array_sum(array_column($ships, 'quantity'));
$models = $ships === null ? null : count($ships);
$ltiCount = $ships === null ? null : array_sum(array_map(static fn (array $e): int => $e['lti'] ? (int) $e['quantity'] : 0, $ships));
$achCount = $profile['achievements'] === null ? null : count($profile['achievements']);
$stats = array_filter([
    'Schiffe' => $shipCount,
    'Modelle' => $models,
    'mit LTI' => $ltiCount,
    'Errungenschaften' => $achCount,
], static fn ($v): bool => $v !== null);
$panel = 'rounded border border-cyan-800/60 bg-teal-950/50';
?>
<div class="space-y-8">
  <section class="<?= $panel ?> flex flex-wrap items-center gap-x-6 gap-y-4 p-5">
    <?php if ($u['image']): ?>
      <img src="<?= e($u['image']) ?>" alt="" class="h-20 w-20 rounded-full ring-2 ring-cyan-700/60">
    <?php else: ?>
      <div class="h-20 w-20 rounded-full bg-zinc-800 ring-2 ring-cyan-700/60"></div>
    <?php endif; ?>
    <div class="min-w-0 flex-1 space-y-2">
      <h1 class="truncate text-3xl font-bold"><?= e($u['name'] ?? 'Unbekannt') ?></h1>
      <?php if ($u['rsiUrl']): ?>
        <a href="<?= e($u['rsiUrl']) ?>" target="_blank" rel="noopener noreferrer" title="RSI-Profil von <?= e($u['rsiHandle']) ?> öffnen"
           class="inline-block rounded border border-cyan-800/60 bg-zinc-950/40 px-3 py-1 text-sm text-zinc-200 hover:border-cyan-500/80">RSI-Profil: <?= e($u['rsiHandle']) ?> ↗</a>
      <?php endif; ?>
    </div>
    <?php if ($stats): ?>
      <dl class="flex flex-wrap gap-3">
        <?php foreach ($stats as $label => $value): ?>
          <div class="min-w-24 rounded border border-cyan-800/40 border-l-2 border-l-cyan-500 bg-zinc-950/40 px-3 py-2">
            <dd class="text-2xl font-bold tabular-nums"><?= (int) $value ?></dd>
            <dt class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></dt>
          </div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </section>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Errungenschaften</h2>
    <?php if ($profile['achievements'] === null): ?>
      <p class="text-sm text-zinc-400">Nicht sichtbar.</p>
    <?php elseif (!$profile['achievements']): ?>
      <p class="text-sm text-zinc-400">Noch keine.</p>
    <?php else: ?>
      <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <?php foreach ($profile['achievements'] as $a): ?>
          <li class="flex gap-3 rounded border border-amber-700/40 bg-amber-950/30 p-3">
            <span class="text-2xl leading-none" aria-hidden="true">🏅</span>
            <div class="min-w-0">
              <div class="font-medium"><?= e($a['title']) ?></div>
              <div class="text-xs text-zinc-400"><?= e($a['description']) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="space-y-3">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-lg font-semibold">Schiffe<?php if ($shipCount !== null): ?> <span class="text-sm font-normal text-zinc-400">(<?= (int) $shipCount ?>)</span><?php endif; ?></h2>
      <?php if ($ships !== null): ?><p class="text-xs text-zinc-400">Zuletzt von RSI übertragen: <?= $profile['lastSync'] ? e(dt($profile['lastSync'])) : 'noch nie' ?></p><?php endif; ?>
    </div>
    <?php if ($ships === null): ?>
      <p class="text-sm text-zinc-400">Der Hangar ist nicht sichtbar.</p>
    <?php elseif (!$ships): ?>
      <p class="text-sm text-zinc-400">Leer.</p>
    <?php else: ?>
      <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 3xl:grid-cols-8">
        <?php foreach ($ships as $e): $c = $e['catalogItem']; ?>
          <?= View::partial('partials/item_tile', [
              'name' => Hangar::entryName($e),
              'href' => $c ? '/catalog/ship/' . rawurlencode($c['slug']) : null,
              'subtitle' => $c['manufacturer'] ?? null,
              'count' => (int) $e['quantity'],
              'image' => $c['imageSrc'] ?? \Hangar\Images\ShipImages::fallbackSrc(Hangar::entryName($e)),
              'tooltip' => Hangar::entryName($e),
              'lti' => (bool) $e['lti'],
              'notReady' => $c ? FleetFilter::notReadyLabel(FleetFilter::parseSpecs($c['data'] ?? null)['status']) : null,
          ]) ?>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
