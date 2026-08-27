<?php

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "invalid configuration-validator invocation\n");
    exit(2);
}

$use_auth = null;
$auth_remote_user = null;
$auth_users = null;
$readonly_users = null;
$directories_users = null;
$global_readonly = null;
$root_path = null;
$root_url = null;
$path_display_mode = null;
$container_tls_proxy = null;
$online_viewer = null;
$settings_enabled = null;
$direct_links_enabled = null;
$raw_previews_enabled = null;
$url_upload_enabled = null;
$archive_enabled = null;
$chmod_enabled = null;
$symlinks_enabled = null;

$constantsBefore = get_defined_constants();
ob_start();
include $argv[1];
$output = ob_get_clean();
$constantsAfter = get_defined_constants();

$errors = array();
if ($output !== '') $errors[] = 'config.php must not produce output';
if ($use_auth !== true || $auth_remote_user !== true || $auth_users !== array()) {
    $errors[] = 'Apache REMOTE_USER must be the only authentication source';
}
if (!is_array($readonly_users)) $errors[] = 'readonly_users must be an array';
if (!is_bool($global_readonly)) $errors[] = 'global_readonly must be a boolean';
if ($directories_users !== array()) $errors[] = 'per-user data roots are not supported';
if ($root_path !== '/srv/tinyfilemanager/data' || $root_url !== '') {
    $errors[] = 'the managed root must remain fixed and outside DocumentRoot';
}
if ($path_display_mode !== 'relative') $errors[] = 'path display mode must remain relative';

$allowedConstants = array(
    'FM_ROOT_URL' => '',
    'FM_SELF_URL' => '/index.php',
    'FM_ARCHIVE_ENABLED' => false,
    'FM_CHMOD_ENABLED' => false,
    'FM_SYMLINKS_ENABLED' => false,
);
$newConstants = array_diff_key($constantsAfter, $constantsBefore);
foreach ($newConstants as $name => $value) {
    if (!array_key_exists($name, $allowedConstants)
        || $value !== $allowedConstants[$name]) {
        $errors[] = 'config.php defined an unsupported constant';
    }
}
foreach ($allowedConstants as $name => $value) {
    if (!defined($name) || constant($name) !== $value) {
        $errors[] = 'config.php must define the fixed AFS safety constants';
    }
}

$secureCookie = filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN);
if ($container_tls_proxy !== true || $secureCookie !== true) {
    $errors[] = 'the AFS profile requires HTTPS and Secure session cookies';
}
$requiredSessionSettings = array(
    'session.use_only_cookies' => '1',
    'session.use_strict_mode' => '1',
    'session.use_trans_sid' => '0',
    'session.cookie_httponly' => '1',
    'session.cookie_samesite' => 'Lax',
);
foreach ($requiredSessionSettings as $name => $value) {
    if ((string) ini_get($name) !== $value) {
        $errors[] = 'required session hardening was changed';
    }
}
if ($online_viewer !== false
    || $settings_enabled !== false
    || $direct_links_enabled !== false
    || $raw_previews_enabled !== false
    || $url_upload_enabled !== false
    || $archive_enabled !== false
    || $chmod_enabled !== false
    || $symlinks_enabled !== false) {
    $errors[] = 'unsafe network, archive, chmod, symlink, and direct-access features must remain disabled';
}

if ($errors !== array()) {
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, "tinyfilemanager-afs: $error\n");
    }
    exit(1);
}
