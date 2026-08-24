#!/usr/bin/env php
<?php

define('FM_IS_WIN', DIRECTORY_SEPARATOR === '\\');
define('FM_ROOT_GUARD_ALLOW_AFS_DEVICE_TRANSITIONS', true);
require_once dirname(__DIR__) . '/lib/fm_root_confinement.php';

$checks = 0;

function afs_multidevice_check($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "not ok $checks - $message\n");
        exit(1);
    }
    echo "ok $checks - $message\n";
}

function afs_multidevice_remove_tree($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
            afs_multidevice_remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

$sandbox = sys_get_temp_dir() . '/tinyfm-afs-multidevice-' . bin2hex(random_bytes(6));
$root = $sandbox . '/root';
$outside = $sandbox . '/outside';
mkdir($root, 0700, true);
mkdir($outside, 0700, true);
mkdir($root . '/nested-mount', 0700);
file_put_contents($root . '/inside.txt', 'inside');
file_put_contents($outside . '/secret', 'outside');

try {
    $canonicalRoot = fm_guard_init($root);
    $rootDevice = stat($canonicalRoot)['dev'];
    $simulatedVolumeDevice = $rootDevice === PHP_INT_MAX
        ? $rootDevice - 1 : $rootDevice + 1;

    afs_multidevice_check(
        fm_guard_device_is_allowed($canonicalRoot . '/volume/file',
            $simulatedVolumeDevice),
        'explicit AFS mode accepts a simulated descendant st_dev transition');
    afs_multidevice_check(
        !fm_guard_device_is_allowed($canonicalRoot, $simulatedVolumeDevice),
        'explicit AFS mode still requires the configured root device');
    afs_multidevice_check(
        fm_guard_read($root . '/inside.txt') === 'inside',
        'explicit AFS mode preserves ordinary same-device reads');

    if (!FM_IS_WIN && function_exists('symlink')) {
        symlink($outside . '/secret', $root . '/escaping-link');
        afs_multidevice_check(
            fm_guard_existing($root . '/escaping-link', 'file') === false,
            'explicit AFS mode still rejects a canonical symlink escape');
        $listing = fm_guard_scandir($root);
        afs_multidevice_check(is_array($listing)
            && !in_array('escaping-link', $listing, true),
            'explicit AFS mode still omits an escaping link from listings');
    }

    $GLOBALS['FM_ROOT_GUARD_MOUNTINFO'] =
        '101 1 0:42 / ' . $root . '/nested-mount rw - tmpfs tmpfs rw' . "\n";
    afs_multidevice_check(
        fm_guard_existing($root . '/nested-mount', 'dir') === false,
        'explicit AFS mode still rejects a nested Linux mountpoint');
} finally {
    unset($GLOBALS['FM_ROOT_GUARD_MOUNTINFO']);
    afs_multidevice_remove_tree($sandbox);
}

echo "PASS: $checks AFS multi-device confinement checks\n";
