<?php
/** @var string $content */
/** @var \Hangar\Viewer|null $viewer */
/** @var bool $serverAdmin */
/** @var string|null $csrf */
/** @var bool|null $wide breiter Inhaltsbereich (Standard); false = schmale Lesespalte */
$wide = !isset($wide) || !empty($wide);   // Standard: ganze Breite; schmal nur, wenn eine Seite wide = false setzt (Text- und Anmeldeseiten)
$bare = !empty($bare);   // /pur/…: nur der Inhalt, ohne Logo, Navigation und Fußzeile
$maxW = $wide ? 'max-w-[1800px]' : 'max-w-5xl';
$brand = \Hangar\Branding::current();
$viewer = $viewer ?? null;
$serverAdmin = $serverAdmin ?? false;
$csrf = $csrf ?? '';
$title = isset($title) && $title !== '' ? $title . ' · Community-Hangar' : 'Community-Hangar';
$link = 'text-zinc-400 hover:text-zinc-100';
// Aktuelle Seite in der Navigation hervorheben (wie die Reiter im Orga-Bereich)
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$here = static fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/');
$navClass = static fn (bool $active, string $idle = 'text-zinc-400 hover:text-zinc-100', string $on = 'text-zinc-100'): string
    => $active ? $on . ' underline underline-offset-8' : $idle;
