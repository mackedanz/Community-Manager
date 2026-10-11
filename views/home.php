<?php
/** @var \Hangar\Viewer $viewer */
?>
<div class="max-w-2xl space-y-6">
  <h1 class="text-2xl font-bold">Willkommen, <?= e($viewer->name ?? 'Pilot') ?></h1>
  <?= \Hangar\Http\View::partial('partials/membership_notice', ['status' => $viewer->membershipStatus, 'csrf' => $viewer->csrf]) ?>

  <?php if (count($viewer->orgs) > 1): ?>
    <section class="space-y-3">
      <h2 class="text-lg font-semibold">Deine Orgas</h2>
      <ul class="grid gap-3 sm:grid-cols-2">
        <?php foreach ($viewer->orgs as $o): ?>
          <li>
            <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-3 rounded border border-zinc-800 p-3 hover:border-zinc-600">
              <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-10 w-10']) ?>
              <span class="font-medium"><?= e($o['name']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php else: ?>
    <section class="space-y-3 text-sm text-zinc-300">
      <p>
        Du bist noch in keiner Orga. Die App erkennt deine Orga über euren Discord-Server: Du
        musst dort Mitglied sein und die Mitgliedsrolle haben, und ein Admin eures Servers muss
        die Orga einmal mit dem Discord-Bot (/einrichten) anlegen.
      </p>
      <div class="flex flex-wrap gap-3">
        <form method="post" action="/recheck">
          <input type="hidden" name="_csrf" value="<?= e($viewer->csrf ?? '') ?>">
          <button class="rounded bg-indigo-600 px-3 py-2 text-sm font-medium hover:bg-indigo-500">Mitgliedschaft jetzt prüfen</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <p class="text-sm"><a href="/hangar" class="text-indigo-400 hover:underline">Mein Hangar →</a></p>
</div>
