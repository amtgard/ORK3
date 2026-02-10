<style>
.heraldry-report-wrapper { position: relative; padding-top: 40px; }
.heraldry-toolbar {
    position: absolute;
    top: 0;
    right: 0;
    display: flex;
    gap: 6px;
    z-index: 10;
}
.heraldry-toolbar button {
    padding: 4px 10px;
    font-size: 0.8em;
    cursor: pointer;
    border: 1px solid #aaa;
    background: #f5f5f5;
    border-radius: 3px;
}
.heraldry-toolbar button:hover { background: #e0e0e0; }
.heraldry-toolbar button.active {
    background: #4a7ab5;
    color: #fff;
    border-color: #3a5f8a;
}
.heraldry-item h3, .heraldry-item h3 a {
    color: #333;
    text-shadow: none;
}
</style>

<div class='info-container heraldry-report-wrapper skip-fold'>
    <div class='heraldry-toolbar'>
        <button onclick='heraldryExpandAll()'>Expand All</button>
        <button onclick='heraldryCollapseAll()'>Collapse All</button>
        <button id='heraldryToggleBtn' onclick='heraldryToggleHasOnly()'>Has Heraldry Only</button>
    </div>
<?php foreach ($Heraldry as $k => $heraldry) : ?>
	<div class='info-container heraldry-item skip-fold' data-has-heraldry='<?=$heraldry['HasHeraldry'] ?>'>
		<h3><a href='<?=$heraldry['Url'] ?>'><?=$heraldry['Name'] ?></a></h3>
		<?php if (isset($heraldry['LastSignin']) && !empty($heraldry['LastSignin'])) : ?>
			<p style='font-size: 0.9em; color: #666; margin: 5px 0;'>Last signin: <?=$heraldry['LastSignin'] ?></p>
		<?php endif; ?>
		<div class='heraldry-img-wrapper'>
			<a href='<?=$heraldry['Url'] ?>'><img src='<?=$heraldry['HasHeraldry']==1?$heraldry['HeraldryUrl']['Url']:$Blank ?>' class='heraldry-img' /></a>
		</div>
	</div>
<?php endforeach; ?>
</div>

<script>
(function () {
    var heraldryOnlyActive = false;

    window.heraldryExpandAll = function () {
        document.querySelectorAll('.heraldry-img-wrapper').forEach(function (el) {
            el.style.display = '';
        });
    };

    window.heraldryCollapseAll = function () {
        document.querySelectorAll('.heraldry-img-wrapper').forEach(function (el) {
            el.style.display = 'none';
        });
    };

    window.heraldryToggleHasOnly = function () {
        heraldryOnlyActive = !heraldryOnlyActive;
        var btn = document.getElementById('heraldryToggleBtn');
        btn.classList.toggle('active', heraldryOnlyActive);
        document.querySelectorAll('.heraldry-item').forEach(function (el) {
            if (heraldryOnlyActive && el.dataset.hasHeraldry === '0') {
                el.style.display = 'none';
            } else {
                el.style.display = '';
            }
        });
    };
}());
</script>
