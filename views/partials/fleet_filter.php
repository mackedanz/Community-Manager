<?php
/**
 * Filterleiste für Schiffslisten (Orga Hangar, Mein Hangar, Katalog).
 * @var string $action Ziel des Formulars (GET)
 * @var array<string,mixed> $filter geparste Filter (FleetFilter::parseFilter), q wird getrennt übergeben
 * @var string $q Suchtext
 * @var array<string,list<string>> $options Werte für die Dropdowns
 * @var array<string,string> $hidden zusätzliche versteckte Felder (z. B. kind)
 * @var bool $specs false: nur die Suche zeigen (z. B. Rüstungen im Katalog)
 * @var string $placeholder
 * @var string $sort gewählte Sortierung (FleetFilter::parseSort), Standard Name A–Z
 * @var bool $boxed false: ohne eigenen Rahmen (liegt in einem umgebenden Kasten)
 */
$hidden = $hidden ?? [];
$specs = $specs ?? true;
$boxed = $boxed ?? true;
$placeholder = $placeholder ?? 'Name, Hersteller, Rolle …';
$labels = ['career' => 'Karriere', 'role' => 'Rolle', 'status' => 'Status', 'sizeLabel' => 'Größenklasse', 'size' => 'Größe'];
$input = 'rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm' . ($boxed ? '' : ' w-full');
// Ohne eigenen Rahmen füllen die Felder die ganze Breite des umgebenden Kastens.
$grow = $boxed ? '' : ' min-w-28 flex-1';
$sort = $sort ?? 'name';
$filtering = $q !== '' || array_diff_key($filter, ['q' => 1]) !== [] || $sort !== 'name';
?>
<form method="get" action="<?= e($action) ?>" data-autofilter class="flex flex-wrap items-end gap-3<?= $boxed ? ' rounded border border-zinc-800 p-3' : '' ?>">
  <?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
  <label class="flex flex-col gap-1 text-xs text-zinc-400<?= $boxed ? ' w-full sm:w-64' : ' min-w-48 flex-[2]' ?>">Suche
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="<?= e($placeholder) ?>" class="<?= $input ?>">
  </label>
  <label class="flex flex-col gap-1 text-xs text-zinc-400<?= $grow ?>">Sortierung
    <select name="sort" class="<?= $input ?>">
      <?php foreach (\Hangar\FleetFilter::SORTS as $v => $label): ?>
        <option value="<?= e($v) ?>"<?= $sort === $v ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($specs): ?>
    <?php foreach ($labels as $key => $label): ?>
      <label class="flex flex-col gap-1 text-xs text-zinc-400<?= $grow ?>"><?= e($label) ?>
        <select name="<?= e($key) ?>" class="<?= $input ?>">
          <option value="">Alle</option>
          <?php foreach ($options[$key] ?? [] as $v): ?>
            <option value="<?= e($v) ?>"<?= isset($filter[$key]) && mb_strtolower((string) $filter[$key]) === mb_strtolower($v) ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <label class="flex flex-col gap-1 text-xs text-zinc-400<?= $grow ?>">Crew min.
      <input type="number" name="crewMin" min="0" max="9999" value="<?= e($filter['crewMin'] ?? '') ?>" class="<?= $input ?><?= $boxed ? ' w-20' : '' ?>">
    </label>
    <label class="flex flex-col gap-1 text-xs text-zinc-400<?= $grow ?>">Crew max.
      <input type="number" name="crewMax" min="0" max="9999" value="<?= e($filter['crewMax'] ?? '') ?>" class="<?= $input ?><?= $boxed ? ' w-20' : '' ?>">
    </label>
  <?php endif; ?>
  <button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-sm text-white hover:bg-indigo-500">Filtern</button>
  <?php if ($filtering): ?>
    <a href="<?= e($action . ($hidden ? '?' . http_build_query($hidden) : '')) ?>" class="py-1 text-sm text-zinc-400 hover:underline">Zurücksetzen</a>
  <?php endif; ?>
  <?php if ($specs): ?>
    <p class="w-full text-xs text-zinc-500">
      Crew min.: Schiffe mit Platz für mindestens so viele. Crew max.: Schiffe, die mit höchstens
      so vielen flugfähig sind. Schiffe ohne Katalogdaten erscheinen nur ohne Filter.
    </p>
  <?php endif; ?>
</form>
