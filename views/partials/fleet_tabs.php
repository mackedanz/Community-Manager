<?php
/**
 * Reiter im Flottenbereich: Orga-Flotte, Statistik, Mein Hangar.
 * @var string $active 'fleet' | 'stats' | 'mine'
 * @var ?string $slug Kürzel der Orga (null: nur Mein Hangar)
 */
$slug = $slug ?? null;
$tabs = [];
if ($slug !== null) {
    $tabs['fleet'] = ['Orga-Flotte', '/o/' . $slug . '/hangar'];
}
$tabs['mine'] = ['Mein Hangar', '/hangar'];
if ($slug !== null) {
    $tabs['stats'] = ['Statistik', '/o/' . $slug . '/stats'];
}
if (count($tabs) < 2) {
    return;
}
?>
<nav class="flex gap-1 border-b border-cyan-900/50 text-sm" aria-label="Flotte">
  <?php foreach ($tabs as $key => [$label, $href]): $on = $key === $active; ?>
    <a href="<?= e($href) ?>" class="-mb-px rounded-t border border-b-0 px-4 py-2 <?= $on ? 'border-cyan-700/60 bg-teal-950/60 font-semibold text-zinc-100' : 'border-transparent text-zinc-400 hover:text-zinc-100' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
