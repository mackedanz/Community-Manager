<?php /** @var string|null $error */ ?>
<div class="mx-auto max-w-sm space-y-4 text-center">
  <h1 class="text-2xl font-bold">Anmelden</h1>
  <?php if (!empty($error)): ?>
    <p class="rounded border border-red-700 p-3 text-sm text-red-400">
      <?php if ($error === 'allowlist'): ?>
        Dieses Discord-Konto steht nicht auf der Zugangsliste. Wende dich an die Admins deiner Orga.
      <?php else: ?>
        Die Anmeldung bei Discord hat nicht geklappt. Bitte versuche es noch einmal.
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <p class="text-sm text-zinc-400">
    Die Anmeldung läuft über Discord. Dein Discord-Passwort gibst du nur bei
    Discord ein, diese App sieht und speichert es nie.
  </p>
  <p class="text-sm text-zinc-400">
    Discord fragt außerdem, ob die App deine Server und deine Rollen darauf sehen darf. Damit
    prüft sie, zu welcher Orga du gehörst.
  </p>
  <a href="/auth/discord" class="block w-full rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Mit Discord anmelden</a>
</div>
