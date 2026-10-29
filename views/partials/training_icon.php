<?php
/**
 * Bild eines Ausbildungsbereichs oder, wenn keins gesetzt ist, ein Standard-Symbol (Linien-Icon).
 * @var string $size Kantenlänge in px
 * @var ?string $image Adresse des Bildes (/img/area/…), sonst null
 */
$size = $size ?? '56';
$image = $image ?? null;
// Bilder sind etwas größer als das Linien-Symbol, damit Einzelheiten erkennbar bleiben
$imgSize = (int) round((int) $size * 1.7);
if ($image !== null && $image !== ''): ?><img src="<?= e($image) ?>" alt="" width="<?= $imgSize ?>" height="<?= $imgSize ?>" class="object-contain" style="width: <?= $imgSize ?>px; height: <?= $imgSize ?>px" loading="lazy"><?php else: ?><svg viewBox="0 0 24 24" width="<?= e($size) ?>" height="<?= e($size) ?>" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9.5 12 5l10 4.5-10 4.5Z"/><path d="M6 11.7V16c0 1.4 2.7 2.8 6 2.8s6-1.4 6-2.8v-4.3M22 9.5V15"/></svg><?php endif; ?>
