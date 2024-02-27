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
    public  $confirmMsg   = '';
    public  $errorMsg     = '';
    public  $notifyMsg    = '';
    public  $parPath;     // Path to the parent of current path
    public  $filename     = '';
    public  $adminPriv    = 0;
    public  $deletePriv   = 0;
    public  $insertPriv   = 0;
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
    protected $newName    = '';
    protected $startCWD   = '';

    public function __construct( $path="" )
    {
        $this->uniqname = $_SERVER['REMOTE_USER'];
        $this->startCWD = getcwd();
        $this->afsStat  = stat('/afs/');

        // Bug 2634811 Fixed: Make sure /afs isn't on the local filesystem
        $rootStat = stat( '/' );

        if ( $this->afsStat['dev'] == $rootStat['dev'] ) {
            error_log( "/afs has same device ID as / " .
                "(is afs actually mounted?): $this->uniqname, " .
                "$this->errorMsg " . __FILE__ );
            //header( 'Location: /missinghomedir.php' );
            $this->errorMsg = 'Missing home directory.';
            return false;
        }

        // Bug 1975875 Fixed: Don't trim whitespaces from path
        $this->setPath( $path );

        // Generate the path of the folder one level above the current
        if ( !preg_match( "/(.*\/)([^\/]+)\/?$/", $this->path, $Matches )) {
            error_log( "missing homedir: [$this->path] $this->uniqname, " .
                "$this->errorMsg " . __FILE__ );
            //header( 'Location: /missinghomedir.php' );
            $this->errorMsg = 'Missing home directory.';
            return false;
        }
        $this->parPath  = $Matches[1];
        $this->filename = $Matches[2];

        if ( !isset( $_SESSION['formKey'] )) {
            $_SESSION['formKey'] = md5( uniqid( rand(), true ));
        }

        $this->formKey = $_SESSION['formKey'];
        $this->sid     = md5( uniqid( rand(), true ));
        ////$this->type    = $this->getType();

        $this->processCommand();
        $this->getACLAccess( $this->path );
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
                $this->mimetype = Mime::getMimeType( basename( $this->path ));
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

            if ( !mkdir( trim( basename( $this->selectedItems )), 0644, true )) {
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

        if ( !@filedrawers_rename( basename( $this->selectedItems ),
                $newName, '/afs' )) {
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

            if ( !@filedrawers_rename( $sourcePath, $destPath, '/afs' )) {
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

            // Security checks are in Afs::copy() and Afs::copy_dirs
            $sourcePath = $this->originPath . '/'. $file;
            $destPath   = $this->path . '/' . $file;

            if ( filetype( $sourcePath ) == 'dir' ) {
                if ( !$this->copy_dirs( $sourcePath, $destPath )) {
                    $this->errorMsg = "Unable to copy $file.";
                    return false;
                }
            } else if ( !$this->copy( $sourcePath, $destPath )) {
                $this->errorMsg = "Unable to copy $file.";
                return false;
            }

            $this->notifyMsg = "Pasted the contents of the clipboard.";
        }
    }


    /* A helper function for copyFiles().  Copies an entire directory at once.
     * Original author: swizec at swizec dot com, php.net
     */
    public function copy_dirs( $source, $target )
    {
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

            // Security checks are in Afs::copy() and Afs::copy_dirs
            if ( filetype( $sourcePath ) == 'dir' ) {
                if ( !$this->copy_dirs( $sourcePath, $targetPath )) {
                    @chdir( $this->startCWD );
                    return false;
                }
            } else if ( !$this->copy( $sourcePath, $targetPath )) {
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

            if ( !symlink( $target, $name )) {
                @chdir( $this->startCWD );
                return false;
            }

            @chdir( $this->startCWD );
            return true;
        }

        if ( !( $sourceHdl = @fopen( $source, "rb" ))) {
            @chdir( $this->startCWD );
            return false;
        }

        $sourceStat = fstat( $sourceHdl );

        if ( $sourceStat['dev'] != $this->afsStat['dev'] ) {
            @chdir( $this->startCWD );
            return false;
        }

        if ( !$this->makePathAFSlocal( dirname( $dest ))) {
            @chdir( $this->startCWD );
            return false;
        }

        // If you want copy to overwrite, then do unlink(basename($dest)) here
        if ( !( $destHdl = @fopen( basename( $dest ), "xb" ))) {
            @chdir( $this->startCWD );
            return false;
        }

        while ( !feof( $sourceHdl )) {
            $buffer = fread( $sourceHdl, 1024 * 1024 );
            fwrite( $destHdl, $buffer );
        }

        @fclose( $sourceHdl );
        @fclose( $destHdl );
        @chdir( $this->startCWD );

        return true;
    }


    // A AFS safe version of the PHP readfile builtin - this will only
    // read files which are hosted in AFS.
    function readfile()
    {
        clearstatcache();

        if ( $handle = @fopen( $this->path, "rb" )) {
            $stat = fstat( $handle );
            if ( $stat['dev'] == $this->afsStat['dev'] ) {
                while ( !feof( $handle )) {
                    $buffer = fread( $handle, 1024 * 1024 );
                    echo $buffer;
                }
            }

            @fclose( $handle );
        }
    }

    // Change the ACL for a given path
    function changeAcl($entity,
                       $rights,
                       $path='',
                       $recursive=false,
                       $negative=false )
    {
        $entity   = escapeshellarg( $entity );
        $rights   = escapeshellarg( trim( $rights ));
        $path    = ( $path ) ? $path : $this->path;
        $neg      = ( $negative ) ? ' -negative' : '';
        $cmd      = "$this->afsUtils/fs sa $neg " . escapeshellarg( $path ) .
        " $entity $rights";
        $cmdRecur = "find " . escapeshellarg( $path ) . " -type d -exec " .
            "$this->afsUtils/fs sa $neg {} $entity $rights \\;";
        $cmd      = ( $recursive ) ? $cmdRecur : $cmd;

        if ( !$path ) {
            return false;
        }

        if ( strpos( shell_exec( $cmd . " 2>&1" ), 'fs:' ) !== false ) {
            $this->errorMsg =
                "Warning: Unable to modify the access control list.";
            return false;
        }

        return true;
    }

    // Return an array of ACL rights for the current path
    function readAcl( $path='' )
    {
        $path = ( $path ) ? $path : $this->path;
        $cmd = "$this->afsUtils/fs listacl " . escapeshellarg( $path );
        $result = shell_exec( $cmd . " 2>&1" );
        $rights = array( 'l', 'r', 'w', 'i', 'd', 'k', 'a' );

        if ( !$path ) {
            return false;
        }

    if ( preg_match( '/^fs:/', $result )) {
        $this->errorMsg =
            "Warning: Unable to read the access control list.";
            return false;
        }

        $result   = preg_replace( "/(.*)is\n(.*)rights:\n/", "", $result );
        $result   = explode( "\nNegative rights:\n", $result );

        if ( isset( $result[0] )) {
            $normal = explode( "\n", trim( $result[0] ));
            if ( is_array( $normal )) {
                foreach ( $normal as $item ) {
                    $perm = explode( ' ', trim( $item ));
                    $setRights = $perm[1];
                    foreach ( $rights as $right ) {
                        if ( strpos( $setRights, $right ) !== false ) {
                            $result['normal'][$perm[0]][$right] = true;
                        } else {
                            $result['normal'][$perm[0]][$right] = false;
                        }
                    }
                }
            }
        }

        if ( isset( $result[1] )) {
            $negative = explode( "\n", trim( $result[1] ));
            if ( is_array( $negative )) {
                foreach ( $negative as $item ) {
                    $perm = explode( ' ', trim( $item ));
                    $setRights = $perm[1];
                    foreach ( $rights as $right ) {
                        if ( strpos( $setRights, $right ) !== false ) {
                            $result['negative'][$perm[0]][$right] = true;
                        } else {
                            $result['negative'][$perm[0]][$right] = false;
                        }
                    }
                }
            }
        }

        return $result;
    }

    function getACLAccess( $path ) 
    {
        if ( empty( $path )) {
            return false;
        }

        $cmd = "$this->afsUtils/fs getcalleraccess " . escapeshellarg( $path );
        $result = shell_exec( $cmd . " 2>&1" );

        $acls = '';
        if ( preg_match( "/^Callers access to .* is (\w{1,7})$/", 
                $result, $Matches )) {
            $acls = strtolower( $Matches[1] );

            if ( strpos( $acls, 'l' ) !== false ) {
                $this->lookupPriv = 1;
                if ( strpos( $acls, 'a' ) !== false ) {
                    $this->adminPriv = 1;
                }
                if ( strpos( $acls, 'd' ) !== false ) {
                    $this->deletePriv= 1;
                }
                if ( strpos( $acls, 'i' ) !== false ) {
                    $this->insertPriv = 1;
                }
                if ( strpos( $acls, 'r' ) !== false ) {
                    $this->readPriv = 1;
                }
                if ( strpos( $acls, 'w' ) !== false ) {
                    $this->writePriv = 1;
                }
            }
        }
        return $acls;
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
    private function pathSecurity( $path='' )
    {
        if ( empty( $path )) {
            return false;
        }

        /* The path is only safe if we're in AFS at the end of it.
         * This test is raceable - so we should check again before sending
         * anything to the client.
         */
        clearstatcache();

        if ( !$pathStat = @stat( $path )) {
            return false;
        }

        if ( $this->afsStat["dev"] !=  $pathStat["dev"] ) {
            return false;
        }

        // Remove the final / in the target path if it exists
        return preg_replace( '/\/$/', '', $path );
    }


    public function makePathAFSlocal( $path )
    {
        if ( !@chdir( $path )) {
            $this->errorMsg = "Couldn't change directory";
            return false;
        }

        clearstatcache();
        $stat = stat( '.' );
        if ( $this->afsStat["dev"] != $stat["dev"] ) {
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
        if ( $path == '/afs' || $path == '/afs/' ) {
            $path = null;
        }

        if ( !empty( $path )) {
            if ( !( $this->path = $this->pathSecurity( $path ))) {
                // Can't give this warning due to the current filedrawers
                // design. This should be fixed in the next release.
                // If a user navigates to a path that doesn't exist, they will
                // continue to get the warning until the url changes
                //$this->errorMsg = "The specified path does not exist. ($path)"
                $this->path = null;
            }
        }

        // Make sure the specified path was accepted
        if ( empty( $this->path )) {
            //GetHomeDir( $this->uniqname, $this->path, $this->errorMsg );
            $this->path = $this->pathSecurity( $this->path );
        }

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

