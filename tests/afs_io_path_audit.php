<?php
/**
 * Dependency-free AFS I/O call-site audit.
 *
 * XFAIL means the endpoint is known to bypass the guarded Afs primitives.
 * An XFAIL is successful only while the exact expected-gap structure remains.
 * If a call site changes, this audit fails so the classification must be
 * reviewed instead of silently turning a stale XFAIL into a compatibility
 * claim.
 */

$root = dirname(__DIR__);
$manager = @file_get_contents($root . '/tinyfilemanager.php');
$afs = @file_get_contents($root . '/afs.php');

if ($manager === false || $afs === false) {
    fwrite(STDERR, "FAIL: unable to read tinyfilemanager.php and afs.php\n");
    exit(2);
}

$auditFailures = array();
$auditProtected = 0;
$auditFailClosed = 0;
$auditExpectedFailures = 0;
$auditChecks = 0;

function afs_audit_result($status, $name, $detail)
{
    echo $status . ': ' . $name . ' - ' . $detail . "\n";
}

function afs_audit_require($condition, $message)
{
    global $auditChecks, $auditFailures;
    $auditChecks++;

    if ($condition) {
        return true;
    }

    $auditFailures[] = $message;
    afs_audit_result('FAIL', 'source baseline', $message);
    return false;
}

function afs_audit_section($source, $startMarker, $endMarker, $label)
{
    $start = strpos($source, $startMarker);
    $end = $start === false ? false : strpos($source, $endMarker, $start + strlen($startMarker));

    if (!afs_audit_require($start !== false, $label . ' start marker is missing')) {
        return '';
    }
    if (!afs_audit_require($end !== false && $end > $start, $label . ' end marker is missing or reordered')) {
        return '';
    }

    return substr($source, $start, $end - $start);
}

function afs_audit_unwired($source)
{
    $guardMarkers = array(
        '$afsSupport',
        'new Afs',
        'makePathAFSlocal',
        'pathSecurity',
        '->copy(',
        '->copy_dirs(',
        '->readfile(',
        '->removeFolder(',
        '->deleteFiles(',
        '->moveFiles(',
        '->afsRename('
    );

    foreach ($guardMarkers as $marker) {
        if (strpos($source, $marker) !== false) {
            return false;
        }
    }

    return true;
}

function afs_audit_matching_lines($source, $needle)
{
    $matches = array();
    foreach (preg_split('/\r?\n/', $source) as $line) {
        if (strpos($line, $needle) !== false) {
            $matches[] = $line;
        }
    }

    return implode("\n", $matches);
}

function afs_audit_protected($name, $condition, $detail)
{
    global $auditProtected, $auditChecks, $auditFailures;
    $auditChecks++;

    if (!$condition) {
        $auditFailures[] = $name . ' no longer matches its protected baseline';
        afs_audit_result('FAIL', $name, 'protected baseline changed; review required');
        return;
    }

    $auditProtected++;
    afs_audit_result('PROTECTED', $name, $detail);
}

function afs_audit_fail_closed($name, $condition, $detail)
{
    global $auditFailClosed, $auditChecks, $auditFailures;
    $auditChecks++;

    if (!$condition) {
        $auditFailures[] = $name . ' no longer matches its fail-closed baseline';
        afs_audit_result('FAIL', $name, 'fail-closed baseline changed; review required');
        return;
    }

    $auditFailClosed++;
    afs_audit_result('FAIL-CLOSED', $name, $detail);
}

function afs_audit_xfail($name, $condition, $detail)
{
    global $auditExpectedFailures, $auditChecks, $auditFailures;
    $auditChecks++;

    if (!$condition) {
        $auditFailures[] = $name . ' changed from its expected-gap baseline';
        afs_audit_result('FAIL', $name, 'expected-gap baseline changed; inspect and reclassify');
        return;
    }

    $auditExpectedFailures++;
    afs_audit_result('XFAIL', $name, $detail);
}

echo "AFS I/O path audit\n";

// Guarded primitives retained in afs.php. These checks do not imply that the
// Tiny File Manager request handlers call them.
$pathSecurity = afs_audit_section($afs, 'function pathSecurity', 'public function makePathAFSlocal', 'Afs::pathSecurity');
$makeLocal = afs_audit_section($afs, 'public function makePathAFSlocal', '// Checks to see if there is a folder', 'Afs::makePathAFSlocal');
$removeFolder = afs_audit_section($afs, 'public function removeFolder', 'public function deleteFiles', 'Afs::removeFolder');
$copyFilesPrimitive = afs_audit_section($afs, 'function copyFiles()', 'protected function copyItem', 'Afs::copyFiles');
$copyItem = afs_audit_section($afs, 'protected function copyItem', '/* A helper function for copyFiles()', 'Afs::copyItem');
$copyDirs = afs_audit_section($afs, 'public function copy_dirs', 'public function copy(', 'Afs::copy_dirs');
$copyPrimitive = afs_audit_section($afs, 'public function copy(', '// A AFS safe version of the PHP readfile', 'Afs::copy');
$readPrimitive = afs_audit_section($afs, 'function readfile()', '// Change the ACL for a given path', 'Afs::readfile');

