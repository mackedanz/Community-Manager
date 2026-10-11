<?php
/** @var list<array<string,mixed>> $entries */
/** @var array<string,list<array<string,mixed>>> $groups */
/** @var ?\DateTimeImmutable $lastSync */
/** @var string $term */
/** @var list<array<string,mixed>> $results */
/** @var string $csrf */
/** @var array<string,mixed> $profile */
use Hangar\Constants;
use Hangar\FleetFilter;
use Hangar\Hangar;
use Hangar\Http\View;
?>
<?php
$ships = array_values(array_filter($entries, static fn (array $e): bool => $e['kind'] === 'SHIP'));
$stats = [
    'Schiffe' => array_sum(array_column($ships, 'quantity')),
    'Modelle' => count($ships),
    'mit LTI' => array_sum(array_map(static fn (array $e): int => $e['lti'] ? (int) $e['quantity'] : 0, $ships)),
];
if ($profile['achievements'] !== null) { $stats['Errungenschaften'] = count($profile['achievements']); }
$u = $profile['user'];
$panel = 'rounded border border-cyan-800/60 bg-teal-950/50';
?>
<div class="space-y-8">
  <?= View::partial('partials/fleet_tabs', ['active' => 'mine', 'slug' => $fleetSlug ?? null]) ?>
  <section class="<?= $panel ?> flex flex-wrap items-center gap-x-6 gap-y-4 p-5">
    <?php if ($u['image']): ?>
      <img src="<?= e($u['image']) ?>" alt="" class="h-20 w-20 rounded-full ring-2 ring-cyan-700/60">
    <?php else: ?>
      <div class="h-20 w-20 rounded-full bg-zinc-800 ring-2 ring-cyan-700/60"></div>
    <?php endif; ?>
    <div class="min-w-0 flex-1 space-y-2">
      <h1 class="truncate text-3xl font-bold"><?= e($u['name'] ?? 'Mein Hangar') ?></h1>
      <?php if ($u['rsiUrl']): ?>
        <a href="<?= e($u['rsiUrl']) ?>" target="_blank" rel="noopener noreferrer" title="RSI-Profil von <?= e($u['rsiHandle']) ?> öffnen"
           class="inline-block rounded border border-cyan-800/60 bg-zinc-950/40 px-3 py-1 text-sm text-zinc-200 hover:border-cyan-500/80">RSI-Profil: <?= e($u['rsiHandle']) ?> ↗</a>
      <?php endif; ?>
    </div>
    <dl class="flex flex-wrap gap-3">
      <?php foreach ($stats as $label => $value): ?>
        <div class="min-w-24 rounded border border-cyan-800/40 border-l-2 border-l-cyan-500 bg-zinc-950/40 px-3 py-2">
          <dd class="text-2xl font-bold tabular-nums"><?= (int) $value ?></dd>
          <dt class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></dt>
        </div>
      <?php endforeach; ?>
    </dl>
  </section>

  <?php if ($profile['achievements']): ?>
  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Errungenschaften</h2>
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
  </section>
  <?php endif; ?>

  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-400">Zuletzt von RSI übertragen: <?= $lastSync ? e(dt($lastSync)) : 'noch nie' ?></p>
    <div class="flex flex-wrap items-center gap-3">
      <?php if ($entries): ?>
        <span class="text-sm text-zinc-400">Gesamten Hangar exportieren:
          <a href="/hangar/export?format=json" class="text-indigo-400 hover:underline">JSON</a> ·
          <a href="/hangar/export?format=csv" class="text-indigo-400 hover:underline">CSV</a>
        </span>
      <?php endif; ?>
      <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" data-rsi-sync title="Öffnet die RSI-Seite in einem neuen Tab. Dort das Lesezeichen anklicken, dann wird dein Hangar übertragen." class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500">Hangar von RSI übertragen</a>
      <a href="/sync" class="text-sm text-indigo-400 hover:underline" title="Anleitung und Lesezeichen für die Übertragung">Lesezeichen einrichten</a>
    </div>
  </div>
  <section class="space-y-3 rounded border border-zinc-800 p-4">
    <div class="flex flex-wrap items-start gap-x-6 gap-y-4">
      <form class="flex shrink-0 flex-col gap-1 lg:border-r lg:border-zinc-800 lg:pr-6" method="get" action="/hangar">
        <label for="add-search" class="w-fit cursor-help text-xs text-zinc-400" title="Für Schiffe, die nicht über RSI kommen (z. B. im Spiel gekauft oder geliehen). Manuelle Einträge bleiben bei jedem RSI-Sync erhalten.">Manuell hinzufügen <span aria-hidden="true" class="text-zinc-500">ⓘ</span></label>
        <div class="flex gap-2">
          <input id="add-search" name="add" value="<?= e($term) ?>" placeholder="Schiff oder Rüstung …" class="w-56 rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
          <button class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700">Suchen</button>
        </div>
      </form>
      <?php if ($entries): ?>
        <div class="min-w-0 flex-1">
          <?= View::partial('partials/fleet_filter', [
            'action' => '/hangar', 'filter' => $filter, 'q' => $q, 'options' => $options, 'boxed' => false, 'sort' => $sort,
            'placeholder' => 'Name, Hersteller, Rolle, LTI …',
          ]) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($term !== '' && !$results): ?>
      <p class="text-sm text-zinc-400">Nichts gefunden für „<?= e($term) ?>“.</p>
    <?php endif; ?>
    <?php if ($results): ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800">
        <?php foreach ($results as $r): ?>
          <li class="flex flex-wrap items-center justify-between gap-3 p-3">
            <div class="min-w-0">
              <div class="font-medium"><?= e($r['name']) ?></div>
              <div class="text-xs text-zinc-400"><?= e($r['manufacturer'] ?? '–') ?> · <?= $r['kind'] === 'SHIP' ? 'Schiff' : 'Rüstung' ?></div>
            </div>
            <?= View::partial('partials/add_item_form', ['catalogItemId' => $r['id'], 'csrf' => $csrf, 'term' => $term]) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($entries && $filtering && !$groups): ?>
    <p class="text-zinc-400">Nichts im Hangar passt zu diesen Filtern.</p>
  <?php endif; ?>
  <?php if (!$entries): ?>
    <p class="text-zinc-400">
      Dein Hangar ist noch leer. Du kannst Schiffe oben manuell hinzufügen oder ihn von deiner
      RSI-Pledge-Seite übertragen. <a href="/sync" class="text-indigo-400 hover:underline">So richtest du den Sync ein</a>.
    </p>
  <?php endif; ?>

  <?php foreach (Constants::ITEM_KINDS as $kind): if (!isset($groups[$kind])) { continue; } $list = $groups[$kind]; $label = Constants::KIND_LABELS[$kind]; ?>
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <button type="button" data-collapse="#sec-<?= e($kind) ?>" aria-expanded="true" class="flex items-center gap-2 text-left">
          <span aria-hidden="true" data-arrow class="w-4 text-sm text-zinc-400">▾</span>
          <h2 class="text-lg font-semibold"><?= e($label) ?> <span class="text-sm font-normal text-zinc-400">(<?= array_sum(array_column($list, 'quantity')) ?>)</span></h2>
        </button>
        <div class="flex items-center gap-4">
          <span class="text-sm text-zinc-400">Export:
            <a href="/hangar/export?format=json&amp;kind=<?= e($kind) ?>" class="text-indigo-400 hover:underline">JSON</a> ·
            <a href="/hangar/export?format=csv&amp;kind=<?= e($kind) ?>" class="text-indigo-400 hover:underline">CSV</a>
          </span>
          <form method="post" action="/hangar/remove-all" data-confirm="Wirklich alle <?= count($list) ?> <?= e($label) ?> aus deinem Hangar entfernen?&#10;&#10;Von RSI übernommene Einträge kommen beim nächsten RSI-Sync zurück, manuell hinzugefügte nicht.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <button class="text-sm text-red-400 hover:underline">Alle entfernen</button>
          </form>
        </div>
      </div>
      <div id="sec-<?= e($kind) ?>">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 3xl:grid-cols-8">
          <?php foreach ($list as $entry):
            $c = $entry['catalogItem'];
            $i = $entry['info'];
            $manual = $entry['source'] === 'MANUAL';
            ob_start(); ?>
            <?php if ($manual): ?><span class="rounded bg-zinc-900/80 px-1.5 py-0.5 text-[10px] text-zinc-300" title="Manuell hinzugefügt, bleibt bei jedem Sync erhalten">manuell</span><?php endif; ?>
            <form method="post" action="/hangar/remove">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="itemId" value="<?= e($entry['id']) ?>">
              <button class="rounded bg-zinc-900/80 px-1.5 py-0.5 text-[10px] text-red-400 hover:bg-red-950" title="<?= $manual ? 'Aus dem Hangar entfernen' : 'Entfernen (kehrt beim nächsten RSI-Sync zurück)' ?>">Entfernen</button>
            </form>
            <?php $actions = (string) ob_get_clean(); ?>
            <?= View::partial('partials/item_tile', [
              'name' => Hangar::entryName($entry),
              'href' => $c ? '/catalog/' . strtolower($entry['kind']) . '/' . rawurlencode($c['slug']) : null,
              'subtitle' => $i && $i['type_label'] ? $i['type_label'] . ($c['manufacturer'] ?? null ? ' · ' . $c['manufacturer'] : '') : ($c['manufacturer'] ?? null),
              'count' => (int) $entry['quantity'],
              'image' => $c['imageSrc'] ?? (!empty($i['image_url']) ? '/img/info/' . rawurlencode($i['match_key']) : \Hangar\Images\ShipImages::fallbackSrc(Hangar::entryName($entry))),
              'tooltip' => $i['description'] ?? Hangar::entryName($entry),
              'lti' => (bool) $entry['lti'],
              'notReady' => $entry['kind'] === 'SHIP' && $c ? FleetFilter::notReadyLabel(FleetFilter::parseSpecs($c['data'] ?? null)['status']) : null,
              'actions' => $actions,
            ]) ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endforeach; ?>
</div>
