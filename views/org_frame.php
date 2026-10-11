<?php
/** @var array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool} $org */
/** @var string $inner */
/** @var string $active */
/** @var bool|null $wide */
// Die Navigation steckt im Kopf der Seite (Logo = Startseite, Avatar-Menü); hier folgt nur der Inhalt.
?>
<div class="space-y-6">
  <?= $inner ?>
</div>
