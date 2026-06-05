<?php if (!empty($Error)) { ?>
  <div class="rm-error"><?= htmlspecialchars($Error) ?></div>
<?php return; } ?>
<h1>Recommendations Manager — <?= htmlspecialchars($LocationName) ?></h1>
<p>Scope: <?= htmlspecialchars($Context) ?> (kingdom <?= (int)$KingdomId ?>, park <?= (int)$ParkId ?>)</p>
