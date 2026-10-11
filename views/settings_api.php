<?php
/** @var list<array<string,mixed>> $tokens */
/** @var ?string $token */
/** @var ?string $error */
/** @var string $appUrl */
/** @var string $csrf */
$day = fn (?string $s) => $s ? (new DateTimeImmutable($s, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y') : null;
?>
<div class="max-w-2xl space-y-8">
  <div>
    <a href="/settings" class="text-sm text-zinc-400 hover:text-zinc-100">← Einstellungen</a>
    <h1 class="mt-2 text-2xl font-bold">API-Zugang</h1>
    <p class="mt-1 text-sm text-zinc-400">
      Mit einem persönlichen Token können Skripte und Erweiterungen deinen Hangar lesen und
      Importe hochladen. Ein Token hat Zugriff auf alles, was du selbst darfst. Behandle es
      wie ein Passwort.
    </p>
  </div>

  <p class="text-sm text-zinc-400">Für den normalen Hangar-Sync brauchst du kein Token, siehe <a href="/sync" class="text-indigo-400 hover:underline">RSI-Sync</a>.</p>

  <div class="space-y-3">
    <form method="post" action="/settings/api" class="flex flex-wrap gap-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input name="name" placeholder="Name, z. B. Mein Skript" maxlength="60" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
      <button class="rounded bg-indigo-600 px-3 py-2 text-sm font-medium hover:bg-indigo-500">Token erzeugen</button>
    </form>
    <?php if (!empty($error)): ?><p class="text-sm text-red-400"><?= e($error) ?></p><?php endif; ?>
    <?php if (!empty($token)): ?>
      <div class="space-y-2 rounded border border-amber-700 bg-amber-950 p-3 text-sm">
        <p class="text-amber-200">Kopiere das Token jetzt. Es wird nur einmal angezeigt und ist danach nicht mehr abrufbar.</p>
        <code id="new-token" class="block break-all rounded bg-zinc-950 p-2"><?= e($token) ?></code>
        <button type="button" data-copy="#new-token" class="rounded bg-amber-700 px-3 py-1.5 font-medium text-white hover:bg-amber-600">In die Zwischenablage kopieren</button>
      </div>
    <?php endif; ?>
  </div>

  <section class="space-y-2">
    <h2 class="text-lg font-semibold">Deine Tokens</h2>
    <?php if (!$tokens): ?>
      <p class="text-sm text-zinc-400">Noch keine Tokens.</p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800 text-sm">
        <?php foreach ($tokens as $t): ?>
          <li class="flex items-center justify-between gap-4 p-3">
            <div>
              <div class="font-medium"><?= e($t['name']) ?></div>
              <div class="text-xs text-zinc-400">Erstellt <?= e($day($t['created_at'])) ?> · zuletzt benutzt <?= e($day($t['last_used_at']) ?? 'nie') ?></div>
            </div>
            <form method="post" action="/settings/api/revoke">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= e($t['id']) ?>">
              <button class="text-red-400 hover:underline">Widerrufen</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="space-y-2 text-sm">
    <h2 class="text-lg font-semibold">Verwendung</h2>
    <p class="text-zinc-400">Eigenen Hangar abrufen:</p>
    <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-xs">curl -H "Authorization: Bearer sch_DEIN_TOKEN" \
  <?= e($appUrl) ?>/api/v1/me/items</pre>
    <p class="text-zinc-400">Hangar-Export hochladen, ersetzt den ganzen Hangar (mit ?dryRun=1 nur als Vorschau):</p>
    <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-xs">curl -X POST -H "Authorization: Bearer sch_DEIN_TOKEN" \
  -H "Content-Type: application/json" \
  --data-binary @hangarexport.json \
  <?= e($appUrl) ?>/api/v1/import/hangar</pre>
  </section>
</div>
