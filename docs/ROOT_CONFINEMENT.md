# Filesystem root confinement

Tiny File Manager treats the configured root as a security boundary. Every
user-controlled file route resolves through `lib/fm_root_confinement.php`.

The guard:

- canonicalizes the configured root and rejects lexical or resolved paths
  outside it;
- permits a symbolic link only when its resolved target remains inside the
  root;
- requires existing objects and opened handles to remain on the root device by
  default;
- rejects nested Linux mountpoints, including same-device bind mounts reported
  by `/proc/self/mountinfo`;
- rechecks the device and inode after opening read/write handles and delays
  truncation until that check succeeds;
- omits escaping links and nested mounts from listing and search results;
- sends direct file links back through an application-mediated streaming
  route; and
- validates archive members and extracts them one at a time instead of using
  whole-archive `extractTo()` operations.

In-root links and normal same-filesystem operation remain available. An
escaping link may be unlinked, renamed, or moved as a directory entry, but it
is never followed for data access. Nested mounts are intentionally unavailable
through the file manager.

Some AuriStor Linux clients report a different `st_dev` after crossing an AFS
volume mount point even though no nested Linux VFS mount exists. An AFS
deployment that has independently verified this provider behavior may opt in
before the guard is loaded (normally from `config.php`):

```php
define('FM_ROOT_GUARD_ALLOW_AFS_DEVICE_TRANSITIONS', true);
```

The option is strictly off by default. It permits only descendant device
transitions; the configured root must retain its initial device. Canonical-path
containment, escaping-symlink rejection, opened-handle identity checks, and
`/proc/self/mountinfo` nested-mount rejection remain enforced.

Linux deployments must expose a readable `/proc/self/mountinfo` to PHP. The
guard fails closed when that interface is unavailable because it could not
otherwise distinguish a same-device bind mount from an ordinary directory.

## Tests

Run the dependency-free behavior, static-route, and HTTP-route tests:

```sh
php tests/root_confinement.php
php tests/root_confinement_afs_multidevice.php
php tests/root_confinement_static.php
php -d phar.readonly=0 tests/http_route_confinement.php
php tests/afs_io_path_audit.php
```

The HTTP test starts a loopback-only PHP development server over synthetic
temporary files. It exercises list, view, download, direct streaming, create,
upload, edit, copy, move, rename, delete, TAR creation, and TAR extraction,
including a link to a synthetic delegated credential cache outside the root.

ZIP behavior additionally requires the PHP `zip` extension. The same member
validation and guarded extraction path is used for ZIP and TAR.
