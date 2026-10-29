<?php
/**
 * Symbol eines Ausbildungsbereichs (Linien-Icon) oder, wenn die Orga ein eigenes Bild gesetzt hat, dieses Bild.
 * @var string $area Schlüssel aus Hangar\Training::AREAS
 * @var string $size Kantenlänge in px
 * @var ?string $image Adresse des eigenen Bildes (/img/area/…), sonst null
 */
$size = $size ?? '56';
$image = $image ?? null;
$paths = [
    'mining' => '<path d="M5 20 16 9"/><path d="M8.5 5.5C13 3.5 18.5 6 20 11"/><path d="M8.5 5.5l2.8 2.8M20 11l-3.4-.6"/>',
    'engineering' => '<path d="M9 2.5h6v4.5l3.2 7.5H5.8L9 7Z"/><path d="M7 14.5h10M10 17.5c.6 1.6 1.4 2.6 2 4 .6-1.4 1.4-2.4 2-4"/><path d="M10.5 7h3"/>',
    'transport' => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5Z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
    'ground' => '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="1.5"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5"/>',
    'space' => '<path d="M12 2 14.2 8.5 19.5 13v3l-5.2-1.8-1.1 4.3-1.2-.7-1.2.7-1.1-4.3L4.5 16v-3l5.3-4.5Z"/>',
    'exploration' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5Z"/>',
    'science' => '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3"/><path d="M8 15h8"/>',
];
// Eigene Bilder sind etwas größer als die Linien-Symbole, damit Einzelheiten erkennbar bleiben
$imgSize = (int) round((int) $size * 1.7);
if ($image !== null && $image !== ''): ?><img src="<?= e($image) ?>" alt="" width="<?= $imgSize ?>" height="<?= $imgSize ?>" class="object-contain" style="width: <?= $imgSize ?>px; height: <?= $imgSize ?>px" loading="lazy"><?php else: ?><svg viewBox="0 0 24 24" width="<?= e($size) ?>" height="<?= e($size) ?>" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $paths[$area] ?? '' ?></svg><?php endif; ?>
