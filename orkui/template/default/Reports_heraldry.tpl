<div class='info-container'>
<?php foreach ($Heraldry as $k => $heraldry) : ?>
	<div class='info-container'>
		<h3><a href='<?=$heraldry['Url'] ?>'><?=$heraldry['Name'] ?></a></h3>
		<?php if (isset($heraldry['LastSignin']) && !empty($heraldry['LastSignin'])) : ?>
			<p style='font-size: 0.9em; color: #666; margin: 5px 0;'>Last signin: <?=$heraldry['LastSignin'] ?></p>
		<?php endif; ?>
		<a href='<?=$heraldry['Url'] ?>'><img src='<?=$heraldry['HasHeraldry']==1?$heraldry['HeraldryUrl']['Url']:$Blank ?>' class='heraldry-img' /></a>
	</div>
<?php endforeach; ?>
</div>