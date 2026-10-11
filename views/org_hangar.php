<?php
/** @var array{id:string,slug:string,name:string} $org */
/** @var array<string,mixed> $all */
/** @var array<string,mixed> $filter */
/** @var array<string,list<string>> $options */
/** @var list<array<string,mixed>> $entries */
/** @var bool $filtering */
/** @var int $totalShips */
use Hangar\FleetFilter;
use Hangar\Http\View;

?>
<div class="space-y-6">
  <?= View::partial('partials/fleet_tabs', ['active' => 'fleet', 'slug' => $org['slug']]) ?>
  <div class="flex flex-wrap items-end justify-between gap-2">
    <h2 class="text-lg font-semibold">Orga-Flotte</h2>
    <p class="text-sm text-zinc-400"><?= (int) $totalShips ?> Schiffe · <?= count($entries) ?> Modelle · <?= (int) $all['memberCount'] ?> Mitglieder mit Schiffen</p>
  </div>

  <?php if ($all['entries']): ?>
    <?= View::partial('partials/fleet_filter', [
      'action' => '/o/' . $org['slug'] . '/hangar', 'filter' => $filter, 'q' => $filter['q'] ?? '', 'options' => $options, 'sort' => $sort,
    ]) ?>
  <?php endif; ?>

  <?php if (!$entries && $filtering && $all['entries']): ?>
    <p class="text-zinc-400">Keine Schiffe passen zu diesen Filtern.</p>
  <?php elseif (!$entries): ?>
    <p class="text-zinc-400">Noch keine Schiffe in der Orga. <a href="/sync" class="text-indigo-400 hover:underline">Synchronisiere deinen Hangar mit RSI</a>.</p>
  <?php else: ?>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 3xl:grid-cols-8">
      <?php foreach ($entries as $en): ?>
        <?= View::partial('partials/item_tile', [
          'name' => $en['name'],
          'href' => $en['href'],
          'subtitle' => $en['manufacturer'],
          'count' => (int) $en['count'],
          'image' => $en['imageUrl'],
          'notReady' => FleetFilter::notReadyLabel($en['specs']['status'] ?? null),
        ]) ?>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="text-xs text-zinc-500">
    Anonyme Übersicht: zählt die Schiffe aller Mitglieder von <?= e($org['name']) ?>, unabhängig von deren
    Sichtbarkeits-Einstellung, und zeigt nie, wem ein Schiff gehört.
  </p>
</div>