afs_audit_fail_closed(
    'path acceptance on a non-AFS device',
    strpos($pathSecurity, '@stat( $path )') !== false
        && preg_match('/\$this->afsStat\[[\'\"]dev[\'\"]\]\s*!=\s*\$pathStat\[[\'\"]dev[\'\"]\]/', $pathSecurity) === 1
        && strpos($pathSecurity, 'return false;') !== false,
    'pathSecurity rejects a path whose st_dev differs from /afs'
);
afs_audit_fail_closed(
    'directory-local operation on a non-AFS device',
    strpos($makeLocal, '@chdir( $path )') !== false
        && preg_match('/\$this->afsStat\[[\'\"]dev[\'\"]\]\s*!=\s*\$stat\[[\'\"]dev[\'\"]\]/', $makeLocal) === 1
        && strpos($makeLocal, 'Path not in AFS') !== false
        && strpos($makeLocal, '@chdir( $this->startCWD )') !== false
        && strpos($makeLocal, 'return false;') !== false,
    'makePathAFSlocal restores cwd and rejects the device mismatch'
);
afs_audit_protected(
    'AFS file-copy primitive',
    strpos($copyPrimitive, 'fstat( $sourceHdl )') !== false
        && strpos($copyPrimitive, '$sourceStat[\'dev\'] != $this->afsStat[\'dev\']') !== false
        && substr_count($copyPrimitive, 'makePathAFSlocal(') >= 2
        && strpos($copyPrimitive, 'fopen( basename( $dest ), "xb" )') !== false,
    'source handle st_dev and destination directory are checked; overwrite is refused'
);
afs_audit_protected(
    'AFS file-read primitive',
    strpos($readPrimitive, 'fstat( $handle )') !== false
        && strpos($readPrimitive, '$stat[\'dev\'] == $this->afsStat[\'dev\']') !== false,
    'bytes are emitted only after checking the opened handle device'
);
afs_audit_protected(
    'AFS recursive delete helper',
    strpos($removeFolder, 'makePathAFSlocal( $folderPath )') !== false
        && strpos($removeFolder, '!is_link( $itemPath )') !== false
        && strpos($removeFolder, '$this->removeFolder( $itemPath )') !== false,
    'each directory is rechecked and symlinks are unlinked rather than traversed'
);
afs_audit_protected(
    'AFS recursive copy helper',
    substr_count($copyDirs, 'makePathAFSlocal(') >= 2
        && strpos($copyFilesPrimitive, 'copyItem(') !== false
        && strpos($copyDirs, 'copyItem(') !== false
        && strpos($copyDirs, 'is_link( $source )') !== false
        && strpos($copyItem, 'is_link( $source )') !== false
        && strpos($copyItem, '@filetype( $source )') !== false
        && strpos($copyItem, 'is_link( $source )')
            < strpos($copyItem, '@filetype( $source )')
        && strpos($copyPrimitive, 'if ( is_link( $source ))') !== false
        && strpos($copyPrimitive, 'readlink( $name )') !== false,
    'copyFiles and copy_dirs dispatch links before directory checks, then reproduce them'
);

// Snapshot the request handlers and generic filesystem helpers.
$save = afs_audit_section($manager, '// save editor file', '// backup files', 'save route');
$backup = afs_audit_section($manager, '// backup files', '// Save Config', 'backup route');
$urlUpload = afs_audit_section($manager, '//upload using url', "    exit();\n}", 'URL-upload route');
$deleteRoute = afs_audit_section($manager, '// Delete file / folder', '// Create a new file/folder', 'single-delete route');
$createRoute = afs_audit_section($manager, '// Create a new file/folder', '// Copy folder / file', 'create route');
$copyRoute = afs_audit_section($manager, '// Copy folder / file', '// Mass copy files/ folders', 'single-copy route');
$massCopyRoute = afs_audit_section($manager, '// Mass copy files/ folders', '// Rename', 'mass-copy route');
$renameRoute = afs_audit_section($manager, '// Rename', '// Download', 'rename route');
$downloadRoute = afs_audit_section($manager, '// Download', '// Upload', 'download route');
$uploadRoute = afs_audit_section($manager, '// Upload', '// Mass deleting', 'upload route');
$massDeleteRoute = afs_audit_section($manager, '// Mass deleting', '// Pack files zip, tar', 'mass-delete route');
$archiveCreateRoute = afs_audit_section($manager, '// Pack files zip, tar', '// Unpack zip, tar', 'archive-create route');
$archiveExtractRoute = afs_audit_section($manager, '// Unpack zip, tar', '// Change POSIX permissions', 'archive-extract route');
$viewer = afs_audit_section($manager, '// file viewer', '// file editor', 'file-view route');
$listing = afs_audit_section($manager, '// --- TINYFILEMANAGER MAIN ---', '// --- END HTML ---', 'main listing');
$directLinks = afs_audit_matching_lines($listing, "lng('DirectLink')");

