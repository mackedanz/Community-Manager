<?php
/** @var list<array<string,mixed>> $orgs */
/** @var list<array<string,mixed>> $bans */
/** @var string $csrf */
use Hangar\Http\View;

$input = 'rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm';
$when = fn (?string $s) => $s ? dt(new DateTimeImmutable($s, new DateTimeZone('UTC'))) : '–';
$csrfField = '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
?>
<div class="space-y-10">
  <h1 class="text-2xl font-bold">Server-Admin</h1>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Registrierte Orgas (<?= count($orgs) ?>)</h2>
    <?php if (!$orgs): ?>
      <p class="text-sm text-zinc-400">Keine Orgas.</p>
    <?php else: ?>
      <ul class="space-y-3">
        <?php foreach ($orgs as $o): ?>
          <li class="space-y-2 rounded border border-zinc-800 p-3">
            <div class="flex items-center gap-3">
              <?= View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['icon_url'], 'size' => 'h-8 w-8']) ?>
              <div>
                <div class="font-medium"><?= e($o['name']) ?></div>
                <div class="text-xs text-zinc-500">Server-ID <?= e($o['discord_guild_id']) ?> · <?= (int) $o['member_count'] ?> Mitglieder · <?= (int) $o['event_count'] ?> Events · angelegt <?= e($when($o['created_at'])) ?></div>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <form method="post" action="/admin/delete-org" data-confirm="Orga „<?= e($o['name']) ?>“ wirklich löschen? Mitgliedschaften und Events gehen verloren.">
                <?= $csrfField ?>
                <input type="hidden" name="orgId" value="<?= e($o['id']) ?>">
                <button class="rounded border border-red-900 px-3 py-1 text-sm text-red-400 hover:bg-red-950">Löschen</button>
              </form>
              <form method="post" action="/admin/ban" class="flex flex-wrap items-center gap-2" data-confirm="„<?= e($o['name']) ?>“ sperren? Die Orga wird gelöscht und der Server kann sich nicht neu registrieren.">
                <?= $csrfField ?>
                <input type="hidden" name="guildId" value="<?= e($o['discord_guild_id']) ?>">
                <input type="hidden" name="name" value="<?= e($o['name']) ?>">
                <input name="reason" placeholder="Grund (optional)" maxlength="500" class="<?= $input ?>">
                <button class="rounded bg-red-700 px-3 py-1 text-sm font-medium hover:bg-red-600">Löschen &amp; sperren</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Bannliste (<?= count($bans) ?>)</h2>
    <?php if (!$bans): ?>
      <p class="text-sm text-zinc-400">Es ist kein Server gesperrt.</p>
    <?php else: ?>
      <ul class="space-y-2">
        <?php foreach ($bans as $b): ?>
          <li class="flex flex-wrap items-center justify-between gap-2 rounded border border-zinc-800 p-3">
            <div>
              <div class="font-medium"><?= e($b['name']) ?></div>
              <div class="text-xs text-zinc-500">Server-ID <?= e($b['discord_guild_id']) ?> · gesperrt <?= e($when($b['banned_at'])) ?></div>
              <?php if ($b['reason']): ?><div class="text-sm text-zinc-300">Grund: <?= e($b['reason']) ?></div><?php endif; ?>
            </div>
            <form method="post" action="/admin/unban" data-confirm="Sperre für „<?= e($b['name']) ?>“ aufheben?">
              <?= $csrfField ?>
              <input type="hidden" name="guildId" value="<?= e($b['discord_guild_id']) ?>">
              <button class="rounded border border-zinc-700 px-3 py-1 text-sm hover:bg-zinc-800">Entsperren</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="space-y-2 pt-2">
      <h3 class="text-sm font-medium text-zinc-300">Server per ID sperren</h3>
      <form method="post" action="/admin/ban" class="flex flex-wrap items-center gap-2">
        <?= $csrfField ?>
        <input name="guildId" required placeholder="Discord-Server-ID" inputmode="numeric" class="<?= $input ?>">
        <input name="name" placeholder="Name (optional)" maxlength="100" class="<?= $input ?>">
        <input name="reason" placeholder="Grund (optional)" maxlength="500" class="<?= $input ?>">
        <button class="rounded bg-red-700 px-3 py-1 text-sm font-medium hover:bg-red-600">Sperren</button>
      </form>
    </div>
  </section>
</div>
