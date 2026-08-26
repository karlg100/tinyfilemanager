<?php
/**
 * Dependency-free source-contract checks for the AFS replay.
 *
 * This file deliberately inspects source instead of loading
 * tinyfilemanager.php, whose top-level request dispatcher would execute.
 */

$root = dirname(__DIR__);
$managerPath = $root . '/tinyfilemanager.php';
$afsPath = $root . '/afs.php';
$manager = @file_get_contents($managerPath);
$afs = @file_get_contents($afsPath);

if ($manager === false || $afs === false) {
    fwrite(STDERR, "FAIL: unable to read tinyfilemanager.php and afs.php\n");
    exit(2);
}

$afsTestFailures = array();
$afsTestPasses = 0;

function afs_test_ok($condition, $message)
{
    global $afsTestFailures, $afsTestPasses;

    if ($condition) {
        $afsTestPasses++;
        echo "PASS: " . $message . "\n";
        return;
    }

    $afsTestFailures[] = $message;
    echo "FAIL: " . $message . "\n";
}

function afs_test_section($source, $startMarker, $endMarker, $label)
{
    $start = strpos($source, $startMarker);
    $end = $start === false ? false : strpos($source, $endMarker, $start + strlen($startMarker));

    afs_test_ok($start !== false, $label . ' start marker is present');
    afs_test_ok($end !== false && $end > $start, $label . ' end marker follows its start marker');

    if ($start === false || $end === false || $end <= $start) {
        return '';
    }

    return substr($source, $start, $end - $start);
}

function afs_test_contains($haystack, $needle, $message)
{
    afs_test_ok(strpos($haystack, $needle) !== false, $message);
}

echo "AFS static integration contract\n";

// AFS must be an explicit config.php opt-in, and the include must be resolved
// before the optional dependency is loaded.
$defaultPos = strpos($manager, '$afsSupport = false;');
$configPos = strpos($manager, '@include($config_file);');
$guardPos = strpos($manager, 'if ($afsSupport) {');
$requirePos = strpos($manager, "require_once __DIR__ . '/afs.php';");

afs_test_ok($defaultPos !== false, 'AFS support defaults to disabled');
afs_test_ok($configPos !== false, 'external config.php is included');
afs_test_ok($guardPos !== false, 'AFS dependency load is conditional');
afs_test_ok($requirePos !== false, 'AFS dependency uses an __DIR__-anchored path');
afs_test_ok(
    $defaultPos !== false && $configPos !== false && $guardPos !== false && $requirePos !== false
        && $defaultPos < $configPos && $configPos < $guardPos && $guardPos < $requirePos,
    'config.php can override the default before afs.php is required'
);

// Preserve the upstream request-token checks around every route that already
// had one. The legacy single-item GET copy route is intentionally audited in
// afs_io_path_audit.php instead of being misrepresented as CSRF-protected.
$ajax = afs_test_section($manager, '// Handle all AJAX Request', '// Delete file / folder', 'AJAX dispatcher');
afs_test_contains($ajax, "isset(\$_POST['ajax'], \$_POST['token'])", 'AJAX dispatcher requires a token field');
$ajaxVerify = strpos($ajax, "verifyToken(\$_POST['token'])");
$ajaxAction = strpos($ajax, '// save editor file');
afs_test_ok(
    $ajaxVerify !== false && $ajaxAction !== false && $ajaxVerify < $ajaxAction,
    'AJAX token is verified before request-specific actions'
);

$csrfSections = array(
    'single delete' => afs_test_section($manager, '// Delete file / folder', '// Create a new file/folder', 'single-delete route'),
    'create' => afs_test_section($manager, '// Create a new file/folder', '// Copy folder / file', 'create route'),
    'single copy/move' => afs_test_section($manager, '// Complete a single copy/move', '// Mass copy files/ folders', 'single-copy route'),
    'mass copy/move' => afs_test_section($manager, '// Mass copy files/ folders', '// Rename', 'mass-copy route'),
    'rename' => afs_test_section($manager, '// Rename', '// Download', 'rename route'),
    'download' => afs_test_section($manager, '// Download', '// Upload', 'download route'),
    'upload' => afs_test_section($manager, '// Upload', '// Mass deleting', 'upload route'),
    'mass delete' => afs_test_section($manager, '// Mass deleting', '// Pack files zip, tar', 'mass-delete route'),
    'archive create' => afs_test_section($manager, '// Pack files zip, tar', '// Unpack zip, tar', 'archive-create route'),
    'archive extract' => afs_test_section($manager, '// Unpack zip, tar', '// Change POSIX permissions', 'archive-extract route'),
    'POSIX chmod' => afs_test_section($manager, '// Change POSIX permissions', '// Change AFS ACLs', 'POSIX-chmod route'),
    'AFS ACL write' => afs_test_section($manager, '// Change AFS ACLs', '/*************************** ACTIONS', 'AFS-ACL-write route')
);

