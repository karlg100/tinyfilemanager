<?php

// Liveness only. This verifies the in-process PHP SAPI selected by the private
// provider, but deliberately does not traverse AFS or claim token readiness.
$healthy = PHP_SAPI === 'apache2handler';
$requiredExtensions = array(
    'ctype', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'mbstring',
    'openssl', 'pcre', 'posix', 'session', 'SPL', 'standard', 'zip', 'zlib',
);
foreach ($requiredExtensions as $extension) {
    $healthy = $healthy && extension_loaded($extension);
}

$lockedVersion = null;
$lockLines = @file('/usr/share/tinyfilemanager-auristor/provider.lock',
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (is_array($lockLines)) {
    foreach ($lockLines as $line) {
        if (strpos($line, 'php_version=') === 0) {
            $lockedVersion = substr($line, strlen('php_version='));
        }
    }
}
$healthy = $healthy && is_string($lockedVersion)
    && hash_equals($lockedVersion, PHP_VERSION);

http_response_code($healthy ? 204 : 500);
