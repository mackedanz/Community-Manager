<?php
/** @var string $name */
/** @var string|null $iconUrl */
$size = $size ?? 'h-6 w-6';
?><?php if (!empty($iconUrl)): ?><img src="<?= e($iconUrl) ?>" alt="" class="<?= e($size) ?> shrink-0 rounded-full"><?php else: ?><span class="<?= e($size) ?> flex shrink-0 items-center justify-center rounded-full bg-zinc-700 text-xs font-semibold"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?>
