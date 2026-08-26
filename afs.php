<?php
/*
 * Copyright (c) 2005 - 2009 Regents of The University of Michigan.
 * All Rights Reserved.  See COPYRIGHT.
 */

//require_once( 'config.php' );
//require_once( 'mime.php' );
//require_once( 'session.php' );
define( "CLIPSEPARATOR", "*#~!@@@" );

if ( !extension_loaded( 'posix' )) {
    error_log( "Couldn't load necessary posix function" );
    echo "<p>Couldn't load necessary posix function</p>\n";
    exit( 1 );
}

/*
if ( !extension_loaded( 'filedrawers' )) {
    error_log( "Couldn't load Filedrawers PECL extension" );
    echo "<p>Couldn't load necessary Filedrawers extension</p>\n";
    exit( 1 );
}
*/

// remember this class needs to have $this->path set
class Afs
{
    protected $selectedItems;
    protected $afsUtils   = '/usr/bin';
    protected $afsRoot    = '/afs';
    public  $confirmMsg   = '';
    public  $errorMsg     = '';
    public  $notifyMsg    = '';
    public  $parPath;     // Path to the parent of current path
    public  $filename     = '';
    public  $adminPriv    = 0;
    public  $deletePriv   = 0;
    public  $insertPriv   = 0;
    public  $lockPriv     = 0;
    public  $lookupPriv   = 0;
    public  $readPriv     = 0;
    public  $writePriv    = 0;
    public  $path         = '';
    public  $sid          = '';
    public  $type         = '';
    public  $mimetype     = '';
    public  $formKey      = '';
    private $uniqname     = '';
    protected $afsStat;
    protected $afsAvailable = false;
    protected $lastFsStatus = 0;
    protected $newName    = '';
    protected $originPath = '';
    protected $startCWD   = '';

    public function __construct( $path="" )
    {
        $this->uniqname = isset( $_SERVER['REMOTE_USER'] )
            ? $_SERVER['REMOTE_USER'] : '';
        $this->startCWD = getcwd();
        if ( function_exists( 'fm_guard_config' )) {
            $guard = fm_guard_config();
            if ( is_array( $guard ) && isset( $guard['root'] )
              && is_string( $guard['root'] ) && $guard['root'] !== '' ) {
                $this->afsRoot = $guard['root'];
            }
        }
        $this->afsStat  = @stat( $this->afsRoot );

        if ( !$this->afsMountIsAvailable() ) {
            $this->errorMsg = 'AFS is not mounted.';
            return;
        }

        $this->afsAvailable = true;

        // Bug 1975875 Fixed: Don't trim whitespaces from path
        if ( !$this->setPath( $path )) {
            return;
        }

        // Generate the path of the folder one level above the current
        if ( !preg_match( "/(.*\/)([^\/]+)\/?$/", $this->path, $Matches )) {
            $this->errorMsg = 'Missing home directory.';
            return;
        }
        $this->parPath  = $Matches[1];
        $this->filename = $Matches[2];

        if ( !isset( $_SESSION['formKey'] )) {
            $_SESSION['formKey'] = md5( uniqid( rand(), true ));
        }

        $this->formKey = $_SESSION['formKey'];
        $this->sid     = md5( uniqid( rand(), true ));
    }

    protected function afsMountIsAvailable()
    {
        if ( !is_array( $this->afsStat )
          || !function_exists( 'fm_guard_mountinfo_records' )
          || !function_exists( 'fm_guard_root_uses_allowlisted_afs' )) {
            return false;
        }

        $records = fm_guard_mountinfo_records();
        return fm_guard_root_uses_allowlisted_afs(
            $this->afsRoot, $records );
    }

    protected function afsDeviceIsAllowed( $path, $device )
    {
        if ( function_exists( 'fm_guard_config' )
          && function_exists( 'fm_guard_device_is_allowed' )
          && fm_guard_config() !== false ) {
            return fm_guard_device_is_allowed( $path, (int)$device );
        }

        return is_array( $this->afsStat )
            && $this->afsStat['dev'] == $device;
    }


    // Symlink safe method to determine file type
    public function getType()
    {
        if ( !$this->makePathAFSlocal( dirname( $this->path ))) {
            return false;
        }

        clearstatcache();
        if ( @filetype( basename( $this->path )) == 'dir' ) {
            @chdir( $this->startCWD );
            return 'dir';
        } else {
            clearstatcache();
            $type = @filetype( basename( $this->path ));

            if ( $type == 'file' ) {
                $this->mimetype = function_exists( 'fm_get_mime_type' )
                    ? fm_get_mime_type( basename( $this->path ))
                    : 'application/octet-stream';
                @chdir( $this->startCWD );
                return $type;
            } else {
                @chdir( $this->startCWD );
                return 'none';
            }
        }

        @chdir( $this->startCWD );
    }


    public function processCommand()
    {
        if ( !isset( $_POST['command'] ) || $this->formKey != $_POST['formKey'] ) {
            return false;
        }

        $this->setSelectedItems();

        switch ( $_POST['command'] ) {
            case 'newfolder':
                $this->createFolder();
                break;
            case 'rename':
                $this->setNewItemName();
                $this->afsRename();
                break;
            case 'cut':
                $this->setOriginPath();
                $this->moveFiles();
                break;
            case 'copy':
                $this->setOriginPath();
                $this->copyFiles();
                break;
            case 'delete':
                $this->deleteFiles();
                break;
            default:
                break;
        }
    }


    /*
     * This function sets the "target" of an operation
     * (what file(s) or folder(s)
     * to perform the selected action on.
     */
    protected function setSelectedItems()
    {
        if ( isset( $_POST['selectedItems'] )
          && is_array( $_POST['selectedItems'] )) {
            $this->selectedItems = array();

            foreach ( $_POST['selectedItems'] as $key=>$item ) {
                $this->selectedItems[$key] = $item;
            }
        } else if ( isset( $_POST['selectedItems'] )) {
            $this->selectedItems = $_POST['selectedItems'];
        }
    }


