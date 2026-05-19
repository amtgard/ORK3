<?php
// Amtpride Nameplate gradient definitions. Each entry maps a preset key to the
// human-readable flag name and an ordered list of hex color stops that render
// as a smooth horizontal linear-gradient on the player's hero. Order in this
// array is the display order in the Amtpride Nameplate subsection of the
// Design My Profile modal.
//
// New flags: append here and (if needed) extend the column width — keys are
// validated server-side in class.Player.php::UpdatePlayer against the keys
// of this map.

return [
	'pride6'      => ['name' => '6-Color Pride',                'colors' => ['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787']],
	'progress'    => ['name' => 'Progress Pride',               'colors' => ['#000000','#784f17','#5bcffa','#f5a9b8','#ffffff','#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787']],
	'gilbert8'    => ['name' => "Gilbert Baker's 1977 8-Color", 'colors' => ['#ff69b4','#e40303','#ff8c00','#ffed00','#008026','#00c0c0','#3f48cc','#750787']],
	'philly'      => ['name' => 'Philadelphia POC-Inclusive',   'colors' => ['#000000','#784f17','#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787']],
	'lesbian'     => ['name' => 'Lesbian',                      'colors' => ['#d62900','#ff9b55','#ffffff','#d461a6','#a50062']],
	'bisexual'    => ['name' => 'Bisexual',                     'colors' => ['#d60270','#9b4f96','#0038a8']],
	'pansexual'   => ['name' => 'Pansexual',                    'colors' => ['#ff218c','#ffd800','#21b1ff']],
	'polysexual'  => ['name' => 'Polysexual',                   'colors' => ['#f714ba','#01d66a','#1594f6']],
	'asexual'     => ['name' => 'Asexual',                      'colors' => ['#000000','#a3a3a3','#ffffff','#800080']],
	'aromantic'   => ['name' => 'Aromantic',                    'colors' => ['#3da542','#a7d379','#ffffff','#a9a9a9','#000000']],
	'transgender' => ['name' => 'Transgender',                  'colors' => ['#5bcffa','#f5a9b8','#ffffff','#f5a9b8','#5bcffa']],
	'nonbinary'   => ['name' => 'Nonbinary',                    'colors' => ['#fcf434','#ffffff','#9c59d1','#2c2c2c']],
	'genderfluid' => ['name' => 'Genderfluid',                  'colors' => ['#ff75a2','#ffffff','#be18d6','#000000','#333ebd']],
	'genderqueer' => ['name' => 'Genderqueer',                  'colors' => ['#b57edc','#ffffff','#4a8123']],
	'intersex'    => ['name' => 'Intersex',                     'colors' => ['#ffd800','#7902aa','#ffd800']],
];
