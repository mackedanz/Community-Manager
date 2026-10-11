<?php
/** @var list<array<string,mixed>> $items */
/** @var string $q */
/** @var string $kind */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var list<string> $kinds */
use Hangar\Constants;
use Hangar\Http\View;

/** @var array<string,mixed> $filter */
/** @var array<string,list<string>> $options */
$href = fn (array $over) => '/catalog?' . http_build_query(array_filter(['kind' => $kind, 'q' => $q, 'sort' => (($sort ?? 'name') === 'name') ? '' : $sort] + $filter + $over, fn ($v) => $v !== ''));
$pageLink = 'rounded border border-cyan-800/60 bg-teal-950/50 px-3 py-1.5 hover:border-cyan-500/80';
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold">Katalog</h1>
      <p class="text-sm text-zinc-400"><?= (int) $total ?> Treffer<?= $q !== '' ? ' für „' . e($q) . '“' : '' ?></p>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-2">
    <?php foreach ($kinds as $k): ?>
      <a href="/catalog?kind=<?= e($k) ?>"
         class="rounded border px-3 py-1.5 text-sm <?= $k === $kind ? 'border-cyan-500/80 bg-teal-900/60 text-zinc-100' : 'border-cyan-800/60 bg-teal-950/50 text-zinc-300 hover:border-cyan-500/80' ?>"
         <?= $k === $kind ? 'aria-current="page"' : '' ?>><?= e(Constants::KIND_LABELS[$k]) ?></a>
    <?php endforeach; ?>
  </div>

  <?= View::partial('partials/fleet_filter', [
    'action' => '/catalog', 'filter' => $filter, 'q' => $q, 'options' => $options, 'hidden' => ['kind' => $kind],
    'specs' => $kind === 'SHIP', 'placeholder' => 'Name oder Hersteller …', 'sort' => $sort ?? 'name',
  ]) ?>

  <?php if (!$items): ?>
    <p class="text-zinc-400">Nichts gefunden. Ist der Katalog synchronisiert? (<code>php bin/catalog-sync.php</code>)</p>
  <?php else: ?>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 3xl:grid-cols-8">
      <?php foreach ($items as $item): ?>
        <?= View::partial('partials/item_tile', [
          'name' => $item['name'],
          'href' => '/catalog/' . strtolower($item['kind']) . '/' . rawurlencode($item['slug']),
          'subtitle' => $item['manufacturer'],
          'meta' => $item['meta'] ?? null,
          'notReady' => $item['notReady'] ?? null,
          'image' => $item['imageSrc'],
          'stretch' => true,
        ]) ?>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <nav class="flex items-center justify-center gap-3 text-sm" aria-label="Seiten">
      <?php if ($page > 1): ?><a href="<?= e($href(['page' => $page - 1])) ?>" class="<?= $pageLink ?>">← Zurück</a><?php endif; ?>
      <span class="text-zinc-400">Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= e($href(['page' => $page + 1])) ?>" class="<?= $pageLink ?>">Weiter →</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</div>
