<?php
/** @var array{slug:string,name:string,canPlan:bool} $org */
/** @var list<array<string,mixed>> $feed */
/** @var ?array<string,mixed> $stats */
/** @var array{today:list<array<string,mixed>>,tomorrow:list<array<string,mixed>>} $days */
/** @var ?array<string,mixed> $next */
/** @var int $upcomingCount */
/** @var int $trainingCount */
/** @var DateTimeImmutable $now */
use Hangar\EventTime;

$nf = fn ($n) => number_format((float) $n, 0, ',', '.');
$base = '/o/' . $org['slug'];

// Kachel „Kalender“: Zahl der kommenden Termine, darunter der nächste.
$calNote = 'Keine Termine geplant';
if ($next) {
    $when = EventTime::format($next['startsAt']);
    $day = EventTime::dayKey($next['startsAt']);
    $tomorrowKey = (new DateTimeImmutable(EventTime::dayKey($now)))->modify('+1 day')->format('Y-m-d');
    $calNote = ($day === EventTime::dayKey($now) ? 'Heute: ' : ($day === $tomorrowKey ? 'Morgen: ' : 'Als Nächstes: ')) . $next['title'] . ', ' . $when;
}
$tiles = [
    [
        'href' => $base . '/events', 'label' => 'Kalender', 'value' => $nf($upcomingCount), 'note' => $calNote,
        'icon' => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
    ],
    [
        'href' => $base . '/hangar', 'label' => 'Flotte', 'value' => $stats ? $nf($stats['totalShips']) : '–',
        'note' => $stats ? $nf($stats['uniqueModels']) . ' Modelle · ' . $nf($stats['flightReady']) . ' flugbereit' : '',
        // Drei Raumschiffe in Keilformation: vorn das große Führungsschiff, dahinter links und rechts zwei kleinere.
        'icon' => '<g stroke-width="2.3" transform="translate(12 1.2) scale(.62) translate(-12 -2)"><path d="M12 2 14.2 8.5 19.5 13v3l-5.2-1.8-1.1 4.3-1.2-.7-1.2.7-1.1-4.3L4.5 16v-3l5.3-4.5Z"/></g>'
            . '<g stroke-width="2.8" transform="translate(6.4 12.4) scale(.5) translate(-12 -2)"><path d="M12 2 14.2 8.5 19.5 13v3l-5.2-1.8-1.1 4.3-1.2-.7-1.2.7-1.1-4.3L4.5 16v-3l5.3-4.5Z"/></g>'
            . '<g stroke-width="2.8" transform="translate(17.6 12.4) scale(.5) translate(-12 -2)"><path d="M12 2 14.2 8.5 19.5 13v3l-5.2-1.8-1.1 4.3-1.2-.7-1.2.7-1.1-4.3L4.5 16v-3l5.3-4.5Z"/></g>',
    ],
    [
        'href' => $base . '/members', 'label' => 'Mitglieder', 'value' => $stats ? $nf($stats['members']) : '–', 'note' => 'in der App angemeldet',
        'icon' => '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.4 3.4 0 0 1 0 6.8M18.5 14.3c2 .8 3.5 2.7 3.5 5.7"/>',
    ],
    [
        'href' => $base . '/training', 'label' => 'Ausbildung', 'value' => $nf($trainingCount), 'note' => count(\Hangar\Training::AREAS) . ' Bereiche',
        'icon' => '<path d="M2 9.5 12 5l10 4.5-10 4.5Z"/><path d="M6 11.7V16c0 1.4 2.7 2.8 6 2.8s6-1.4 6-2.8v-4.3M22 9.5V15"/>',
    ],
    ['empty' => true],
    ['empty' => true],
];
?>
<div class="space-y-8">
  <div class="grid gap-4 md:grid-cols-3">
    <?php foreach ($tiles as $t): ?>
      <?php if (!empty($t['empty'])): ?>
        <div class="min-h-56 rounded-lg border border-dashed border-cyan-800/40 bg-teal-950/20" aria-hidden="true"></div>
        <?php continue; ?>
      <?php endif; ?>
      <?php $box = 'group flex min-h-56 flex-col items-center justify-center gap-2 rounded-lg border border-cyan-800/50 bg-teal-950/50 px-4 py-8 text-center transition';
            $open = $t['href'] !== null; ?>
      <<?= $open ? 'a href="' . e($t['href']) . '"' : 'div' ?> class="<?= $box ?><?= $open ? ' hover:border-cyan-400/80 hover:bg-teal-900/50 hover:shadow-[0_0_28px_-6px_rgba(34,211,238,0.45)]' : '' ?>">
        <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" class="text-cyan-300<?= $open ? ' transition group-hover:scale-105' : ' opacity-70' ?>" aria-hidden="true"><?= $t['icon'] ?></svg>
        <span class="text-lg font-semibold tracking-wide"><?= e($t['label']) ?></span>
        <?php if ($t['value'] !== ''): ?><span class="text-4xl font-bold tabular-nums text-cyan-100"><?= e($t['value']) ?></span><?php endif; ?>
        <?php if ($t['note'] !== ''): ?><span class="line-clamp-2 max-w-full text-xs text-zinc-400"><?= e($t['note']) ?></span><?php endif; ?>
      </<?= $open ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>

  <section class="space-y-3" aria-labelledby="news-title">
    <h2 id="news-title" class="text-lg font-semibold tracking-wide">News</h2>
    <?php if (!$feed): ?>
      <p class="rounded-lg border border-cyan-900/50 bg-teal-950/30 p-4 text-sm text-zinc-400">Noch keine neuen Mitglieder oder Errungenschaften.</p>
    <?php else: ?>
      <ul class="divide-y divide-cyan-900/40 rounded-lg border border-cyan-800/50 bg-teal-950/40 text-sm">
        <?php foreach ($feed as $e): ?>
          <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
            <span><a href="/members/<?= e($e['userId']) ?>" class="font-medium hover:underline"><?= e($e['userName'] ?? 'Unbekannt') ?></a> <?= e($e['text']) ?></span>
            <span class="text-xs text-zinc-500"><?= e($e['at']->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