    // Some functions like cut or paste need to know where a file is coming from
    // in addition to where it is going
    public function setOriginPath()
    {
        if ( isset( $_POST['originPath'] )) {
            $this->originPath = $this->pathSecurity( $_POST['originPath'] );
        }
    }


    public function setNewItemName()
    {
        if ( isset( $_POST['newName'] )) {
            $this->newName = $_POST['newName'];
        }
    }


    public function createFolder()
    {
        if ( !$this->makePathAFSlocal( $this->path )) {
            return false;
        }

        if ( $this->selectedItems != 'Please enter a name for your new folder.' ) {
            if ( $this->linkSafeFileExists( basename( $this->selectedItems ))) {
                $this->errorMsg = "The folder \'$this->selectedItems\' " .
                    "already exists. Please select a different name.";
                  @chdir( $this->startCWD );
                  return false;
            }

            if ( !mkdir( trim( basename( $this->selectedItems )), 0755, true )) {
                $this->errorMsg = 'Unable to create folder.';
                @chdir( $this->startCWD );
                return false;
            }

            @chdir( $this->startCWD );
            return true;
        }
    }


    // Remove an existing folder
    // jackylee at eml dot cc
    public function removeFolder( $folderPath )
    {
        if ( !$this->makePathAFSlocal( $folderPath )) {
            return false;
        }

        if ( !$handle = @opendir( '.' )) {
            $this->errorMsg = 'Unable to remove the folder because ' .
                'it no longer exists.';
            @chdir( $this->startCWD );
            return false;
        }

        while ( false !== ( $item = readdir( $handle ))) {

            if ( $item == "." || $item == ".." ) {
                continue;
            }

            $itemPath = $folderPath . '/' . $item;

            if ( is_dir( $itemPath ) && !is_link( $itemPath )) {
                if ( !$this->removeFolder( $itemPath )) {
                    @chdir( $this->startCWD );
                    return false;
                }
            } else {
                if ( !$this->makePathAFSlocal( $folderPath )) {
                    @chdir( $this->startCWD );
                    return false;
                }

                unlink( basename( $item ));
                @chdir( $this->startCWD );
            }
        }

        closedir( $handle );

        if ( !$this->makePathAFSlocal( $folderPath )) {
            @chdir( $this->startCWD );
            return false;
        }

        if ( rmdir( '../' . basename( getcwd()))) {
            $this->notifyMsg = "Successfully deleted file(s).";
            @chdir( $this->startCWD );
            return true;
        }

        @chdir( $this->startCWD );
        $this->errorMsg = 'Unable to remove the folder.';
        return false;
    }


    // Delete specified files
    public function deleteFiles()
    {
        if ( ! $this->selectedItems ) {
            return false;
        }

        // XXX 0.5.0 should use a data structure that doesn't require splitting
        // on a whitespace character
        $files = explode( "\n", $this->selectedItems );

        foreach ( $files as $file ) {
            $file = preg_replace( "/[\r\n]/", '', $file );

            if ( empty( $file )) {
                continue;
            }

            // Security checks are in Afs::removeFolder()
            $itemPath = $this->path . '/' . $file;

            if ( is_dir( $itemPath ) && !is_link( $itemPath )) {
                if ( !$this->removeFolder( $itemPath )) {
                    return false;
                }
            } else {
                if ( !$this->makePathAFSlocal( $this->path )) {
                    return false;
                }

                if ( !@unlink( basename( $file ))) {
                    @chdir( $this->startCWD );
                    $this->errorMsg = "Unable to delete $file.";
                    return false;
                } else {
                    @chdir( $this->startCWD );
                    $this->notifyMsg = "Successfully deleted file(s).";
                }
            }
        }

        @chdir( $this->startCWD );
        return true;
    }


    public function afsRename()
    {

        if ( $this->selectedItems == $this->newName ) {
            return false;
        }

        if ( !$this->makePathAFSlocal( $this->path )) {
            return false;
        }

        if ( is_link( basename( $this->selectedItems ))) {
            $this->errorMsg = "Symbolic links cannot be renamed.";
            @chdir( $this->startCWD );
            return false;
        }

        $newName = trim( basename( $this->newName ));

        if ( $this->linkSafeFileExists( $newName )) {
            $this->errorMsg = "The file or folder '" . $newName .
                "' already exists. Please select a different name.";
            @chdir( $this->startCWD );
            return false;
        }

        if ( !function_exists( 'filedrawers_rename' )) {
            $this->errorMsg = 'AFS-safe rename support is unavailable.';
            @chdir( $this->startCWD );
            return false;
        }

        if ( !@filedrawers_rename( basename( $this->selectedItems ),
                $newName, $this->afsRoot )) {
            $this->errorMsg = 'Unable to rename this file or folder.';
            @chdir( $this->startCWD );
            return false;
        }

        @chdir( $this->startCWD );
        return true;
    }

    /*
     * Move files from one directory to another
     * This will clobber an existing file with the same name
     */
    function moveFiles()
    {
        $files = explode( CLIPSEPARATOR, $this->selectedItems );

        foreach ( $files as $file ) {
            if ( empty( $file )) {
                continue;
            }

            // Security checks are in filedrawers_rename
            $sourcePath = $this->originPath . '/' . $file;
            $destPath   = $this->path . '/' . $file;

            if ( !function_exists( 'filedrawers_rename' )) {
                $this->errorMsg = 'AFS-safe move support is unavailable.';
                return false;
            }

            if ( !@filedrawers_rename( $sourcePath, $destPath, $this->afsRoot )) {
                $this->errorMsg = "Unable to move: $file.";
                return false;
            }

            $this->notifyMsg = "Pasted the contents of the clipboard.";
        }

        return true;
    }

    // Copy file from one directory to another
    function copyFiles()
    {
        $files = explode( CLIPSEPARATOR, $this->selectedItems );

        foreach ( $files as $file ) {
            if ( empty( $file )) {
                continue;
            }

            // Link-safe dispatch and security checks are in copyItem().
            $sourcePath = $this->originPath . '/'. $file;
            $destPath   = $this->path . '/' . $file;

            if ( !$this->copyItem( $sourcePath, $destPath )) {
                $this->errorMsg = "Unable to copy $file.";
                return false;
            }

            $this->notifyMsg = "Pasted the contents of the clipboard.";
        }

        return true;
    }


