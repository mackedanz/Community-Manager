<?php
/**
 * Rollen-ID-Felder mit daneben stehendem Namensfeld (nur zur Anzeige).
 * @var list<string> $values
 * @var string $name
 * @var string $nameField
 * @var array<string,string> $labels
 * @var string $legend
 * @var int $slots
 */
$values = $values ?? [];
$labels = $labels ?? [];
$slots = $slots ?? 10;
$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2';
?>
<fieldset class="space-y-2">
  <legend class="text-sm text-zinc-400"><?= e($legend) ?> (bis zu <?= (int) $slots ?>, eine davon genügt)</legend>
  <?php for ($i = 0; $i < $slots; $i++): $v = $values[$i] ?? ''; ?>
    <div class="grid gap-2 sm:grid-cols-2">
      <input name="<?= e($name) ?>[]" inputmode="numeric" aria-label="Rollen-ID <?= $i + 1 ?>" value="<?= e($v) ?>" placeholder="<?= $i === 0 ? 'z. B. 123456789012345678' : 'Weitere Rolle (optional)' ?>" class="<?= $input ?>">
      <input name="<?= e($nameField) ?>[]" maxlength="40" aria-label="Rollenname zu ID <?= $i + 1 ?>" value="<?= e($labels[$v] ?? '') ?>" placeholder="Rollenname in Discord (optional)" class="<?= $input ?>">
    </div>
  <?php endfor; ?>
</fieldset>
