<?php
/** @var array{slug:string,canPlan:bool} $org */
/** @var array<string,mixed> $event */
/** @var list<string> $chunks */
/** @var string $csrf */
use Hangar\EventTime;

$base = '/o/' . $org['slug'] . '/events';
$labels = ['YES' => 'Dabei', 'MAYBE' => 'Vielleicht', 'NO' => 'Kann nicht'];
$my = $event['myRsvp'];
$canClaim = $my === 'YES' && !$event['cancelled'];
$hasSlot = false;
foreach ($event['ships'] as $sh) { foreach ($sh['slots'] as $sl) { if ($sl['userId'] === $event['viewerId']) { $hasSlot = true; } } }
?>
<div class="space-y-8">
  <div class="space-y-2">
    <a href="<?= e($base) ?>" class="text-sm text-zinc-400 hover:underline">← Zu den Terminen</a>
    <div class="flex flex-wrap items-start justify-between gap-2">
      <h2 class="text-xl font-semibold">
        <?php if ($event['draft']): ?><span class="mr-2 rounded bg-amber-900/50 px-2 py-0.5 text-xs text-amber-300">Entwurf</span><?php endif; ?>
        <?php if ($event['cancelled']): ?><span class="mr-2 rounded bg-red-900/50 px-2 py-0.5 text-xs text-red-300">Abgesagt</span><?php endif; ?>
        <?= e($event['title']) ?>
      </h2>
      <?php if ($org['canPlan']): ?>
        <div class="flex flex-wrap gap-2">
          <a href="<?= e($base . '/' . $event['id']) ?>/edit" class="rounded border border-zinc-700 px-3 py-1 text-sm hover:bg-zinc-800">Bearbeiten</a>
          <form method="post" action="<?= e($base . '/' . $event['id']) ?>/cancel">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="cancelled" value="<?= $event['cancelled'] ? '0' : '1' ?>">
            <button class="rounded border border-zinc-700 px-3 py-1 text-sm hover:bg-zinc-800"><?= $event['cancelled'] ? 'Wieder aktivieren' : 'Absagen' ?></button>
          </form>
          <form method="post" action="<?= e($base . '/' . $event['id']) ?>/delete" data-confirm="Dieses Event wirklich löschen?">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="rounded border border-red-900 px-3 py-1 text-sm text-red-400 hover:bg-red-950">Löschen</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <p class="text-sm text-zinc-300"><?= e(EventTime::format($event['startsAt'])) ?><?= $event['endsAt'] ? e(' bis ' . EventTime::format($event['endsAt'])) : '' ?></p>
    <?php if ($org['canPlan'] && $event['discordPush'] && $event['discordOrigin'] !== 'IMPORT'): ?>
      <?php if ($event['discordError']): ?>
        <p class="text-sm text-red-400">Discord-Event: <?= e($event['discordError']) ?> (der Bot versucht es weiter).</p>
      <?php elseif ($event['discordUrl']): ?>
        <p class="text-sm text-zinc-400">In Discord angelegt · <a href="<?= e($event['discordUrl']) ?>" target="_blank" rel="noopener noreferrer" class="text-indigo-400 hover:underline">Event in Discord öffnen ↗</a></p>
      <?php else: ?>
        <p class="text-sm text-zinc-400">Wird in den nächsten Minuten als Discord-Event angelegt<?= $event['draft'] ? ' (sobald der Entwurf veröffentlicht ist)' : '' ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($event['discordUrl'] && $event['discordOrigin'] === 'IMPORT'): ?><p class="text-sm text-zinc-400">Aus Discord übernommen · <a href="<?= e($event['discordUrl']) ?>" target="_blank" rel="noopener noreferrer" class="text-indigo-400 hover:underline">Event in Discord öffnen ↗</a></p><?php endif; ?>
    <?php if ($event['location']): ?><p class="text-sm text-zinc-400">Treffpunkt: <?= e($event['location']) ?></p><?php endif; ?>
    <?php if ($event['description']): ?><p class="max-w-3xl whitespace-pre-wrap pt-2 text-sm"><?= e($event['description']) ?></p><?php endif; ?>
  </div>

  <section class="space-y-2">
    <h3 class="font-medium">Deine Antwort</h3>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($labels as $s => $label): ?>
        <form method="post" action="<?= e($base . '/' . $event['id']) ?>/rsvp">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="status" value="<?= $my === $s ? 'NONE' : $s ?>">
          <button aria-pressed="<?= $my === $s ? 'true' : 'false' ?>" class="rounded border px-3 py-1 text-sm <?= $my === $s ? 'border-indigo-500 bg-indigo-600 text-white' : 'border-zinc-700 hover:bg-zinc-800' ?>"><?= e($label) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if ($canClaim && $event['ships'] && !$hasSlot): ?>
      <p class="rounded border border-indigo-500/60 bg-indigo-500/10 p-2 text-sm">Du bist dabei. Wähle unten bei einem Schiff einen offenen Platz mit „Eintragen“.</p>
    <?php endif; ?>
    <ul class="space-y-1 text-sm text-zinc-300">
      <?php foreach ($labels as $s => $label):
          $names = array_column(array_filter($event['rsvps'], fn ($r) => $r['status'] === $s), 'name'); ?>
        <li><span class="text-zinc-500"><?= e($label) ?> (<?= count($names) ?>):</span> <?= $names ? e(implode(', ', $names)) : '–' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="space-y-3">
    <h3 class="font-medium">Schiffe (<?= count($event['ships']) ?>)</h3>
    <?php if (!$event['ships']): ?>
      <p class="text-sm text-zinc-400">Noch keine Schiffe eingeplant.</p>
    <?php else: ?>
      <ul class="grid gap-3 sm:grid-cols-2">
        <?php foreach ($event['ships'] as $s): ?>
          <li class="flex gap-3 rounded border border-cyan-800/60 bg-teal-950/50 p-3 transition hover:border-cyan-500/80">
            <div class="h-14 w-20 shrink-0 overflow-hidden rounded bg-zinc-900">
              <?php if ($s['imageUrl']): ?><img src="<?= e($s['imageUrl']) ?>" alt="" loading="lazy" class="h-full w-full object-cover"><?php endif; ?>
            </div>
            <div class="min-w-0 flex-1 space-y-1">
              <div class="font-medium"><?php if ($s['href']): ?><a href="<?= e($s['href']) ?>" class="hover:underline"><?= e($s['name']) ?></a><?php else: ?><?= e($s['name']) ?><?php endif; ?></div>
              <?php if ($s['task']): ?><div class="text-xs text-zinc-400"><?= e($s['task']) ?></div><?php endif; ?>
              <ul class="mt-1 grid grid-cols-[max-content_minmax(0,1fr)_max-content] items-center gap-x-3 gap-y-1 text-sm">
                <?php foreach ($s['slots'] as $x): ?>
                  <?php $mineSlot = $x['userId'] === $event['viewerId']; ?>
                  <li class="contents">
                    <span class="text-zinc-400"><?= e($x['label']) ?></span>
                    <span class="truncate <?= $mineSlot ? 'font-semibold text-indigo-400' : '' ?>"><?= $x['userName'] !== null ? e($x['userName']) : '<span class="text-zinc-500">offen</span>' ?></span>
                    <?php if ($canClaim && ($x['userName'] === null || $mineSlot)): ?>
                      <form method="post" action="<?= e($base . '/' . $event['id']) ?>/slot" class="justify-self-end">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="slot" value="<?= $mineSlot ? '' : e($x['id']) ?>">
                        <button class="w-24 rounded border py-0.5 text-xs transition <?= $mineSlot ? 'border-zinc-600 text-zinc-300 hover:bg-zinc-800' : 'border-indigo-500/70 bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500/30' ?>"><?= $mineSlot ? 'Austragen' : 'Eintragen' ?></button>
                      </form>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="space-y-3">
    <h3 class="font-medium">Briefing für Discord</h3>
    <?php if (count($chunks) > 1): ?>
      <p class="text-xs text-zinc-500">Das Briefing ist länger als eine Discord-Nachricht und in <?= count($chunks) ?> Teile geteilt. Poste sie nacheinander.</p>
    <?php endif; ?>
    <?php foreach ($chunks as $i => $c): ?>
      <div class="space-y-1">
        <pre id="briefing-<?= $i ?>" class="max-h-72 overflow-auto whitespace-pre-wrap rounded border border-zinc-800 bg-zinc-900 p-3 text-xs"><?= e($c) ?></pre>
        <button type="button" data-copy="#briefing-<?= $i ?>" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"><?= count($chunks) > 1 ? 'Teil ' . ($i + 1) . ' für Discord kopieren' : 'Für Discord kopieren' ?></button>
      </div>
    <?php endforeach; ?>
  </section>
</div>
