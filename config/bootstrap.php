<?php

declare(strict_types=1);

require_once __DIR__ . '/../system/lib/system/class.Ork3Bootstrap.php';

/**
 * @return list<array{level: string, event: string, detail?: string}>
 */
function ork3_bootstrap_get_trace(): array
{
    return Ork3BootstrapConfig::getTrace();
}

Ork3BootstrapConfig::boot();