$deleteHelper = afs_audit_section($manager, 'function fm_rdelete($path)', 'function fm_rchmod', 'fm_rdelete');
$renameHelper = afs_audit_section($manager, 'function fm_rename($old, $new)', 'function fm_rcopy', 'fm_rename');
$recursiveCopy = afs_audit_section($manager, 'function fm_rcopy($path, $dest', 'function fm_mkdir', 'fm_rcopy');
$mkdirHelper = afs_audit_section($manager, 'function fm_mkdir($dir, $force)', 'function fm_copy', 'fm_mkdir');
$copyHelper = afs_audit_section($manager, 'function fm_copy($f1, $f2, $upd)', 'function fm_get_mime_type', 'fm_copy');
$downloadHelper = afs_audit_section($manager, 'function fm_download_file(', 'class FM_Zipper', 'fm_download_file');
$archiveHelpers = afs_audit_section($manager, 'class FM_Zipper', '//--- Templates Functions ---', 'archive helper classes');

afs_audit_xfail(
    'save/edit writes',
    afs_audit_unwired($save)
        && strpos($save, 'fopen($file_path, "w")') !== false
        && strpos($save, '@fwrite($fd, $writedata)') !== false,
    'AJAX save writes the resolved path directly without an AFS handle/device guard'
);
afs_audit_xfail(
    'backup writes',
    afs_audit_unwired($backup)
        && strpos($backup, 'copy($fullyQualifiedFileName, $fullPath . $newFileName)') !== false,
    'backup uses PHP copy directly and can follow a same-root symlink outside AFS'
);
afs_audit_xfail(
    'file and directory creation',
    afs_audit_unwired($createRoute)
        && strpos($createRoute, "@fopen(\$path . '/' . \$new, 'w')") !== false
        && strpos($createRoute, "fm_mkdir(\$path . '/' . \$new, false)") !== false
        && afs_audit_unwired($mkdirHelper)
        && strpos($mkdirHelper, 'mkdir($dir, 0777, true)') !== false,
    'create routes use fopen/mkdir without checking the target directory device'
);
afs_audit_xfail(
    'copy',
    afs_audit_unwired($copyRoute)
        && strpos($copyRoute, 'fm_rcopy($from, $dest)') !== false
        && afs_audit_unwired($recursiveCopy)
        && strpos($recursiveCopy, 'return fm_copy($path, $dest, $upd)') !== false
        && afs_audit_unwired($copyHelper)
        && strpos($copyHelper, 'copy($f1, $f2)') !== false,
    'copy dispatches through generic recursive PHP copy, not Afs::copy/copy_dirs'
);
afs_audit_xfail(
    'duplicate',
    afs_audit_unwired($copyRoute)
        && strpos($copyRoute, 'fm_rcopy($from, $fn_duplicate, False)') !== false,
    'same-directory duplicate uses the same unguarded fm_rcopy path'
);
afs_audit_xfail(
    'move',
    afs_audit_unwired($copyRoute . $massCopyRoute)
        && strpos($copyRoute, 'fm_rename($from, $dest)') !== false
        && strpos($massCopyRoute, 'fm_rename($from, $dest)') !== false
        && afs_audit_unwired($renameHelper)
        && strpos($renameHelper, 'rename($old, $new)') !== false,
    'single and mass move use generic rename without source/destination device checks'
);
afs_audit_xfail(
    'rename',
    afs_audit_unwired($renameRoute)
        && strpos($renameRoute, "fm_rename(\$path . '/' . \$old, \$path . '/' . \$new)") !== false
        && afs_audit_unwired($renameHelper),
    'rename is not routed through the AFS/filedrawers-safe primitive'
);
afs_audit_xfail(
    'single and bulk delete',
    afs_audit_unwired($deleteRoute . $massDeleteRoute)
        && strpos($deleteRoute, "fm_rdelete(\$path . '/' . \$del)") !== false
        && strpos($massDeleteRoute, 'fm_rdelete($new_path)') !== false
        && afs_audit_unwired($deleteHelper)
        && strpos($deleteHelper, 'unlink($path)') !== false
        && strpos($deleteHelper, 'rmdir($path)') !== false,
    'delete uses generic unlink/rmdir recursion rather than Afs::removeFolder/deleteFiles'
);
afs_audit_xfail(
    'single-part upload',
    afs_audit_unwired($uploadRoute)
        && strpos($uploadRoute, 'move_uploaded_file($tmp_name, $fullPath)') !== false,
    'the final uploaded-file destination is not device-checked'
);
afs_audit_xfail(
    'chunked upload',
    afs_audit_unwired($uploadRoute)
        && strpos($uploadRoute, '"{$fullPath}.part"') !== false
        && strpos($uploadRoute, 'fopen("{$fullPath}.part"') !== false
        && strpos($uploadRoute, 'rename("{$fullPath}.part", $fullPathTarget)') !== false,
    'chunk append and final rename operate directly on the requested path'
);
afs_audit_xfail(
    'URL upload',
    afs_audit_unwired($urlUpload)
        && strpos($urlUpload, 'copy($url, $temp_file, $ctx)') !== false
        && strpos($urlUpload, "rename(\$temp_file, strtok(get_file_path(), '?'))") !== false,
    'SSRF checks are retained, but the final filesystem rename is not AFS-confined'
);
afs_audit_xfail(
    'download/read',
    afs_audit_unwired($downloadRoute)
        && strpos($downloadRoute, 'fm_download_file(') !== false
        && afs_audit_unwired($downloadHelper)
        && strpos($downloadHelper, 'realpath($fileLocation)') !== false
        && strpos($downloadHelper, 'readfile($fileLocation)') !== false,
    'download follows realpath and calls PHP readfile instead of Afs::readfile'
);
afs_audit_xfail(
    'view/preview reads',
    afs_audit_unwired($viewer)
        && strpos($viewer, 'file_get_contents($file_path)') !== false
        && strpos($viewer, 'is_file($path . \'/\' . $file)') !== false,
    'viewer follows file symlinks and reads without validating the opened handle device'
);
afs_audit_xfail(
    'direct links',
    afs_audit_unwired($directLinks)
        && substr_count($directLinks, "lng('DirectLink')") === 2
        && substr_count($directLinks, 'FM_ROOT_URL') === 2,
    'links hand paths to the web server, bypassing PHP and every Afs guard'
);
afs_audit_xfail(
    'archive creation',
    afs_audit_unwired($archiveCreateRoute . $archiveHelpers)
        && strpos($archiveCreateRoute, 'new FM_Zipper()') !== false
        && strpos($archiveCreateRoute, 'new FM_Zipper_Tar()') !== false
        && strpos($archiveHelpers, 'addFile($filename)') !== false
        && strpos($archiveHelpers, 'scandir($path)') !== false,
    'ZIP/TAR creation recursively reads paths without AFS device or symlink boundaries'
);
afs_audit_xfail(
    'archive extraction',
    afs_audit_unwired($archiveExtractRoute . $archiveHelpers)
        && strpos($archiveExtractRoute, 'extractTo($path, null, true)') !== false
        && substr_count($archiveHelpers, 'extractTo($path)') >= 2,
    'ZIP/TAR extraction writes directly to a path without per-entry AFS confinement'
);
afs_audit_xfail(
    'symlink traversal',
    afs_audit_unwired($deleteHelper . $recursiveCopy . $viewer . $directLinks)
        && strpos($deleteHelper, 'if (is_link($path))') !== false
        && strpos($deleteHelper, 'return unlink($path)') !== false
        && strpos($recursiveCopy, 'if (is_dir($path))') !== false
        && strpos($recursiveCopy, 'is_link(') === false
        && strpos($viewer, 'file_get_contents($file_path)') !== false,
    'delete unlinks a link, but copy/view/direct-link paths can follow it outside AFS'
);
afs_audit_xfail(
    'mount-point traversal',
    afs_audit_unwired($deleteHelper . $recursiveCopy . $archiveHelpers)
        && strpos($deleteHelper, 'scandir($path)') !== false
        && strpos($recursiveCopy, 'scandir($path)') !== false
        && strpos($archiveHelpers, 'scandir($path)') !== false
        && strpos($deleteHelper . $recursiveCopy . $archiveHelpers, 'lstat(') === false
        && strpos($deleteHelper . $recursiveCopy . $archiveHelpers, "['dev']") === false,
    'generic recursion treats mount points as directories and never checks st_dev'
);

echo 'SUMMARY: ' . $auditProtected . ' protected, '
    . $auditFailClosed . ' fail-closed, '
    . $auditExpectedFailures . ' expected failures, '
    . count($auditFailures) . ' unexpected failures across '
    . $auditChecks . " checks\n";

if (!empty($auditFailures)) {
    exit(1);
}

exit(0);