foreach ($csrfSections as $label => $section) {
    afs_test_contains($section, "verifyToken(\$_POST['token'])", $label . ' preserves token verification');
}

// This was a pre-existing upstream GET mutation, not an AFS replay change.
// Completion must remain a token-verified POST while GET is navigation-only.
$singleCopy = $csrfSections['single copy/move'];
afs_test_contains(
    $singleCopy,
    "isset(\$_POST['copy'], \$_POST['finish'], \$_POST['token'])",
    'single-copy completion requires POST fields and a CSRF token'
);
afs_test_ok(
    strpos($singleCopy, "\$_GET['finish']") === false,
    'single-copy completion has no mutating GET finish route'
);
$singleCopyVerify = strpos($singleCopy, "verifyToken(\$_POST['token'])");
$singleCopyMutation = strpos($singleCopy, 'fm_rename(');
afs_test_ok(
    $singleCopyVerify !== false && $singleCopyMutation !== false
        && $singleCopyVerify < $singleCopyMutation,
    'single-copy token verification precedes copy or move mutation'
);

$singleCopyUi = afs_test_section(
    $manager,
    "// copy form\nif (isset(\$_GET['copy'])",
    "if (isset(\$_GET['settings'])",
    'single-copy navigation form'
);
afs_test_contains($singleCopyUi, 'method="post"',
    'single-copy completion UI submits by POST');
afs_test_contains($singleCopyUi, 'name="token"',
    'single-copy completion UI submits the session token');
afs_test_contains($singleCopyUi, 'name="copy"',
    'single-copy completion UI submits the source path');
afs_test_contains($singleCopyUi, 'name="finish" value="1"',
    'single-copy completion UI submits the completion marker');
afs_test_contains($singleCopyUi, 'name="move" value="1"',
    'single-copy completion UI distinguishes move from copy');
afs_test_ok(
    strpos($singleCopyUi, '&amp;finish=1') === false,
    'single-copy completion UI emits no state-changing GET links'
);

$verifyFunction = afs_test_section($manager, 'function verifyToken($token)', 'function fm_rdelete($path)', 'verifyToken function');
afs_test_contains($verifyFunction, 'hash_equals(', 'token comparison remains timing-safe');

// Preserve exclusion behavior added upstream: configured exact names, wildcard
// extensions, and full paths all remain excluded from listing/view/edit.
afs_test_contains(
    $manager,
    "version_compare(PHP_VERSION, '7.0.0', '<') ? serialize(\$exclude_items) : \$exclude_items",
    'FM_EXCLUDE_ITEMS preserves the PHP 5 serialization compatibility path'
);
$excludeFunction = afs_test_section($manager, 'function fm_is_exclude_items($name, $path)', 'function fm_get_translations($tr)', 'exclusion helper');
afs_test_contains($excludeFunction, 'in_array($name, $exclude_items)', 'exclusion helper checks exact names');
afs_test_contains($excludeFunction, 'in_array("*.$ext", $exclude_items)', 'exclusion helper checks wildcard extensions');
afs_test_contains($excludeFunction, 'in_array($path, $exclude_items)', 'exclusion helper checks full paths');
afs_test_ok(
    substr_count($manager, 'fm_is_exclude_items(') >= 6,
    'listing, view, and edit paths retain exclusion checks'
);
afs_test_contains($manager, "strpbrk(\$text, '/?%*:|\"<>' . chr(0))", 'current null-byte filename rejection is preserved');

