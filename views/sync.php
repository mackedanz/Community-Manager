<?php
/** @var ?\DateTimeImmutable $lastSync */
/** @var string $bookmarklet */
use Hangar\Constants;

$link = 'text-indigo-400 hover:underline';
$kbd = 'rounded bg-zinc-800 px-1.5 py-0.5';
?>
<div class="max-w-2xl space-y-8">
  <div>
    <h1 class="text-2xl font-bold">Hangar mit RSI synchronisieren</h1>
    <p class="mt-1 text-sm text-zinc-400">
      Ein Lesezeichen liest deine Pledges aus, während du auf robertsspaceindustries.com
      angemeldet bist, und übergibt sie hierher. Jeder Sync ersetzt die von RSI übernommenen
      Einträge; manuell hinzugefügte Schiffe bleiben erhalten. Du brauchst dafür keine
      Erweiterung.
    </p>
    <p class="mt-2 text-sm text-zinc-300">Zuletzt synchronisiert: <b><?= $lastSync ? e(dt($lastSync)) : 'noch nie' ?></b></p>
  </div>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Teil 1 – Einmalig: Lesezeichen anlegen</h2>
    <p class="text-sm text-zinc-400">Das machst du nur beim ersten Mal (und wieder, wenn sich das Lesezeichen ändert).</p>
    <ol class="list-decimal space-y-3 pl-5 text-sm text-zinc-300">
      <li>Blende die Lesezeichenleiste deines Browsers ein, die Leiste direkt unter der Adresszeile:
        <kbd class="<?= $kbd ?>">Strg</kbd> + <kbd class="<?= $kbd ?>">Umschalt</kbd> + <kbd class="<?= $kbd ?>">B</kbd>.
        Ist sie schon sichtbar, brauchst du nichts zu tun.</li>
      <li class="space-y-2">
        <p>Ziehe den blauen Knopf unten mit gedrückter linker Maustaste in die Lesezeichenleiste und lass die Taste dort los:</p>
        <a href="<?= e($bookmarklet) ?>" draggable="true" data-bookmarklet class="inline-block cursor-grab rounded border-2 border-dashed border-indigo-400 bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">↻ Hangar-Sync</a>
        <p class="text-zinc-400">
          Nur ziehen, nicht anklicken. Danach steht in der Leiste ein Lesezeichen mit dem Namen „↻ Hangar-Sync“.
          Ein älteres Lesezeichen mit diesem Namen löschst du vorher (Rechtsklick → Löschen), sonst hast du zwei.
        </p>
      </li>
    </ol>
  </section>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Teil 2 – Bei jedem Sync</h2>
    <ol class="list-decimal space-y-3 pl-5 text-sm text-zinc-300">
      <li class="space-y-2">
        <p>Klicke hier auf diesen Knopf. Dadurch öffnet sich ein <b>neuer Tab</b> mit deinem RSI-Konto. Diese Seite hier bleibt offen und zeigt „Warte auf RSI“.</p>
        <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" data-rsi-sync class="inline-block rounded bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500">RSI-Pledge-Seite öffnen</a>
      </li>
      <li>Im neuen Tab: Melde dich bei RSI an, falls du es nicht schon bist. Du musst danach auf der Seite <b>„My Hangar“ / „Pledges“</b> mit deiner Liste von Schiffen und Paketen stehen. Eine andere RSI-Seite (Startseite, Konto) reicht nicht.</li>
      <li>Klicke im <b>RSI-Tab</b> in der Lesezeichenleiste auf <b>„↻ Hangar-Sync“</b>. Nicht in diesem Tab, sondern im RSI-Tab.</li>
      <li>Warte, bis das Lesezeichen fertig ist. Unten rechts auf der RSI-Seite läuft ein Kasten „Community-Hangar“ mit dem Fortschritt („Lese Seite 3 …“). Das dauert je nach Hangar-Größe ein paar Sekunden bis eine Minute. Schließe den Tab in dieser Zeit nicht.</li>
      <li>Danach schließt sich der RSI-Tab von selbst, und du siehst hier im Community-Hangar eine <b>Vorschau</b> mit allen erkannten Schiffen und Gegenständen. Wurde nichts erkannt oder fehlt etwas, brich mit „Abbrechen“ ab. Es wird nichts verändert.</li>
      <li>Stimmt die Vorschau, klicke auf <b>„In meinen Hangar übernehmen“</b>. Erst jetzt wird dein Hangar aktualisiert, und die Seite lädt neu.</li>
    </ol>
  </section>

  <section class="space-y-2">
    <h2 class="text-lg font-semibold">Wenn etwas nicht klappt</h2>
    <ul class="list-disc space-y-2 pl-5 text-sm text-zinc-300">
      <li><b>Es öffnet sich kein neuer Tab:</b> Der Browser hat das Öffnen blockiert. Klicke rechts in der Adresszeile auf das Pop-up-Symbol, erlaube Pop-ups für diese Seite und klicke den Knopf noch einmal.</li>
      <li><b>Beim Klick auf das Lesezeichen öffnet sich nur RSI:</b> Du warst nicht auf der Pledge-Seite. Das Lesezeichen öffnet sie dann in einem neuen Tab für dich. Klicke es dort noch einmal an. Klickst du es in diesem Community-Hangar an, funktioniert es wie der Knopf oben, und die Vorschau erscheint hier.</li>
      <li><b>Im Kasten unten rechts steht ein Fehler:</b> Meist bist du bei RSI nicht angemeldet. Melde dich an, lade die Pledge-Seite neu und klicke das Lesezeichen noch einmal an.</li>
      <li><b>Hier kommt nach dem Lesen keine Vorschau:</b> Du hast diese Seite zwischendurch gewechselt oder geschlossen. Beginne mit Schritt 1 von vorn und lass diese Seite offen.</li>
    </ul>
  </section>

  <p class="text-xs text-zinc-500">
    Das Lesezeichen läuft nur, wenn du es auf deiner RSI-Pledge-Seite anklickst. Es liest nur,
    was du dort selbst siehst, entfernt Gutscheincodes und übergibt die Liste an dieses
    Seite; übernommen wird erst nach deiner Bestätigung. Deine RSI-Zugangsdaten bekommt es
    nie. Für eigene Skripte gibt es zusätzlich die <a href="/settings/api" class="<?= $link ?>">API mit persönlichen Tokens</a>.
  </p>
</div>