$cur = static fn (bool $active): string => $active ? ' aria-current="page"' : '';
$privacyUrl = \Hangar\Env::get('LEGAL_PRIVACY_URL');
$imprintUrl = \Hangar\Env::get('LEGAL_IMPRINT_URL');
?><!DOCTYPE html>
<html lang="de" class="h-full antialiased">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="Star-Citizen-Besitz tracken und mit der Community teilen">
<link rel="icon" type="image/png" href="<?= e($brand['logoUrl']) ?>">
<script>(function(){try{var t=localStorage.getItem("theme");if(t!=="light"&&t!=="dark"){t=window.matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"}document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme="dark"}})()</script>
<link rel="stylesheet" href="/css/app.css">
<style><?= \Hangar\Branding::css() ?></style>
</head>
<body class="flex h-dvh flex-col overflow-clip bg-zinc-950 text-zinc-100">
<?php /* Kopf (Logo, Navigation) und Fußzeile bleiben stehen, nur der Bereich dazwischen scrollt. */ ?>
<?php if (!$bare): ?>
<?php
// Aktuelle Orga: die aus der Adresse (/o/<Kürzel>/…), sonst die erste des Nutzers.
$curOrg = null;
if ($viewer && $viewer->orgs) {
    $curOrg = $viewer->orgs[0];
    foreach ($viewer->orgs as $o) {
        if ($here('/o/' . $o['slug'])) {
            $curOrg = $o;
            break;
        }
    }
}
$menuLink = 'block px-4 py-2 hover:bg-zinc-800';
$menuLabel = 'px-4 pb-1 pt-3 text-[11px] font-medium uppercase tracking-wider text-zinc-500';
?>
<header class="relative z-20 shrink-0 border-b border-cyan-900/50 bg-zinc-950/85">
  <nav class="mx-auto flex max-w-[1800px] items-center justify-between gap-4 px-4 py-2 text-sm" aria-label="Hauptmenü">
    <?php if ($curOrg): ?>
      <a href="/o/<?= e($curOrg['slug']) ?>" class="flex min-w-0 items-center gap-3 font-semibold hover:text-cyan-200" title="Zur Startseite von <?= e($curOrg['name']) ?>">
        <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $curOrg['name'], 'iconUrl' => $curOrg['iconUrl'], 'size' => 'h-9 w-9']) ?>
        <span class="truncate text-base"><?= e($curOrg['name']) ?></span>
      </a>
    <?php else: ?>
      <a href="/" class="flex items-center gap-3 font-semibold hover:text-cyan-200" title="Community-Hangar">
        <img src="<?= e($brand['logoUrl']) ?>" alt="" width="36" height="36" class="h-9 w-9">
        <span class="text-base">Community-Hangar</span>
      </a>
    <?php endif; ?>
    <?php if ($viewer): ?>
      <details class="relative" data-org-menu>
        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-full p-0.5 hover:ring-2 hover:ring-cyan-700/60 [&::-webkit-details-marker]:hidden" title="Menü von <?= e($viewer->name) ?>" aria-label="Menü">
          <?php if ($viewer->image): ?>
            <img src="<?= e($viewer->image) ?>" alt="" class="h-9 w-9 rounded-full ring-2 ring-cyan-700/60">
          <?php else: ?>
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-zinc-700 text-sm font-semibold ring-2 ring-cyan-700/60"><?= e(mb_strtoupper(mb_substr((string) $viewer->name, 0, 1))) ?></span>
          <?php endif; ?>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-64 rounded-lg border border-cyan-900/60 bg-zinc-900 py-1 shadow-xl">
          <div class="truncate px-4 py-2 font-semibold"><?= e($viewer->name) ?></div>
          <?php if ($curOrg): $base = '/o/' . $curOrg['slug']; ?>
            <div class="border-t border-zinc-800">
              <div class="<?= $menuLabel ?>"><?= e($curOrg['name']) ?></div>
              <a href="<?= e($base) ?>" class="<?= $menuLink ?>">Startseite</a>
              <a href="<?= e($base) ?>/events" class="<?= $menuLink ?>">Kalender</a>
              <a href="<?= e($base) ?>/hangar" class="<?= $menuLink ?>">Flotte</a>
              <a href="<?= e($base) ?>/members" class="<?= $menuLink ?>">Mitglieder</a>
              <?php if ($curOrg['role'] === 'ADMIN'): ?><a href="<?= e($base) ?>/settings" class="<?= $menuLink ?>">Orga verwalten</a><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (count($viewer->orgs) > 1): ?>
            <div class="border-t border-zinc-800">
              <div class="<?= $menuLabel ?>">Orga wechseln</div>
              <?php foreach ($viewer->orgs as $o): if ($curOrg && $o['id'] === $curOrg['id']) { continue; } ?>
                <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-2 px-4 py-2 hover:bg-zinc-800">
                  <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-5 w-5']) ?>
                  <span class="truncate"><?= e($o['name']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="border-t border-zinc-800">
            <div class="<?= $menuLabel ?>">Persönlich</div>
            <a href="/hangar" class="<?= $menuLink ?>">Mein Hangar</a>
            <a href="/catalog" class="<?= $menuLink ?>">Katalog</a>
            <a href="/settings" class="<?= $menuLink ?>">Einstellungen</a>
            <?php if (!empty($serverAdmin)): ?><a href="/admin" class="<?= $menuLink ?> text-amber-400 hover:text-amber-300">Server-Admin</a><?php endif; ?>
          </div>
          <div class="border-t border-zinc-800 py-1">
            <button type="button" data-theme-toggle class="<?= $menuLink ?> w-full text-left"></button>
            <form method="post" action="/logout">
              <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
              <button class="<?= $menuLink ?> w-full text-left">Abmelden</button>
            </form>
          </div>
        </div>
      </details>
    <?php else: ?>
      <span class="flex items-center gap-4">
        <button type="button" data-theme-toggle class="<?= $link ?>"></button>
        <a href="/login" class="<?= $link ?>">Anmelden</a>
      </span>
    <?php endif; ?>
  </nav>
</header>
<?php endif; ?>
<?php /* Nur der Bereich über der Fußzeile scrollt; die Fußzeile bleibt immer sichtbar. */ ?>
<div class="min-h-0 flex-1 overflow-y-auto" id="page-scroll">
<div class="flex min-h-full flex-col">
<main class="mx-auto w-full <?= $maxW ?> flex-1 px-4 py-8">
  <?php if (!empty($flash)): ?>
    <p role="status" class="mb-6 rounded border p-3 text-sm <?= $flash['t'] === 'error' ? 'border-red-700 text-red-400' : 'border-green-700 text-green-400' ?>"><?= e($flash['m']) ?></p>
  <?php endif; ?>
  <?= $content ?>
</main>
</div>
</div>
<?php if (!$bare): ?>
<footer class="shrink-0 border-t border-zinc-800 bg-zinc-950/85 py-3 text-center text-xs text-zinc-500">
  MADE BY THE COMMUNITY ·
  <?php if ($privacyUrl): ?>
    <a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Datenschutz</a>
  <?php else: ?>
    <a href="/datenschutz" class="hover:text-zinc-300">Datenschutz</a>
  <?php endif; ?>
  · <a href="/lizenz" class="hover:text-zinc-300">Lizenz</a>
  <?php if ($imprintUrl): ?>
    · <a href="<?= e($imprintUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Impressum</a>
  <?php endif; ?>
</footer>
<?php endif; ?>
<script src="/js/app.js" defer></script>
<script src="/js/sync-receive.js" defer></script>
</body>
</html>
