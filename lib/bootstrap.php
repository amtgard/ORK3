<?php

$bootstrap = new DirectoryIterator(ROOT.'bootstrap');

$files = [];
foreach ($bootstrap as $script) {
    if ($script->isDir() || $script->isDot() || substr($script->getFileName(), -4) != '.php') {
        continue;
    }
    $parts = explode('_', $script->getFileName());
    if (is_numeric($parts[0])) {
        $path = $script->getPathname();
        $files[] = $path;
    }
}
sort($files);
foreach ($files as $file) {
    include_once $file;
}
