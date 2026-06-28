<?php

// tests/cms-theme/tokens_test.php — run: php tests/cms-theme/tokens_test.php
require __DIR__ . '/../../system/lib/ork3/class.CmsThemeTokens.php';

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label\n";
        $fails++;
    }
}

// --- Catalog / defaults ---
$def = CmsThemeTokens::Defaults();
check('Defaults has --fd-primary', isset($def['--fd-primary']));
check('primary default is navy', ($def['--fd-primary']['value'] ?? null) === '#0b1120');
check('DefaultValues flattens', CmsThemeTokens::DefaultValues()['--fd-accent'] === '#f0b429');
check('font allowlist has Open Sans', in_array('Open Sans', CmsThemeTokens::FontAllowlist(), true));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
