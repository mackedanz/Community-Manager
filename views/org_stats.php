<?php
/** @var array{id:string,slug:string,name:string} $org */
/** @var array<string,mixed> $stats */
use Hangar\Http\View;

$nf = fn ($n) => number_format((float) $n, 0, ',', '.');
$tile = function (string $label, int|float $value, ?string $unit = null, ?string $note = null) use ($nf): string {
    return '<div class="rounded border border-zinc-800 border-l-2 border-l-indigo-500 p-3">'
        . '<div class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">' . e($label) . '</div>'
        . '<div class="mt-1 flex items-baseline gap-2"><span class="text-2xl font-bold">' . e($nf($value)) . '</span>'
        . ($unit ? '<span class="text-xs text-zinc-500">' . e($unit) . '</span>' : '')
        . ($note ? '<span class="text-xs text-zinc-500">' . e($note) . '</span>' : '') . '</div></div>';
};
$pct = fn (int|float $n) => $stats['totalShips'] ? '(' . round($n / $stats['totalShips'] * 100) . ' %)' : null;
$slices = fn (array $list) => array_map(fn ($s) => [
    'label' => $s['label'], 'value' => $s['value'],
    'href' => $s['filter'] ? '/o/' . $org['slug'] . '/hangar?' . $s['filter']['key'] . '=' . rawurlencode($s['filter']['value']) : null,
], $list);
?>
<div class="space-y-8">
  <?= View::partial('partials/fleet_tabs', ['active' => 'stats', 'slug' => $org['slug']]) ?>
  <div>
    <h2 class="text-lg font-semibold">Statistik</h2>
    <p class="text-sm text-zinc-400">
      Auswertung des Orga-Hangars. Alle Zahlen sind anonym, es gibt keine Zuordnung zu
      Mitgliedern. Ein Klick auf ein Segment zeigt die passenden Schiffe in der Orga-Flotte.
    </p>
  </div>

  <?php if ($stats['totalShips'] === 0): ?>
    <p class="text-zinc-400">Noch keine Schiffe in der Orga. Sobald Mitglieder ihren Hangar synchronisieren, erscheinen hier Zahlen.</p>
  <?php else: ?>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <?= $tile('Mitglieder gesamt', $stats['members']) ?>
      <?= $tile('Min. Besatzung', $stats['minCrew'], 'Personen') ?>
      <?= $tile('Max. Besatzung', $stats['maxCrew'], 'Personen') ?>
      <?= $tile('Crew-Defizit', $stats['crewDeficit'], 'Personen') ?>
      <?= $tile('Schiffe gesamt', $stats['totalShips']) ?>
      <?= $tile('Einzigartige Modelle', $stats['uniqueModels'], null, $pct($stats['uniqueModels'])) ?>
      <?= $tile('Flugbereit', $stats['flightReady'], null, $pct($stats['flightReady'])) ?>
      <?= $tile('Frachtkapazität', $stats['totalCargo'], 'SCU') ?>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <?= View::partial('partials/donut', ['title' => 'Schiffe nach Klassifikation', 'slices' => $slices($stats['byCareer'])]) ?>
      <?= View::partial('partials/donut', ['title' => 'Schiffe nach Hersteller', 'slices' => $slices($stats['byManufacturer'])]) ?>
      <?= View::partial('partials/donut', ['title' => 'Schiffe nach Produktionsstatus', 'slices' => $slices($stats['byStatus'])]) ?>
      <?= View::partial('partials/donut', ['title' => 'Schiffe nach Größe', 'slices' => $slices($stats['bySize'])]) ?>
      <?= View::partial('partials/donut', ['title' => 'Schiffe nach Rolle', 'slices' => $slices($stats['byRole'])]) ?>

      <section class="space-y-3 rounded border border-zinc-800 p-4">
        <h3 class="font-semibold">Häufigste Modelle</h3>
        <ul class="space-y-2 text-sm">
          <?php foreach ($stats['topModels'] as $m): ?>
            <li class="space-y-1">
              <div class="flex justify-between gap-2"><span class="truncate"><?= e($m['name']) ?></span><span class="shrink-0 text-zinc-400"><?= (int) $m['count'] ?>×</span></div>
              <div class="h-1.5 rounded bg-zinc-800"><div class="h-1.5 rounded bg-indigo-500" style="width:<?= round($m['count'] / $stats['topModels'][0]['count'] * 100, 2) ?>%"></div></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

    <p class="text-xs text-zinc-500">
      Crew-Defizit = Mindestbesatzung aller Schiffe minus Mitglieder. Schiffe ohne Katalogeintrag
      zählen bei Crew und Fracht nicht mit und erscheinen als „Unbekannt“.
    </p>
  <?php endif; ?>
</div>