// ACL write handling must round-trip both lists. Accept either two explicit
// branches or a normal=>false/negative=>true mode map feeding the batched
// negative-mode argument.
$aclSubmit = $csrfSections['AFS ACL write'];
$readBeforeChange = strpos($aclSubmit, '$afs->readAcl($aclPath)');
$changeCall = strpos($aclSubmit, '$afs->changeAclEntries(');
afs_test_ok(
    $readBeforeChange !== false && $changeCall !== false && $readBeforeChange < $changeCall,
    'ACL writes re-read current inheritance state before fs setacl'
);
afs_test_contains($aclSubmit, "!empty(\$currentAcl['inherited'])",
    'ACL writes revalidate inherited state server-side');
afs_test_contains($aclSubmit, '$breakInheritance',
    'inherited ACL writes require an explicit break-inheritance intent');
afs_test_contains($aclSubmit, '$aclIsFile',
    'break and restore inheritance operations are restricted to files');
afs_test_contains($aclSubmit, '$afs->restoreAclInheritance($aclPath)',
    'restore-inheritance POST uses the typed AFS provider method');
$mappedAclSets = preg_match('/[\'\"]normal[\'\"]\s*=>\s*false.*[\'\"]negative[\'\"]\s*=>\s*true/s', $aclSubmit) === 1;
$dynamicAclLookup = strpos($aclSubmit, '$_POST[$setName]') !== false;
afs_test_ok(
    strpos($aclSubmit, "\$_POST['normal']") !== false || ($mappedAclSets && $dynamicAclLookup),
    'positive ACL submissions are handled'
);
afs_test_ok(
    strpos($aclSubmit, "\$_POST['negative']") !== false || ($mappedAclSets && $dynamicAclLookup),
    'negative ACL submissions are handled'
);
$sentinelIgnored = strpos($aclSubmit, "unset(\$perms['acl'])") !== false
    || (strpos($aclSubmit, '$allowedRights') !== false
        && strpos($aclSubmit, 'isset($perms[$right])') !== false);
afs_test_ok($sentinelIgnored, 'ACL empty-rights sentinel is excluded from assembled rights');
$emptyBecomesNone = strpos($aclSubmit, "empty(\$perms) ? 'none'") !== false
    || preg_match('/\$newAcl\s*=\s*\$newAcl\s*==={0,1}\s*[\'\"]{2}\s*\?\s*[\'\"]none[\'\"]/', $aclSubmit) === 1;
afs_test_ok($emptyBecomesNone, 'clearing every right is translated to fs none');

$directNegativeCall = preg_match('/changeAclEntries\s*\([^;]*,\s*true\s*\)/s', $aclSubmit) === 1;
$modeMap = $mappedAclSets;
$variableNegativeCall = preg_match('/changeAclEntries\s*\([^;]*,\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)/s', $aclSubmit) === 1;
afs_test_ok(
    $directNegativeCall || ($modeMap && $variableNegativeCall),
    'negative ACL writes pass true to changeAcl negative mode'
);
afs_test_contains($aclSubmit, '$aclBatches', 'ACL entries are batched by positive/negative set');

$aclUi = afs_test_section($manager, '// Edit AFS ACLs', '// --- TINYFILEMANAGER MAIN ---', 'AFS ACL editor');
afs_test_contains($aclUi, '$afs->readAcl($file_path)', 'ACL editor reads the current AFS ACL');
afs_test_contains($aclUi, "!empty(\$mode['inherited'])", 'ACL editor detects inherited AuriStor ACLs');
afs_test_contains($aclUi,
    "<fieldset id=\"acl-permission-fields\"<?php echo (\$acl_readonly || \$acl_inherited) ? ' disabled' : ''; ?>",
    'unreadable and initially inherited ACL controls are disabled');
afs_test_contains($aclUi, 'name="break_inheritance" value="0"',
    'inherited ACL editor starts with a fail-closed break flag');
afs_test_contains($aclUi,
    "document.getElementById('acl-permission-fields').disabled=false",
    'make-specific button enables the current inherited ACL matrix');
afs_test_contains($aclUi, 'name="restore_inheritance" value="1"',
    'file-specific ACL editor offers restoration of inheritance');

$rights = array('l', 'r', 'w', 'i', 'd', 'k', 'a',
    'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H');
$aclTypes = array('normal', 'negative');
foreach ($aclTypes as $aclType) {
    foreach ($rights as $right) {
        $pattern = '/name="' . preg_quote($aclType, '/') . '\[[^"]+\]\[' . preg_quote($right, '/') . '\]"/';
        afs_test_ok(
            preg_match($pattern, $aclUi) === 1,
            $aclType . ' ACL editor exposes the ' . $right . ' right'
        );
    }
}