    // Dispatch links before directory checks so a directory symlink is copied
    // as a link and is never traversed by copy_dirs().
    protected function copyItem( $source, $target )
    {
        if ( is_link( $source )) {
            return $this->copy( $source, $target );
        }

        $type = @filetype( $source );
        if ( $type === 'dir' ) {
            return $this->copy_dirs( $source, $target );
        }
        if ( $type === 'file' ) {
            return $this->copy( $source, $target );
        }

        return false;
    }


    /* A helper function for copyFiles().  Copies an entire directory at once.
     * Original author: swizec at swizec dot com, php.net
     */
    public function copy_dirs( $source, $target )
    {
        if ( is_link( $source ) || !is_dir( $source )) {
            return false;
        }

        $sourceReal = @realpath( $source );
        $targetParentReal = @realpath( dirname( $target ));
        if ( $sourceReal === false || $targetParentReal === false ) {
            return false;
        }

        $sourcePrefix = rtrim( $sourceReal, '/' ) . '/';
        $targetParentPrefix = rtrim( $targetParentReal, '/' ) . '/';
        if ( $targetParentReal === $sourceReal
          || strpos( $targetParentPrefix, $sourcePrefix ) === 0 ) {
            return false;
        }

        if ( !$this->makePathAFSlocal( dirname( $target ))) {
            return false;
        }

        $targetCheck = getcwd();

        if ( !@mkdir( basename( $target ), 0755 )) {
            @chdir( $this->startCWD );
            return false;
        }

        if ( !$this->makePathAFSlocal( $source )) {
            @chdir( $this->startCWD );
            return false;
        }

        $destCheck = getcwd();

        // Prevent copying directory inside of itself
        if ( $targetCheck == $destCheck ) {
            @chdir( $this->startCWD );
            return false;
        }

        $dir = dir( '.' );

        while ( false !== ( $entry = $dir->read())) {
            if ( $entry == '.' || $entry == '..' ) {
                continue;
            }

            $sourcePath = $source . '/' . $entry;
            $targetPath = $target . '/' . $entry;

            // Link-safe dispatch and security checks are in copyItem().
            if ( !$this->copyItem( $sourcePath, $targetPath )) {
                @chdir( $this->startCWD );
                return false;
            }
        }

        $dir->close();
        @chdir( $this->startCWD );
        return true;
    }


    /* An AFS safe version of the PHP copy builtin - this will only copy
     * a file with a source and destination in AFS.  If we relied on the
     * copy builtin, there is a small possibility of a race condition where
     * the copy could be symlink'ed out of AFS.  This function works on file
     * handles only after making sure the source and destination are in AFS.
     */
    public function copy( $source, $dest )
    {
        if ( !$this->afsAvailable || !is_array( $this->afsStat )) {
            return false;
        }

        if ( is_link( $source )) {
            if ( !$this->makePathAFSlocal( dirname( $source ))) {
                return false;
            }

            $name   = basename( $source );
            $target = readlink( $name );

            if ( !$this->makePathAFSlocal( dirname( $dest ))) {
                @chdir( $this->startCWD );
                return false;
            }

            if ( !symlink( $target, basename( $dest ))) {
                @chdir( $this->startCWD );
                return false;
            }

            @chdir( $this->startCWD );
            return true;
        }

        $safeSource = $this->pathSecurity( $source );
        if ( !$safeSource
          || !( $sourceHdl = @fopen( $safeSource, "rb" ))) {
            @chdir( $this->startCWD );
            return false;
        }

        $sourceStat = fstat( $sourceHdl );

        if ( !is_array( $sourceStat )
          || !$this->afsDeviceIsAllowed(
                $safeSource, $sourceStat['dev'] )) {
            @fclose( $sourceHdl );
            @chdir( $this->startCWD );
            return false;
        }

        if ( !$this->makePathAFSlocal( dirname( $dest ))) {
            @fclose( $sourceHdl );
            @chdir( $this->startCWD );
            return false;
        }

        // If you want copy to overwrite, then do unlink(basename($dest)) here
        if ( !( $destHdl = @fopen( basename( $dest ), "xb" ))) {
            @fclose( $sourceHdl );
            @chdir( $this->startCWD );
            return false;
        }

        $copied = true;
        while ( !feof( $sourceHdl )) {
            $buffer = fread( $sourceHdl, 1024 * 1024 );
            if ( $buffer === false ) {
                $copied = false;
                break;
            }

            $written = 0;
            $length = strlen( $buffer );
            while ( $written < $length ) {
                $bytes = fwrite( $destHdl, substr( $buffer, $written ));
                if ( $bytes === false || $bytes === 0 ) {
                    $copied = false;
                    break 2;
                }
                $written += $bytes;
            }
        }

        if ( !@fflush( $destHdl )) {
            $copied = false;
        }
        @fclose( $sourceHdl );
        if ( !@fclose( $destHdl )) {
            $copied = false;
        }

        if ( !$copied ) {
            @unlink( basename( $dest ));
        }
        @chdir( $this->startCWD );

        return $copied;
    }


    // A AFS safe version of the PHP readfile builtin - this will only
    // read files which are hosted in AFS.
    function readfile()
    {
        if ( !$this->afsAvailable || !is_array( $this->afsStat )) {
            return false;
        }

        clearstatcache();

        $safePath = $this->pathSecurity( $this->path );
        $handle = $safePath ? @fopen( $safePath, "rb" ) : false;
        if ( $handle ) {
            $stat = fstat( $handle );
            if ( is_array( $stat )
              && $this->afsDeviceIsAllowed( $safePath, $stat['dev'] )) {
                while ( !feof( $handle )) {
                    $buffer = fread( $handle, 1024 * 1024 );
                    if ( $buffer === false ) {
                        @fclose( $handle );
                        return false;
                    }
                    echo $buffer;
                }
                @fclose( $handle );
                return true;
            }

            @fclose( $handle );
        }

        return false;
    }

