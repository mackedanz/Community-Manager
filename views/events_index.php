<?php
/** @var array{slug:string,canPlan:bool} $org */
/** @var string $today */
/** @var list<array{key:string,label:string,events:list<array<string,mixed>>}> $timeline */
use Hangar\EventTime;

$base = '/o/' . $org['slug'] . '/events';
$wdFmt = new IntlDateFormatter('de_DE', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Berlin', IntlDateFormatter::GREGORIAN, 'EEE');
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$monthNow = substr($today, 0, 7);
// Nächster anstehender (oder laufender) Termin ohne abgesagte: bekommt den grünen Rahmen
$nextId = null;
foreach ($timeline as $col) {
    foreach ($col['events'] as $ev) {
        if (!$ev['cancelled'] && empty($ev['draft']) && ($ev['endsAt'] ?? $ev['startsAt']) >= $nowUtc) {
            $nextId = $ev['id'];
            break 2;
        }
    }
}
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="text-lg font-semibold">Termine</h2>
    <?php if ($org['canPlan']): ?>
      <a href="<?= e($base) ?>/new" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Event anlegen</a>
    <?php endif; ?>
  </div>

  <section class="space-y-2">
    
    <div class="overflow-x-auto pb-3" data-timeline>
      <div class="flex min-h-[var(--tl-h,60vh)] min-w-max items-stretch gap-3">
        <?php foreach ($timeline as $col): ?>
          <div class="w-72 shrink-0 space-y-2"<?= $col['key'] === $monthNow ? ' data-now' : '' ?>>
            <div class="border-b-2 border-indigo-500 bg-indigo-500/20 px-2 py-1 text-sm font-semibold <?= $col['key'] === $monthNow ? 'text-indigo-400' : '' ?>"><?= e($col['label']) ?></div>
            <?php foreach ($col['events'] as $ev):
                $mins = $ev['endsAt'] ? (int) round(($ev['endsAt']->getTimestamp() - $ev['startsAt']->getTimestamp()) / 60) : 0;
                $dur = $mins > 0 ? 'ca. ' . rtrim(rtrim(number_format($mins / 60, 1, ',', ''), '0'), ',') . ' h' : '';
                $running = !$ev['cancelled'] && $ev['endsAt'] && $ev['startsAt'] <= $nowUtc && $ev['endsAt'] >= $nowUtc;   // läuft gerade
                $past = $ev['startsAt'] < $nowUtc && (!$ev['endsAt'] || $ev['endsAt'] < $nowUtc);
                $draft = !empty($ev['draft']);   // Entwurf: nur Planer bekommen ihn überhaupt in der Liste
                $locked = ($past || $ev['cancelled']) && !$org['canPlan'];   // Vergangenes und Abgesagtes öffnen nur Planer
                $tag = $locked ? 'div' : 'a';
                $dayNo = substr(EventTime::dayKey($ev['startsAt']), 8, 2);
                $wd = $wdFmt->format($ev['startsAt']); ?>
              <<?= $tag ?><?= ' data-start="' . $ev['startsAt']->getTimestamp() . '"' . ($ev['endsAt'] ? ' data-end="' . $ev['endsAt']->getTimestamp() . '"' : '') ?><?= $locked ? ' aria-disabled="true"' : ' href="' . e($base . '/' . $ev['id']) . '"' ?> class="flex overflow-hidden rounded border transition <?= $past || $draft ? 'border-zinc-800 opacity-45 grayscale' : ($ev['cancelled'] ? 'border-zinc-800 opacity-60' : ($ev['id'] === $nextId ? 'border-green-500 ring-1 ring-green-500/60' : 'border-indigo-500/60')) ?> <?= $locked ? 'cursor-default' : 'hover:border-indigo-400' . ($past ? ' hover:opacity-80' : '') ?>">
                <div class="flex w-14 shrink-0 flex-col items-center justify-center <?= $ev['cancelled'] ? 'bg-zinc-800' : ($running ? 'bg-green-600 text-white' : 'bg-indigo-500/25') ?>">
                  <span class="text-[10px] uppercase <?= $running ? 'text-green-100' : 'text-zinc-400' ?>"><?= e($wd) ?></span>
                  <span class="text-2xl font-bold leading-none"><?= e($dayNo) ?></span>
                </div>
                <div class="min-w-0 flex-1 space-y-0.5 bg-zinc-950/70 px-2 py-1.5">
                  <div class="truncate text-sm font-medium <?= $ev['cancelled'] ? 'line-through' : '' ?>"><?= e($ev['title']) ?></div>
                  <div class="text-xs text-zinc-400"><?= e(EventTime::formatTime($ev['startsAt'])) ?><?= $ev['endsAt'] ? ' – ' . e(EventTime::formatTime($ev['endsAt'])) : '' ?><?= $dur ? ' · ' . e($dur) : '' ?><?= $ev['cancelled'] ? ' · abgesagt' : '' ?><?= $draft ? ' · Entwurf' : '' ?></div>
                  <div class="flex items-center justify-between gap-2 text-[11px] text-zinc-500">
                    <span title="Zusagen" class="shrink-0 whitespace-nowrap"><?= (int) $ev['yes'] ?> ✓ · <?= (int) $ev['shipCount'] ?> Schiffe</span>
                    <span class="truncate">von <?= e($ev['by']) ?></span>
                  </div>
                </div>
              </<?= $tag ?>>
            <?php endforeach; ?>
            <?php if (!$col['events']): ?><p class="px-1 text-xs text-zinc-600">Keine Events</p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>
<script>
(function () {
  var t = document.querySelector("[data-timeline]");
  if (!t) return;
  var n = t.querySelector("[data-now]");

  // Die Monatsspalten reichen bis zur Fußzeile: Höhe = sichtbarer Bereich ab der Leiste bis unten (Seitenrand, Abstand zur Fußzeile und Scrollbalken abgezogen).
  function fit() {
    var sc = document.getElementById("page-scroll");
    if (!sc) return;
    var top = t.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop;
    var h = sc.clientHeight - top - 32 - 24 - 12 - (t.offsetHeight - t.clientHeight);
    t.style.setProperty("--tl-h", Math.max(240, Math.round(h)) + "px");
  }
  fit();
  window.addEventListener("resize", fit);

  // Beim Öffnen den aktuellen Monat in die Mitte rücken (erst, wenn die Leiste eine Breite hat).
  function center() {
    if (!n) return true;
    var a = t.getBoundingClientRect(), b = n.getBoundingClientRect();
    if (!a.width) return false;
    t.scrollLeft += b.left - a.left - (a.width - b.width) / 2;
    return true;
  }
  if (!center() && window.ResizeObserver) {
    var o = new ResizeObserver(function () { if (center()) o.disconnect(); });
    o.observe(t);
  }

  // Die Leiste regelmäßig und beim nächsten Start oder Ende eines Termins neu laden (Farben, Rahmen, Sperre), ohne die Seite zu springen.
  var timer;
  function schedule() {
    clearTimeout(timer);
    var now = Date.now() / 1000, next = Infinity;
    t.querySelectorAll("[data-start]").forEach(function (c) {
      ["start", "end"].forEach(function (k) {
        var v = +c.getAttribute("data-" + k);
        if (v > now && v < next) next = v;
      });
    });
    // Spätestens jede Minute neu laden, damit neue (z. B. aus Discord übernommene) Termine ohne F5 erscheinen; im Hintergrund-Tab ruht es.
    timer = setTimeout(function () { document.hidden ? schedule() : refresh(); }, Math.min((next - now) * 1000 + 1000, 60000));
  }
  function refresh() {
    fetch(location.href, { credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        var fresh = new DOMParser().parseFromString(html, "text/html").querySelector("[data-timeline]");
        if (fresh) { var x = t.scrollLeft; t.innerHTML = fresh.innerHTML; t.scrollLeft = x; }
      })
      .catch(function () {})
      .then(schedule);
  }
  document.addEventListener("visibilitychange", function () { if (!document.hidden) refresh(); });
  schedule();
})();
</script>
