<?php
/** @var array{slug:string,name:string} $org */
/** @var list<array{id:string,name:string,description:string,image:?string,courses:int,active:int}> $areas */
/** @var bool $manage Admin oder Ausbilder */
/** @var ?array{id:string,name:string,description:string,image:?string} $edit Bereich, der gerade bearbeitet wird */
/** @var string $csrf */
use Hangar\Http\View;

$base = '/o/' . $org['slug'] . '/training';
$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm';
?>
<div class="space-y-8">
  <div>
    <h1 class="text-2xl font-bold">Ausbildung</h1>
    <p class="text-sm text-zinc-400">Wähle einen Bereich. Die Ausbildungen bauen aufeinander auf: Zu jedem Thema kommt erst Stufe 1, dann Stufe 2 und so weiter.</p>
  </div>

  <?php if (!$areas): ?>
    <p class="rounded-lg border border-cyan-900/50 bg-teal-950/30 p-4 text-sm text-zinc-400">Es gibt noch keinen Bereich.<?= $manage ? ' Lege unten den ersten an.' : '' ?></p>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($areas as $a): $n = $a['courses']; ?>
        <div class="group relative flex min-h-56 flex-col items-center justify-center gap-2 rounded-lg border border-cyan-800/50 bg-teal-950/50 px-4 py-8 text-center transition hover:border-cyan-400/80 hover:bg-teal-900/50 hover:shadow-[0_0_28px_-6px_rgba(34,211,238,0.45)]">
          <a href="<?= e($base . '/' . $a['id']) ?>" class="absolute inset-0 rounded-lg" aria-label="<?= e($a['name']) ?>"></a>
          <span class="text-cyan-300 transition group-hover:scale-105"><?= View::partial('partials/training_icon', ['size' => '56', 'image' => $a['image']]) ?></span>
          <span class="text-lg font-semibold tracking-wide"><?= e($a['name']) ?></span>
          <?php if ($a['description'] !== ''): ?><span class="line-clamp-2 max-w-full text-xs text-zinc-400"><?= e($a['description']) ?></span><?php endif; ?>
          <?php $act = min($n, $a['active']); ?>
          <?php if ($n === 0): ?>
            <span class="text-sm text-zinc-500">Noch keine Ausbildung</span>
          <?php elseif ($act === 0): ?>
            <span class="text-sm text-zinc-400">Gerade keine Ausbildung angeboten</span>
            <span class="text-xs text-zinc-500"><?= $n === 1 ? '1 Ausbildung' : $n . ' Ausbildungen' ?> insgesamt</span>
          <?php else: ?>
            <span class="text-sm font-medium text-emerald-300"><?= $act ?> von <?= $n ?> <?= $n === 1 ? 'Ausbildung' : 'Ausbildungen' ?> aktiv</span>
          <?php endif; ?>
          <?php if ($manage): ?>
            <div class="relative z-10 mt-1 flex items-center gap-3 text-xs">
              <a href="<?= e($base . '?edit=' . $a['id']) ?>#form" class="text-indigo-400 hover:underline">Bearbeiten</a>
              <form method="post" action="<?= e($base . '/areas/delete') ?>" data-confirm="Bereich „<?= e($a['name']) ?>“ samt allen Ausbildungen wirklich löschen?">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                <button class="text-red-400 hover:underline">Löschen</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($manage): ?>
    <section id="form" class="space-y-3 border-t border-zinc-800 pt-6">
      <h2 class="text-lg font-semibold"><?= $edit ? 'Bereich bearbeiten' : 'Neuer Bereich' ?></h2>
      <form method="post" action="<?= e($base . '/areas/save') ?>" enctype="multipart/form-data" class="max-w-3xl space-y-4">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Name</span>
          <input name="name" required minlength="2" maxlength="80" value="<?= e($edit['name'] ?? '') ?>" class="<?= $input ?>">
        </label>
        <div class="relative space-y-2" data-pick>
          <span class="block text-sm text-zinc-400">Bild (optional, PNG, JPG oder WebP bis 5 MB, wird auf 256 Pixel verkleinert)</span>
          <input type="file" id="area-image" name="image" accept="image/png,image/jpeg,image/webp" class="sr-only peer">
          <div class="flex flex-wrap items-center gap-3">
            <label for="area-image" class="block cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-500" title="Klicken, um ein Bild auszuwählen">
              <img data-preview src="<?= e($edit['image'] ?? '') ?>" alt="" class="h-24 w-24 rounded border border-zinc-800 object-contain transition hover:border-cyan-500/80<?= empty($edit['image']) ? ' hidden' : '' ?>">
            </label>
            <label for="area-image" class="inline-block cursor-pointer rounded border border-cyan-700/70 bg-cyan-500/10 px-3 py-1.5 text-sm font-medium hover:bg-cyan-500/20">Bild auswählen …</label>
            <span data-name class="text-xs text-zinc-500">Keine Datei gewählt</span>
          </div>
          <?php if (!empty($edit['image'])): ?>
            <label class="flex items-center gap-2 text-sm text-zinc-400"><input type="checkbox" name="removeImage" value="1"> Bild entfernen</label>
          <?php endif; ?>
        </div>
        <label class="block space-y-1">
          <span class="text-sm text-zinc-400">Beschreibung (kurz, was in diesem Bereich ausgebildet wird)</span>
          <textarea name="description" rows="4" maxlength="8000" class="<?= $input ?>"><?= e($edit['description'] ?? '') ?></textarea>
        </label>
        <div class="flex items-center gap-3">
          <button class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Speichern</button>
          <?php if ($edit): ?><a href="<?= e($base) ?>" class="text-sm text-zinc-400 hover:underline">Abbrechen</a><?php endif; ?>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
<script>
(function () {
  document.querySelectorAll("[data-pick]").forEach(function (box) {
    var input = box.querySelector("input[type=file]"), img = box.querySelector("[data-preview]"), name = box.querySelector("[data-name]");
    input.addEventListener("change", function () {
      var f = input.files && input.files[0];
      if (!f) { name.textContent = "Keine Datei gewählt"; return; }
      name.textContent = f.name + " (noch nicht gespeichert)";
      img.src = URL.createObjectURL(f);
      img.classList.remove("hidden");
    });
  });
})();
</script>