    // Change the ACL for a given path
    function changeAcl($entity,
                       $rights,
                       $path='',
                       $recursive=false,
                       $negative=false )
    {
        $path = ( $path ) ? $path : $this->path;
        $path = $this->pathSecurity( $path );
        $rights = trim( $rights );

        if ( !$path || empty( $entity )
          || !preg_match( '/^(none|[lrwidkaA-H]{1,15})$/', $rights )) {
            $this->errorMsg =
                'Warning: Invalid access control list request.';
            return false;
        }

        if ( !$recursive ) {
            return $this->changeAclEntries(
                array( $entity => $rights ), $path, $negative );
        }

        $paths = array( $path );
        if ( $recursive && is_dir( $path )) {
            $flags = FilesystemIterator::SKIP_DOTS;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, $flags ),
                RecursiveIteratorIterator::SELF_FIRST );

            foreach ( $iterator as $item ) {
                if ( $item->isDir() && !$item->isLink()) {
                    $safePath = $this->pathSecurity( $item->getPathname());
                    if ( !$safePath ) {
                        return false;
                    }
                    $paths[] = $safePath;
                }
            }
        }

        foreach ( $paths as $aclPath ) {
            $arguments = array( 'sa' );
            if ( $negative ) {
                $arguments[] = '-negative';
            }
            $arguments[] = $aclPath;
            $arguments[] = $entity;
            $arguments[] = $rights;

            $result = $this->runFs( $arguments );
            if ( $result === false || $this->lastFsStatus !== 0
              || preg_match( '/(^|\n)fs:/', $result )) {
                $this->errorMsg =
                    'Warning: Unable to modify the access control list.';
                return false;
            }
        }

        return true;
    }

    // Change multiple ACL entries in one fs invocation to avoid per-ACE
    // partial updates and subprocess overhead.
    function changeAclEntries( $entries, $path='', $negative=false )
    {
        $path = ( $path ) ? $path : $this->path;
        $path = $this->pathSecurity( $path );
        if ( !$path || !is_array( $entries ) || empty( $entries )) {
            return false;
        }

        $arguments = array( 'sa' );
        if ( $negative ) {
            $arguments[] = '-negative';
        }
        $arguments[] = $path;

        foreach ( $entries as $entity => $rights ) {
            $rights = trim( $rights );
            if ( $entity === ''
              || !preg_match( '/^(none|[lrwidkaA-H]{1,15})$/', $rights )) {
                $this->errorMsg =
                    'Warning: Invalid access control list request.';
                return false;
            }
            $arguments[] = $entity;
            $arguments[] = $rights;
        }

        $result = $this->runFs( $arguments );
        if ( $result === false || $this->lastFsStatus !== 0
          || preg_match( '/(^|\n)fs:/', $result )) {
            $this->errorMsg =
                'Warning: Unable to modify the access control list.';
            return false;
        }

        return true;
    }

    // Remove a file-specific ACL so that the file inherits its parent ACL.
    function restoreAclInheritance( $path='' )
    {
        $path = ( $path ) ? $path : $this->path;
        $path = $this->pathSecurity( $path );
        if ( !$path || !is_file( $path )) {
            $this->errorMsg =
                'Warning: Invalid ACL inheritance request.';
            return false;
        }

        $result = $this->runFs( array( 'removeacl', $path ));
        if ( $result === false || $this->lastFsStatus !== 0
          || preg_match( '/(^|\n)fs:/', $result )) {
            $this->errorMsg =
                'Warning: Unable to restore inherited permissions.';
            return false;
        }

        return true;
    }

    // Return an array of ACL rights for the current path
    function readAcl( $path='' )
    {
        $path = ( $path ) ? $path : $this->path;
        $path = $this->pathSecurity( $path );
        if ( !$path ) {
            return false;
        }

        $result = $this->runFs( array( 'listacl', $path ));
        if ( $result === false || $this->lastFsStatus !== 0
          || preg_match( '/(^|\n)fs:/', $result )) {
            $this->errorMsg =
                'Warning: Unable to read the access control list.';
            return false;
        }

        $acl = $this->parseAclOutput( $result );
        if ( $acl === false ) {
            $this->errorMsg =
                'Warning: Unable to parse the access control list.';
        }

        return $acl;
    }

    public function parseAclOutput( $result )
    {
        if ( !is_string( $result )) {
            return false;
        }

        $rights = array( 'l', 'r', 'w', 'i', 'd', 'k', 'a',
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' );
        $acl = array(
            'normal' => array(),
            'negative' => array(),
            'inherited' => false
        );
        $section = '';
        $sawHeader = false;
        $sawNormal = false;
        $sawNegative = false;
        $lines = preg_split( '/\r?\n/', $result );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( preg_match( '/^Access list( \(inherited\))? for .+ is$/i',
                    $line, $header )) {
                if ( $sawHeader ) {
                    return false;
                }
                $sawHeader = true;
                $acl['inherited'] = !empty( $header[1] );
                $section = '';
                continue;
            }
            if ( preg_match( '/^Volume access list for .+ is$/i', $line )) {
                // A Volume Maximum ACL is a separate, read-only policy block.
                // Never merge it into the editable object ACL.
                return false;
            }
            if ( preg_match( '/^Normal rights:$/i', $line )) {
                if ( !$sawHeader || $sawNormal ) {
                    return false;
                }
                $section = 'normal';
                $sawNormal = true;
                continue;
            }
            if ( preg_match( '/^Negative rights:$/i', $line )) {
                if ( !$sawNormal || $sawNegative ) {
                    return false;
                }
                $section = 'negative';
                $sawNegative = true;
                continue;
            }
            if ( !$section || !preg_match( '/^(\S+)\s+(\S+)$/', $line, $matches )) {
                return false;
            }
            if ( $matches[2] !== 'none'
              && !preg_match( '/^[lrwidkaA-H]{1,15}$/', $matches[2] )) {
                return false;
            }

            $seenRights = array();
            foreach ( str_split( $matches[2] ) as $setRight ) {
                if ( isset( $seenRights[$setRight] )) {
                    return false;
                }
                $seenRights[$setRight] = true;
            }

            foreach ( $rights as $right ) {
                $acl[$section][$matches[1]][$right] =
                    strpos( $matches[2], $right ) !== false;
            }
        }

        if ( !$sawHeader || !$sawNormal ) {
            return false;
        }

        return $acl;
    }

    function getACLAccess( $path )
    {
        $this->lookupPriv = 0;
        $this->readPriv = 0;
        $this->writePriv = 0;
        $this->insertPriv = 0;
        $this->deletePriv = 0;
        $this->lockPriv = 0;
        $this->adminPriv = 0;

        $path = $this->pathSecurity( $path );
        if ( !$path ) {
            return '';
        }

        $result = $this->runFs( array( 'getcalleraccess', $path ));
        if ( $result === false || $this->lastFsStatus !== 0 ) {
            return '';
        }

        $acls = '';
        if ( preg_match( '/^Callers access to .* is ([lrwidkaA-H]{1,15})$/m',
                $result, $Matches )) {
            $acls = $Matches[1];
            $this->lookupPriv = strpos( $acls, 'l' ) !== false ? 1 : 0;
            $this->readPriv   = strpos( $acls, 'r' ) !== false ? 1 : 0;
            $this->writePriv  = strpos( $acls, 'w' ) !== false ? 1 : 0;
            $this->insertPriv = strpos( $acls, 'i' ) !== false ? 1 : 0;
            $this->deletePriv = strpos( $acls, 'd' ) !== false ? 1 : 0;
            $this->lockPriv   = strpos( $acls, 'k' ) !== false ? 1 : 0;
            $this->adminPriv  = strpos( $acls, 'a' ) !== false ? 1 : 0;
        }
        return $acls;
    }

    function getACLAccessBatch( $paths )
    {
        if ( !is_array( $paths ) || empty( $paths )) {
            return array();
        }

        $access = array();
        $safeToRequested = array();
        foreach ( $paths as $path ) {
            if ( !is_string( $path ) || $path === '' ) {
                continue;
            }
            $access[$path] = '';
            if ( strpos( $path, "\0" ) !== false
              || strpos( $path, "\r" ) !== false
              || strpos( $path, "\n" ) !== false ) {
                continue;
            }
            $safePath = $this->pathSecurity( $path );
            if ( !$safePath ) {
                continue;
            }
            if ( !isset( $safeToRequested[$safePath] )) {
                $safeToRequested[$safePath] = array();
            }
            $safeToRequested[$safePath][] = $path;
        }

        $batches = array();
        $safePaths = array();
        $payloadSize = 2;
        foreach ( array_keys( $safeToRequested ) as $safePath ) {
            $encodedSize = 2 + strlen( $safePath );
            if ( !empty( $safePaths )
              && ( count( $safePaths ) >= 128
                || $payloadSize + $encodedSize > 262144 )) {
                $batches[] = $safePaths;
                $safePaths = array();
                $payloadSize = 2;
            }
            $safePaths[] = $safePath;
            $payloadSize += $encodedSize;
        }
        if ( !empty( $safePaths )) {
            $batches[] = $safePaths;
        }

        foreach ( $batches as $safePaths ) {
            $result = $this->runFs( array_merge(
                array( 'getcalleraccess', '-path' ), $safePaths ));
            if ( $result === false ) {
                continue;
            }

            /* fs uses one aggregate exit status.  Preserve exact successful
             * rows from mixed output; missing or malformed rows stay empty. */
            foreach ( preg_split( '/\r?\n/', $result ) as $line ) {
                if ( !preg_match(
                        '/^Callers access to (.*) is ([lrwidkaA-H]{0,15})$/D',
                        $line, $matches )
                  || !isset( $safeToRequested[$matches[1]] )) {
                    continue;
                }
                foreach ( $safeToRequested[$matches[1]] as $requestedPath ) {
                    $access[$requestedPath] = $matches[2];
                }
            }
        }
        return $access;
    }

    private static $aclBrokerStream = null;
    private static $aclBrokerUnavailable = false;
    private static $aclBrokerRequests = 0;

    private static function brokerDeadline()
    {
        if ( !function_exists( 'hrtime' )) {
            return false;
        }
        $now = hrtime( true );
        if ( !is_int( $now ) || $now > PHP_INT_MAX - 6000000000 ) {
            return false;
        }
        return $now + 6000000000;
    }

    private static function brokerWait( $stream, $deadline, $write )
    {
        if ( !function_exists( 'hrtime' ) || !is_bool( $write )) {
            return false;
        }
        $now = hrtime( true );
        if ( !is_int( $now )) {
            return false;
        }
        $remaining = $deadline - $now;
        if ( $remaining <= 0 ) {
            return false;
        }
        $seconds = (int)floor( $remaining / 1000000000 );
        $microseconds = (int)floor(( $remaining % 1000000000 ) / 1000 );
        if ( $seconds === 0 && $microseconds === 0 ) {
            $microseconds = 1;
        }
        $readStreams = $write ? null : array( $stream );
        $writeStreams = $write ? array( $stream ) : null;
        $exceptStreams = null;
        return @stream_select( $readStreams, $writeStreams, $exceptStreams,
            $seconds, $microseconds ) === 1;
    }

    private static function brokerReadExact( $stream, $length, $deadline )
    {
        $data = '';
        while ( strlen( $data ) < $length ) {
            if ( !self::brokerWait( $stream, $deadline, false )) {
                return false;
            }
            $part = fread( $stream, $length - strlen( $data ));
            if ( $part === false || $part === '' ) {
                return false;
            }
            $data .= $part;
        }
        return $data;
    }

    private static function brokerPackString( $value )
    {
        if ( !is_string( $value ) || strlen( $value ) > 4095
          || strpos( $value, "\0" ) !== false
          || strpos( $value, "\r" ) !== false
          || strpos( $value, "\n" ) !== false ) {
            return false;
        }
        return pack( 'n', strlen( $value )) . $value;
    }

    private static function brokerTake( $payload, &$offset, $length )
    {
        if ( !is_int( $offset ) || !is_int( $length ) || $length < 0
          || $offset < 0 || $offset + $length > strlen( $payload )) {
            return false;
        }
        $value = substr( $payload, $offset, $length );
        $offset += $length;
        return $value;
    }

    private static function brokerTakeU16( $payload, &$offset )
    {
        $bytes = self::brokerTake( $payload, $offset, 2 );
        if ( $bytes === false ) {
            return false;
        }
        $value = unpack( 'nvalue', $bytes );
        return $value['value'];
    }

    private static function brokerRequest( $operation, $flags, $payload )
    {
        if ( self::$aclBrokerUnavailable || self::$aclBrokerRequests >= 64
          || !is_string( $payload ) || strlen( $payload ) > 262144 ) {
            return false;
        }
        if ( self::$aclBrokerStream === null ) {
            if ( !function_exists( 'fm_open_afs_acl_broker_stream' )) {
                self::$aclBrokerUnavailable = true;
                return false;
            }
            self::$aclBrokerStream = @fm_open_afs_acl_broker_stream();
            if ( !is_resource( self::$aclBrokerStream )
              || !@stream_set_blocking( self::$aclBrokerStream, false )
              || @stream_set_read_buffer(
                    self::$aclBrokerStream, 0 ) !== 0 ) {
                self::$aclBrokerStream = null;
                self::$aclBrokerUnavailable = true;
                return false;
            }
        }

        $request = pack( 'a4CCCCN', 'AACL', 1, $operation, $flags, 0,
            strlen( $payload )) . $payload;
        $deadline = self::brokerDeadline();
        if ( $deadline === false ) {
            self::$aclBrokerUnavailable = true;
            return false;
        }
        $written = 0;
        while ( $written < strlen( $request )) {
            if ( !self::brokerWait(
                    self::$aclBrokerStream, $deadline, true )) {
                self::$aclBrokerUnavailable = true;
                return false;
            }
            $count = @fwrite( self::$aclBrokerStream,
                substr( $request, $written ));
            if ( $count === false || $count === 0 ) {
                self::$aclBrokerUnavailable = true;
                return false;
            }
            $written += $count;
        }
        self::$aclBrokerRequests++;

        $deadline = self::brokerDeadline();
        if ( $deadline === false ) {
            self::$aclBrokerUnavailable = true;
            return false;
        }
        $headerBytes = self::brokerReadExact(
            self::$aclBrokerStream, 12, $deadline );
        if ( $headerBytes === false ) {
            self::$aclBrokerUnavailable = true;
            return false;
        }
        $header = unpack(
            'a4magic/Cversion/Coperation/Cstatus/Creserved/Nlength',
            $headerBytes );
        if ( $header['magic'] !== 'AACL' || $header['version'] !== 1
          || $header['operation'] !== $operation || $header['reserved'] !== 0
          || $header['length'] > 262144 ) {
            self::$aclBrokerUnavailable = true;
            return false;
        }
        $body = self::brokerReadExact(
            self::$aclBrokerStream, $header['length'], $deadline );
        if ( $body === false ) {
            self::$aclBrokerUnavailable = true;
            return false;
        }
        return array( 'status' => $header['status'], 'payload' => $body );
    }

    private function runBrokerFs( $arguments )
    {
        if ( $arguments[0] === 'getcalleraccess' ) {
            $paths = array_slice( $arguments,
                isset( $arguments[1] ) && $arguments[1] === '-path' ? 2 : 1 );
            if ( empty( $paths ) || count( $paths ) > 128 ) {
                $this->lastFsStatus = 125;
                return false;
            }
            $payload = pack( 'n', count( $paths ));
            foreach ( $paths as $path ) {
                $encoded = self::brokerPackString( $path );
                if ( $encoded === false ) {
                    $this->lastFsStatus = 125;
                    return false;
                }
                $payload .= $encoded;
            }
            $response = self::brokerRequest( 1, 0, $payload );
            if ( $response === false || $response['status'] !== 0 ) {
                $this->lastFsStatus = 126;
                return false;
            }
            $offset = 0;
            $count = self::brokerTakeU16( $response['payload'], $offset );
            if ( $count !== count( $paths )) {
                $this->lastFsStatus = 126;
                return false;
            }
            $lines = array();
            $failed = false;
            foreach ( $paths as $path ) {
                $item = self::brokerTake( $response['payload'], $offset, 2 );
                if ( $item === false ) {
                    $this->lastFsStatus = 126;
                    return false;
                }
                $status = ord( $item[0] );
                $rightsLength = ord( $item[1] );
                $rights = self::brokerTake(
                    $response['payload'], $offset, $rightsLength );
                if ( $rights === false
                  || !preg_match( '/^[lrwidkaA-H]{0,15}$/D', $rights )) {
                    $this->lastFsStatus = 126;
                    return false;
                }
                if ( $status === 0 ) {
                    $lines[] = 'Callers access to ' . $path . ' is ' . $rights;
                } else {
                    $failed = true;
                }
            }
            if ( $offset !== strlen( $response['payload'] )) {
                $this->lastFsStatus = 126;
                return false;
            }
            $this->lastFsStatus = $failed ? 1 : 0;
            return implode( "\n", $lines );
        }

        if ( $arguments[0] === 'listacl' && count( $arguments ) === 2 ) {
            $path = self::brokerPackString( $arguments[1] );
            $response = $path === false ? false
                : self::brokerRequest( 2, 0, $path );
            if ( $response === false || $response['status'] !== 0 ) {
                $this->lastFsStatus = 1;
                return false;
            }
            $offset = 0;
            $prefix = self::brokerTake( $response['payload'], $offset, 5 );
            if ( $prefix === false ) {
                $this->lastFsStatus = 126;
                return false;
            }
            $inherited = ord( $prefix[0] );
            $counts = unpack( 'nnormal/nnegative', substr( $prefix, 1 ));
            if ( $inherited > 1 || $counts['normal'] > 256
              || $counts['negative'] > 256 ) {
                $this->lastFsStatus = 126;
                return false;
            }
            $lines = array(
                'Access list' . ( $inherited ? ' (inherited)' : '' )
                    . ' for ' . $arguments[1] . ' is',
                'Normal rights:'
            );
            foreach ( array( 'normal', 'negative' ) as $section ) {
                if ( $section === 'negative' ) {
                    $lines[] = 'Negative rights:';
                }
                for ( $index = 0; $index < $counts[$section]; $index++ ) {
                    $principalLength = self::brokerTakeU16(
                        $response['payload'], $offset );
                    $principal = $principalLength === false ? false
                        : self::brokerTake( $response['payload'], $offset,
                            $principalLength );
                    $rightSize = self::brokerTake(
                        $response['payload'], $offset, 1 );
                    $rights = $rightSize === false ? false
                        : self::brokerTake( $response['payload'], $offset,
                            ord( $rightSize ));
                    if ( $principal === false || $principal === ''
                      || preg_match( '/\s/', $principal )
                      || $rights === false
                      || !preg_match( '/^(none|[lrwidkaA-H]{1,15})$/D',
                            $rights )) {
                        $this->lastFsStatus = 126;
                        return false;
                    }
                    $lines[] = '  ' . $principal . ' ' . $rights;
                }
            }
            if ( $offset !== strlen( $response['payload'] )) {
                $this->lastFsStatus = 126;
                return false;
            }
            $this->lastFsStatus = 0;
            return implode( "\n", $lines );
        }

        if ( $arguments[0] === 'sa' ) {
            $negative = isset( $arguments[1] )
                && $arguments[1] === '-negative';
            $pathIndex = $negative ? 2 : 1;
            if ( count( $arguments ) < $pathIndex + 3
              || ( count( $arguments ) - $pathIndex - 1 ) % 2 !== 0 ) {
                $this->lastFsStatus = 125;
                return false;
            }
            $path = self::brokerPackString( $arguments[$pathIndex] );
            $entryCount = (int)(( count( $arguments )
                - $pathIndex - 1 ) / 2 );
            if ( $path === false || $entryCount < 1 || $entryCount > 256 ) {
                $this->lastFsStatus = 125;
                return false;
            }
            $payload = chr( $negative ? 1 : 0 ) . $path
                . pack( 'n', $entryCount );
            for ( $index = $pathIndex + 1; $index < count( $arguments );
                  $index += 2 ) {
                $principal = $arguments[$index];
                $rights = $arguments[$index + 1];
                if ( !is_string( $principal ) || $principal === ''
                  || strlen( $principal ) > 512 || $principal[0] === '-'
                  || preg_match( '/\s/', $principal )
                  || !preg_match( '/^(none|[lrwidkaA-H]{1,15})$/D',
                        $rights )) {
                    $this->lastFsStatus = 125;
                    return false;
                }
                $payload .= pack( 'n', strlen( $principal )) . $principal
                    . chr( strlen( $rights )) . $rights;
            }
            $response = self::brokerRequest( 3, $negative ? 1 : 0, $payload );
            if ( $response === false || $response['status'] !== 0
              || $response['payload'] !== '' ) {
                $this->lastFsStatus = 1;
                return false;
            }
            $this->lastFsStatus = 0;
            return '';
        }

        if ( $arguments[0] === 'removeacl' && count( $arguments ) === 2 ) {
            $path = self::brokerPackString( $arguments[1] );
            $response = $path === false ? false
                : self::brokerRequest( 5, 0, $path );
            if ( $response === false || $response['status'] !== 0
              || $response['payload'] !== '' ) {
                $this->lastFsStatus = 1;
                return false;
            }
            $this->lastFsStatus = 0;
            return '';
        }

        $this->lastFsStatus = 125;
        return false;
    }

    protected function runFs( $arguments )
    {
        if ( !is_array( $arguments ) || empty( $arguments )) {
            return false;
        }

        if ( getenv( 'AURISTOR_AFS_ACL_BROKER' ) === '1' ) {
            if ( getenv( 'AURISTOR_AFS_ACL_BROKER_DIALECT' )
                !== 'auristor' ) {
                $this->lastFsStatus = 125;
                return false;
            }
            return $this->runBrokerFs( $arguments );
        }

        $command = 'LC_ALL=C ' . escapeshellarg( $this->afsUtils . '/fs' );
        foreach ( $arguments as $argument ) {
            $command .= ' ' . escapeshellarg( $argument );
        }

        if ( !function_exists( 'exec' )) {
            $this->lastFsStatus = 126;
            return false;
        }

        $output = array();
        $status = 0;
        exec( $command . ' 2>&1', $output, $status );
        $this->lastFsStatus = $status;
        return implode( "\n", $output );
    }

    /*
     * List the contents of a folder as a set of javascript
     * variable declarations.
     *
     */
    public function get_foldercontents_js( $showHidden=false )
    {
        $id = 0;
        $files = '';

        if ( is_file( $this->path )) {
            $path = dirname( $this->path );
        } else {
            $path = $this->path;
        }

        if ( !$this->makePathAFSlocal( $path )) {
            $this->errorMsg = "Unable to view: $this->path.";
            return false;
        }

        if ( !@is_dir( '.' )) {
            @chdir( $this->startCWD );
            return false;
        }

        // Open the path and read its contents
        if ( !$dh = @opendir( '.' )) {
            $this->errorMsg = "Unable to view: $this->path.";
            @chdir( $this->startCWD );
            return false;
        }

        while ( $filename = readdir( $dh )) {
            clearstatcache();
            if ( !$fileStats = @lstat( $filename )) {
                $modTime = '';
                $size = 0;
            } else {
                $modTime = $fileStats['mtime'];
                $size = $fileStats['size'];
            }

            //$mimeType = Mime::getMimeType( $filename );
            //$mimeIcon = Mime::getIcon( $mimeType, $filename );
            $filename = $this->escape_js( $filename );

            $viewable = 0;

            //if ( Mime::getPreviewType( $mimeType ) || @is_dir( $filename )) {
                //$viewable = 1;
            //}

            if ( $showHidden || strpos( $filename, '.' ) !== 0 ) {
                $files .= "files[$id]=new File('$filename', '$modTime', $size, "
                  . "'', '$mimeIcon', $viewable);\n";
            }

            $id++;
        }

        closedir( $dh );
        @chdir( $this->startCWD );
        return $files;
    }

    function get_foldername()
    {
        return basename( $this->path );
    }

    function get_returnToURI()
    {
        return ( 'https://' .
                  $_SERVER['HTTP_HOST'] .
              $_SERVER['PHP_SELF'] .
              "?path=" .
              urlencode($this->path) .
              "&" .
              "finishid=" .
                  $this->sid );
    }

    /*
    * Return a string escaped for a javascript string literal.
    */
    function escape_js( $string )
    {
        $output = "";

        $length = strlen( $string );
        for( $i=0; $i<$length; $i++ )
        {
            $c = $string[$i];
            switch( $c )
            {
                case '\'':
                    $output .= '\\\'';
                    break;
                case '\\':
                    $output .= '\\\\';
                    break;
                case "\n":
                    $output .= '\\n';
                    break;
                case "\r":
                    $output .= '\\r';
                    break;
                default:
                    $output .= $c;
                    break;
            }
        }

        return $output;
    }

    /* An initial check to make sure the path is in AFS.  This is an initial
     * check only.  To avoid race conditions, other precaustions must be used.
     * CAUTION: This method will be removed in the next major release.
     */
    protected function pathSecurity( $path='' )
    {
        if ( !$this->afsAvailable || empty( $path )
          || !is_array( $this->afsStat )) {
            return false;
        }

        if ( function_exists( 'fm_guard_config' )
          && function_exists( 'fm_guard_existing' )
          && fm_guard_config() !== false ) {
            $safePath = fm_guard_existing( $path );
            return $safePath === false ? false : rtrim( $safePath, '/' );
        }

        /* Native compatibility fallback when no configured root guard exists.
         * Container deployments always take the guard path above. */
        clearstatcache();

        if ( !$pathStat = @stat( $path )) {
            return false;
        }

        if ( !$this->afsDeviceIsAllowed( $path, $pathStat['dev'] )) {
            return false;
        }

        // Remove the final / in the target path if it exists
        return rtrim( $path, '/' );
    }


    public function makePathAFSlocal( $path )
    {
        if ( !$this->afsAvailable || !is_array( $this->afsStat )) {
            $this->errorMsg = 'AFS is not mounted.';
            return false;
        }

        $guarded = function_exists( 'fm_guard_config' )
            && function_exists( 'fm_guard_existing' )
            && fm_guard_config() !== false;
        $safePath = $guarded ? fm_guard_existing( $path, 'dir' ) : $path;
        if ( $safePath === false || !@chdir( $safePath )) {
            $this->errorMsg = "Couldn't change directory";
            return false;
        }

        clearstatcache();
        $stat = @stat( '.' );
        $current = @realpath( '.' );
        $current = $current === false ? false : rtrim( $current, '/' );
        if ( !is_array( $stat ) || $current === false
          || !$this->afsDeviceIsAllowed( $current, $stat['dev'] )
          || ( $guarded && fm_guard_existing( $current, 'dir' ) === false )) {
            $this->errorMsg = "Path not in AFS";
            @chdir( $this->startCWD );
            return false;
        }

        return true;
    }


    // Checks to see if there is a folder at the current path
    function folderExists( $path='' )
    {
        $path = ( $path ) ? $path : $this->path;
        return is_dir( $path );
    }


    public function linkSafeFileExists( $path )
    {
        clearstatcache();

        if ( is_array( @lstat( $path ))) {
            return true;
        } else {
            return false;
        }
    }


    // Set the afs path used inside the class
    function setPath( $path='' )
    {
        $safePath = $this->pathSecurity( $path );
        if ( !$safePath ) {
            $this->path = '';
            $this->errorMsg = 'Path not in AFS';
            return false;
        }

        $this->path = $safePath;
        return true;
    }

    public function isAvailable()
    {
        return $this->afsAvailable;
    }

    // Makes each piece of a file path clickable
    function pathDisplay()
    {
        if ( empty( $this->path )) {
            return '';
        }

        $path     = preg_replace( '/^\/afs\//', '', $this->path );
        $path     = explode( '/', $path );
        $lastItem = array_pop( $path );
        $pathDisp = '/afs';
        $pathURI  = '/afs';
        $lastDisp = '';
        $lastURI  = '';

        foreach ( $path as $piece ) {
            $pathURI  .= "/$piece";
            $pathDisp .= "/<a href=\"/?path=" . rawurlencode( $pathURI ) . "\">"
                    . htmlentities( $piece ) . "</a>";
        }

        $pathURI  .= $lastURI;
        $pathDisp .= $lastDisp;

        return $pathDisp . '/' . htmlentities( $lastItem );
    }

    // Make smarty template variable assignments
    function make_smarty_assignments(&$smart)
    {
        $smart->assign( 'path_url', urlencode($this->path));
        $smart->assign( 'parentPath', urlencode($this->parPath ));
        $smart->assign( 'location', $this->pathDisplay());
    }

    function get_js_declarations()
    {
        $retstr = "";

        $retstr .= $this->js_var( "path", $this->path );
        $retstr .= $this->js_var( "foldername", $this->get_foldername( ));
        $retstr .= $this->js_var( "folderIcon", "" );
        $retstr .= $this->js_var( "homepath", $this->path );
        $retstr .= $this->js_var( "sid", $this->sid );
        $retstr .= $this->js_var( "returnToURI", $this->get_returnToURI( ));
        $retstr .= $this->js_var( "adminPriv", $this->adminPriv);
        $retstr .= $this->js_var( "deletePriv", $this->deletePriv);
        $retstr .= $this->js_var( "insertPriv", $this->insertPriv );
        $retstr .= $this->js_var( "lockPriv", $this->lockPriv );
        $retstr .= $this->js_var( "readPriv", $this->readPriv );
        $retstr .= $this->js_var( "lookupPriv", $this->lookupPriv );
        $retstr .= $this->js_var( "writePriv", $this->writePriv );
        $retstr .= "files = new Array();\n";
        $retstr .= $this->get_foldercontents_js( true );

        return $retstr;
    }

    private function js_var( $varname, $contents )
    {
        $retstr = "";
        $retstr .= "var $varname = " .
            ( is_string( $contents ) ?
                "'" . $this->escape_js( $contents ) . "'"
                : $contents )
            . ";\n";
        return $retstr;
    }

}
