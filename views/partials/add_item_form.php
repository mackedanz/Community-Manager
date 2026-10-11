<?php
/** @var string $catalogItemId */
/** @var string $csrf */
/** @var string|null $return Rücksprungadresse (nur /catalog/...) */
/** @var string|null $term */
?>
<form method="post" action="/hangar/add" class="flex flex-wrap items-center gap-3 text-sm">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="catalogItemId" value="<?= e($catalogItemId) ?>">
  <?php if (!empty($return)): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
  <?php if (!empty($term)): ?><input type="hidden" name="add" value="<?= e($term) ?>"><?php endif; ?>
  <label class="flex items-center gap-1.5">
    Anzahl
    <input type="number" name="quantity" value="1" min="1" max="99" class="w-16 rounded border border-zinc-700 bg-zinc-900 px-2 py-1">
  </label>
  <label class="flex items-center gap-1.5"><input type="checkbox" name="lti"> LTI</label>
  <button class="rounded bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500">Zum Hangar hinzufügen</button>
</form>
