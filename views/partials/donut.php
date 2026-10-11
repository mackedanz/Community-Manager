<?php
/** @var string $title */
/** @var list<array{label:string,value:int,href:?string}> $slices */
$colors = ['#3b82f6', '#22c55e', '#ef4444', '#8b9dc3', '#67e8f9', '#f43f5e', '#f0abfc', '#16a34a', '#f59e0b', '#a78bfa'];
$grey = '#71717a';
$colorOf = fn (array $s, int $i): string => in_array($s['label'], ['Weitere', 'Unbekannt'], true) ? $grey : $colors[$i % count($colors)];
$total = array_sum(array_column($slices, 'value'));
$acc = 0;
$starts = [];
foreach ($slices as $s) {
    $starts[] = ($total > 0 ? $acc / $total : 0) * 100;
    $acc += $s['value'];
}
?>
<section class="space-y-3 rounded border border-zinc-800 p-4">
  <h3 class="font-semibold"><?= e($title) ?></h3>
  <?php if ($total === 0): ?>
    <p class="text-sm text-zinc-400">Keine Daten.</p>
  <?php else: ?>
    <div class="flex flex-wrap items-center gap-6">
      <svg viewBox="0 0 42 42" role="img" aria-label="<?= e($title) ?>" class="h-44 w-44 shrink-0">
        <?php foreach ($slices as $i => $s):
            $pct = $s['value'] / $total * 100;
            $offset = 25 - $starts[$i];
            $dash = count($slices) === 1 ? 100 : max($pct - 0.4, 0.01);
            $circle = '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="' . e($colorOf($s, $i)) . '" stroke-width="6" stroke-dasharray="'
                . round($dash, 4) . ' ' . round(100 - $dash, 4) . '" stroke-dashoffset="' . round($offset, 4) . '"><title>'
                . e($s['label'] . ': ' . $s['value'] . ' (' . number_format($pct, 1, ',', '.') . ' %)') . '</title></circle>';
            echo $s['href'] ? '<a href="' . e($s['href']) . '">' . $circle . '</a>' : '<g>' . $circle . '</g>';
        endforeach; ?>
        <text x="21" y="22.7" text-anchor="middle" class="fill-zinc-100" style="font-size:5px;font-weight:600"><?= (int) $total ?></text>
      </svg>
      <ul class="min-w-0 flex-1 space-y-1 text-sm">
        <?php foreach ($slices as $i => $s):
            $row = '<span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:' . e($colorOf($s, $i)) . '"></span>'
                . '<span class="truncate">' . e($s['label']) . '</span>'
                . '<span class="ml-auto shrink-0 text-zinc-400">' . (int) $s['value'] . ' · ' . number_format($s['value'] / $total * 100, 0) . ' %</span>'; ?>
          <li>
            <?php if ($s['href']): ?>
              <a href="<?= e($s['href']) ?>" class="flex items-center gap-2 hover:text-indigo-300"><?= $row ?></a>
            <?php else: ?>
              <div class="flex items-center gap-2"><?= $row ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>
