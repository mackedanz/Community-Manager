<?php
/** @var bool $loggedIn */
/** @var array<string,string> $kindLabels */
?>
<div class="max-w-2xl space-y-4">
  <h1 class="text-2xl font-bold">Hangar von RSI übernehmen</h1>
  <?php if ($loggedIn): ?>
    <div id="receive" data-kind-labels="<?= e(json_encode($kindLabels, JSON_UNESCAPED_UNICODE)) ?>"></div>
  <?php else: ?>
    <p class="text-zinc-300">
      Du bist im Community-Hangar nicht angemeldet. <a href="/login" class="text-indigo-400 hover:underline">Melde dich an</a>
      und klicke danach auf der RSI-Seite noch einmal auf das Lesezeichen.
    </p>
  <?php endif; ?>
</div>
