<?php

declare(strict_types=1);

define('ORK3_ROOT', dirname(__DIR__, 3));

require_once ORK3_ROOT . '/vendor/autoload.php';

require_once ORK3_ROOT . '/tools/ork-db/lib/Json5.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/ValidationException.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/TierRefusalException.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/Wiring.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/DeploymentTier.php';
require_once ORK3_ROOT . '/tools/ork-db/Validate.php';
require_once ORK3_ROOT . '/tools/ork-db/Extract.php';
require_once ORK3_ROOT . '/tools/ork-db/Render.php';
require_once ORK3_ROOT . '/tools/ork-db/Init.php';
require_once ORK3_ROOT . '/tools/ork-db/Apply.php';
