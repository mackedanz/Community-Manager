<?php
/** @var list<array<string,mixed>> $members */
/** @var array<string,mixed> $org */
/** @var ?string $rsiOrgName */
/** @var int $total alle Mitglieder (vor dem Filter) */
/** @var string $rsiFilter aktiver RSI-Filter oder leer */
/** @var array<string,int> $rsiCounts */
/** @var bool $hasRsi */
/** Rahmenfarbe und Tooltip je RSI-Status (null = kein RSI-Abgleich eingerichtet). */
$rsiLook = [
    'main' => ['border-emerald-600/70 hover:border-emerald-400', 'In der RSI-Orga (Hauptorga)'],
    'affiliate' => ['border-cyan-600/70 hover:border-cyan-400', 'In der RSI-Orga als Affiliate'],
    'out' => ['border-yellow-600/70 hover:border-yellow-400', 'Nicht in der Mitgliederliste der RSI-Orga gefunden'],
    'unknown' => ['border-red-700/70 hover:border-red-500', 'RSI-Zugehörigkeit nicht prüfbar (verborgen oder kein Handle ableitbar)'],
];
$legend = ['main' => ['Mitglied', 'border-emerald-600'], 'affiliate' => ['Affiliate', 'border-cyan-600'], 'out' => ['nicht gefunden', 'border-yellow-600'], 'unknown' => ['nicht prüfbar', 'border-red-700']];
?>
<div class="space-y-4">
  <h2 class="text-lg font-semibold">Mitglieder <span class="text-sm font-normal text-zinc-400">(<?= $rsiFilter !== '' ? count($members) . ' von ' . $total : count($members) ?>)</span></h2>
  <p class="text-xs text-zinc-500">Hier erscheint, wer sich in der App angemeldet hat und auf Discord als Mitglied der Orga bestätigt ist.</p>
  <?php if ($hasRsi): ?>
    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-400">
      <span>Rahmen = Zugehörigkeit zur RSI-Orga<?= !empty($rsiOrgName) ? ' „' . e($rsiOrgName) . '“' : '' ?>:</span>
      <?php foreach ($legend as $key => [$label, $box]): $on = $rsiFilter === $key; ?>
        <a href="<?= $on ? '?' : '?rsi=' . e($key) ?>" class="flex items-center gap-1 rounded px-1.5 py-0.5 hover:text-zinc-100 <?= $on ? 'bg-zinc-800 text-zinc-100 ring-1 ring-zinc-600' : '' ?>"
           title="<?= $on ? 'Filter aufheben' : 'Nur „' . e($label) . '“ anzeigen' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
          <i class="inline-block h-3 w-3 rounded-sm border <?= $box ?>"></i> <?= e($label) ?> <span class="text-zinc-500">(<?= (int) ($rsiCounts[$key] ?? 0) ?>)</span>
        </a>
      <?php endforeach; ?>
      <?php if ($rsiFilter !== ''): ?><a href="?" class="text-indigo-400 hover:underline">Alle anzeigen</a><?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if ($members === [] && $rsiFilter !== ''): ?>
    <p class="text-sm text-zinc-400">Niemand hat diesen RSI-Status.</p>
  <?php endif; ?>
  <ul class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
    <?php foreach ($members as $m):
      [$rsiBorder, $rsiTitle] = $rsiLook[$m['rsiStatus'] ?? ''] ?? ['border-zinc-800 hover:border-zinc-600', null]; ?>
      <li>
        <a href="/members/<?= e($m['id']) ?>" class="flex items-center gap-3 rounded border <?= $rsiBorder ?> p-3"<?= $rsiTitle !== null ? ' title="' . e($rsiTitle) . '"' : '' ?>>
          <?php if ($m['image']): ?><img src="<?= e($m['image']) ?>" alt="" class="h-10 w-10 rounded-full"><?php else: ?><div class="h-10 w-10 rounded-full bg-zinc-800"></div><?php endif; ?>
          <div class="min-w-0">
            <div class="truncate font-medium"><?= e($m['name'] ?? 'Unbekannt') ?><?php if ($m['isAdmin']): ?><span class="ml-2 text-xs text-amber-400">Admin</span><?php endif; ?></div>
            <div class="text-xs text-zinc-400">
              <?= $m['shipCount'] === null ? 'Hangar privat' : e($m['shipCount'] . ' Schiffe') ?>
              <?= $m['achievementCount'] !== null ? e(' · ' . $m['achievementCount'] . ' Errungenschaften') : '' ?>
            </div>
            <?php if (!empty($m['rsiHint'])): $h = $m['rsiHint']; ?>
              <div class="mt-1 text-xs text-yellow-400">
                <?php if (!empty($h['conflict'])): ?>
                  Der Handle gehört jetzt zu einem anderen RSI-Konto (erwartet #<?= (int) $h['record'] ?>). Bitte nachfragen.
                <?php elseif ($h['suggested'] !== null): ?>
                  Neuer Handle wahrscheinlich <strong><?= e($h['suggested']) ?></strong> (#<?= (int) $h['record'] ?>). Bitte im Discord den Nickname anpassen.
                <?php else: ?>
                  Handle nicht mehr auffindbar (RSI-Konto #<?= (int) $h['record'] ?>). Bitte nachfragen.
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