afs_test_ok(
    preg_match('/name="normal\[[^"]+\]\[acl\]"/', $aclUi) === 1,
    'positive ACL rows post an empty-rights sentinel'
);
afs_test_ok(
    preg_match('/name="negative\[[^"]+\]\[acl\]"/', $aclUi) === 1,
    'negative ACL rows post an empty-rights sentinel'
);

$lockRows = 0;
foreach (preg_split('/\r?\n/', $aclUi) as $line) {
    if (strpos($line, '][k]"') === false) {
        continue;
    }
    $lockRows++;
    afs_test_ok(
        strpos($line, "\$perms['k']") !== false && strpos($line, "\$perms['l']") === false,
        'lock checkbox state is derived from the k right'
    );
}
afs_test_ok($lockRows === 2, 'both positive and negative ACL tables contain one lock row');

// Constructing an Afs object must not query caller access. Directory listings
// collect eligible paths first and dispatch one batch.
$constructor = afs_test_section($afs, 'public function __construct', 'public function getType', 'Afs constructor');
afs_test_ok(
    substr_count($constructor, 'getACLAccess(') === 0,
    'Afs construction does not perform an implicit getcalleraccess query'
);
afs_test_contains($constructor, '@stat( $this->afsRoot )',
    'Afs construction stats the native or configured AFS root');
afs_test_contains($constructor, '$this->afsRoot = $guard[\'root\']',
    'Afs construction adopts the canonical configured root guard');
afs_test_ok(strpos($constructor, "@stat( '/' )") === false,
    'Afs construction does not stat the open_basedir-inaccessible filesystem root');
afs_test_contains($constructor, 'fm_guard_mountinfo_records()',
    'Afs construction reads the fail-closed mountinfo records');
afs_test_contains($constructor, '$this->afsRoot, $records',
    'Afs construction requires an allowlisted AFS filesystem covering its exact root');
afs_test_ok(strpos($constructor, 'error_log(') === false,
    'Afs constructor failure paths do not call disabled error_log');

$runFs = afs_test_section($afs, 'protected function runFs', 'public function get_foldercontents_js', 'AFS command runner');
afs_test_contains($runFs, "getenv( 'AURISTOR_AFS_ACL_BROKER' ) === '1'",
    'container ACL operations prefer the private request broker');
afs_test_contains($runFs, '$this->runBrokerFs( $arguments )',
    'container ACL operations use the typed broker client');
afs_test_contains($runFs, "getenv( 'AURISTOR_AFS_ACL_BROKER_DIALECT' )",
    'typed container ACL operations require an explicit provider dialect');
afs_test_contains($runFs, "!== 'auristor'",
    'the typed broker contract is explicitly AuriStor-only');
$brokerClient = afs_test_section($afs, 'private static function brokerWait', 'private function runBrokerFs', 'typed ACL broker client');
afs_test_contains($afs, "function_exists( 'hrtime' )",
    'broker frames fail closed without a monotonic PHP clock');
afs_test_contains($brokerClient, '$now = hrtime( true );',
    'broker frames use a monotonic absolute PHP deadline');
afs_test_ok(strpos($brokerClient, 'microtime(') === false,
    'broker frame deadlines never use the adjustable wall clock');
afs_test_contains($brokerClient, 'self::$aclBrokerRequests >= 128',
    'broker operations retain an explicit per-request frame budget');
afs_test_contains($brokerClient,
    "function_exists( 'fm_open_afs_acl_broker_stream' )",
    'CGI broker transport requires the deployment-provided stream factory');
afs_test_contains($brokerClient,
    'self::$aclBrokerStream = @fm_open_afs_acl_broker_stream();',
    'CGI broker transport calls only the reviewed stream factory');
afs_test_contains($brokerClient,
    '@stream_set_blocking( self::$aclBrokerStream, false )',
    'CGI broker transport is nonblocking');
afs_test_contains($brokerClient,
    '@stream_set_read_buffer(',
    'CGI broker transport disables userspace read prefetch');
afs_test_contains($brokerClient, '@stream_select(',
    'CGI broker I/O uses deadline-bounded descriptor readiness');
