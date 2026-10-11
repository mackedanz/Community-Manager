<?php /** @var list<array<string,mixed>> $users */ ?>
<div class="mx-auto max-w-md space-y-6">
  <h1 class="text-2xl font-bold">Dev-Login (nur lokal)</h1>
  <p class="text-sm text-amber-400">Meldet ohne Discord an und legt die Testorga „Dev-Orga“ an. Nur mit APP_ENV=local und über localhost.</p>
  <form method="post" action="/dev/login" class="space-y-3 rounded border border-zinc-800 p-4">
    <input type="hidden" name="_csrf" value="">
    <h2 class="font-semibold">Neuer Testnutzer</h2>
    <label class="block text-sm">Name <input name="name" value="Testpilot" class="mt-1 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1"></label>
    <label class="block text-sm">Discord-ID (optional) <input name="discord_id" class="mt-1 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1"></label>
    <label class="block text-sm">Rolle in Dev-Orga
      <select name="role" class="mt-1 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1">
        <option value="ADMIN">Admin</option><option value="MEMBER">Mitglied</option>
      </select>
    </label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="can_plan" value="1"> darf planen (nur Mitglied)</label>
    <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Anlegen und anmelden</button>
  </form>
  <?php if ($users): ?>
    <div class="space-y-2">
      <h2 class="font-semibold">Vorhandene Nutzer</h2>
      <?php foreach ($users as $u): ?>
        <form method="post" action="/dev/login" class="flex items-center gap-2 text-sm">
          <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <input type="hidden" name="role" value="ADMIN">
          <span class="flex-1"><?= e($u['name']) ?> <span class="text-zinc-500"><?= e($u['discord_id']) ?></span></span>
          <button class="rounded bg-zinc-800 px-3 py-1 hover:bg-zinc-700">Anmelden</button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
