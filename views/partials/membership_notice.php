<?php
/** @var string|null $status */
/** @var string $csrf */
?>
<?php if (($status ?? null) === 'REAUTH'): ?>
  <div class="space-y-2 rounded border border-amber-700 bg-amber-950 p-3 text-sm text-amber-200">
    <p>
      Die App braucht eine neue Discord-Freigabe, um deine Orga-Mitgliedschaft zu prüfen
      (Server anzeigen und Mitgliedschaften lesen). Bitte melde dich einmal neu an.
    </p>
    <form method="post" action="/logout?next=login">
      <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
      <button class="rounded bg-amber-700 px-3 py-1.5 font-medium text-white hover:bg-amber-600">Neu mit Discord anmelden</button>
    </form>
  </div>
<?php elseif (($status ?? null) === 'UNAVAILABLE'): ?>
  <p class="rounded border border-zinc-700 p-3 text-sm text-zinc-300">
    Discord ist gerade nicht erreichbar. Die Mitgliedschaft wird in Kürze erneut geprüft.
  </p>
<?php endif; ?>