afs_test_ok(strpos($brokerClient, 'stream_set_timeout') === false,
    'CGI broker transport does not rely on socket-only stream timeouts');
afs_test_ok(strpos($brokerClient, 'php://fd/') === false,
    'CGI broker transport does not use the CLI-only php fd wrapper');
afs_test_contains($runFs, "function_exists( 'exec' )",
    'native AFS command fallback checks whether exec is available');
afs_test_contains($runFs, '$this->lastFsStatus = 126;',
    'AFS command runner records a fail-closed unavailable-shell status');
foreach (array(
    "self::brokerRequest( 1, 0, \$payload )",
    "self::brokerRequest( 2, 0, \$path )",
    "self::brokerRequest( 3, \$negative ? 1 : 0, \$payload )",
    "self::brokerRequest( 4, 0, \$payload )",
    "self::brokerRequest( 5, 0, \$path )",
    "pack( 'a4CCCCN', 'AACL', 1, \$operation, \$flags, 0,"
) as $brokerContract) {
    afs_test_contains($afs, $brokerContract,
        'typed ACL broker contract is present: ' . $brokerContract);
}

$folderListing = afs_test_section($manager, '$ii = 3399;', '$ik = 8002;', 'folder-listing loop');
$fileListing = afs_test_section($manager, '$ik = 8002;', 'if (empty($folders) && empty($files))', 'file-listing loop');
$listingPrep = afs_test_section($manager, '// --- TINYFILEMANAGER MAIN ---', '$metadata_column_count', 'main listing metadata preparation');
$inheritanceEndpoint = afs_test_section($manager,
    '// Fetch file-inheritance indicators separately from the main directory page.',
    '// upload form', 'asynchronous inheritance endpoint');
afs_test_ok(substr_count($folderListing, '->getACLAccess(') === 0,
    'folder rows consume prefetched caller access without subprocesses');
afs_test_ok(substr_count($fileListing, '->getACLAccess(') === 0,
    'file rows consume prefetched caller access without subprocesses');
afs_test_ok(substr_count($manager, '->getACLAccessBatch(') === 1,
    'Tiny File Manager has one batched caller-access call site');
afs_test_ok(substr_count($manager, '->getACLInheritanceBatch(') === 1,
    'Tiny File Manager has one batched inheritance-state call site');
afs_test_ok(strpos($listingPrep, '->getACLInheritanceBatch(') === false,
    'main listing permission rendering never waits on inheritance metadata');
afs_test_contains($inheritanceEndpoint, '->getACLInheritanceBatch($inheritancePaths)',
    'the separate read-only endpoint batches inheritance metadata');
afs_test_contains($inheritanceEndpoint, "header('Cache-Control: no-store')",
    'inheritance metadata is never cached');
afs_test_contains($inheritanceEndpoint, 'exit;',
    'inheritance metadata exits before the main permission listing');
afs_test_contains($fileListing, 'data-afs-inheritance-file=',
    'file permission cells expose an encoded icon target');
afs_test_contains($manager, "fetch(inheritanceUrl, {",
    'inheritance icons load only after the permission listing is complete');
afs_test_contains($manager, "icon.setAttribute('aria-label', inheritedTitle)",
    'asynchronous inherited-permission icons retain an accessible tooltip');
afs_test_contains($manager, '// Permission text is already complete; omit only the icon.',
    'inheritance metadata failure cannot blank rendered permissions');
afs_test_contains($manager, '$enable_afs_acl_operations = true;',
    'native AFS deployments retain ACL operations unless config disables them');
afs_test_contains($manager,
    '$afsSupport && $enable_afs_acl_operations && !FM_IS_WIN && !$hide_Cols',
    'disabled ACL operations do not invoke the batch');
afs_test_contains($manager,
    '$afsSupport && $enable_afs_acl_operations' . "\n"
        . "    && isset(\$_POST['chmod']",
    'AFS ACL mutation route requires the deployment feature gate');
afs_test_contains($manager,
    '$afsSupport && $enable_afs_acl_operations' . "\n"
        . "    && isset(\$_GET['chmod']",
    'AFS ACL editor route requires the deployment feature gate');

echo "SUMMARY: " . $afsTestPasses . " passed, " . count($afsTestFailures) . " failed\n";
if (!empty($afsTestFailures)) {
    exit(1);
}

exit(0);
