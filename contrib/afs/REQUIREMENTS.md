# AFS implementation and security requirements

> **Status:** [`README.md`](README.md) supplies a separate, pinned AFS
> prototype and deployment recipe built against the OpenAFS 1.8 userspace ABI.
> It remains a trusted-intranet,
> non-production profile until the documented live browser/KDC/AFS gate passes
> on the target environment.

Add this layer only after the
[`contrib/haproxy`](../haproxy/README.md) stack—which includes Tiny File
Manager—is healthy and a real Kerberos browser sign-on has passed. Do not
modify or replace the working deployment while developing the AFS layer.

At a glance: the AFS layer is Linux-only, a bind mount alone is insufficient,
per-user access requires full browser delegation, and the supplied
OpenAFS-linked profile still requires target-site acceptance testing. It can
potentially interoperate with AuriStorFS servers only through their documented
OpenAFS-client/RxKAD path and its security and data-model restrictions; native
AuriStorFS/YFS and rxgk are separate provider targets and are not supplied
here.

## Keep the deployment layered

| Layer | Responsibility | Supplied here |
| --- | --- | --- |
| [Base](../container/README.md) | Tiny File Manager, fixed data root, and local storage | Yes |
| TLS and sign-on | HAProxy TCP pass-through, Apache TLS, Kerberos authentication, and an exact user allowlist | Yes |
| AFS | Delegated user credential, PAG and token lifecycle, host AFS mount, and restricted file operations | Prototype; live gate required |

The HAProxy layer can remain unchanged: it passes the original TLS connection
to Apache. The AFS layer must be a separately named child image and separate
Compose configuration. Pin its base image and dependencies; do not overwrite
or retag the working non-AFS images.

## Why a bind mount is not enough

The current stack deliberately passes only the authenticated, realm-qualified
`REMOTE_USER` string to PHP. It does not pass a ticket, credential cache,
keytab, PAG, or AFS token. Without an AFS token, the host Cache Manager treats
the Apache worker as anonymous and applies only the rights granted to
`system:anyuser`.

The current stack also has constraints that an AFS profile must replace:

- Apache uses an accept-only `gssproxy` service and does not export delegated
  credentials.
- The application container has no KDC network path.
- Startup requires anonymous UID 33 to read, write, and search the data root.
- The image has no AFS user-space libraries, tools, or `mod_waklog` module.
- The healthcheck tests HTTPS and PHP, not the managed filesystem.
- Tiny File Manager's AFS profile rejects visible symlink components, but that
  pathname check is race-prone and does not identify nested AFS volume mount
  points. **Change Permissions** uses POSIX `chmod`, not AFS ACLs, so the
  profile disables it.

`$global_readonly` and `$readonly_users` block Tiny File Manager's intentional
mutations, but they do not create an AFS identity or ACL boundary; startup still
requires UID 33 write access. `$directories_users` is rejected by this image
and would not provide a user credential. There is no AFS ACL route or separate
switch for only chmod, archive, copy, move, or delete.

Do not solve these constraints by granting broad `system:anyuser` rights,
running a Cache Manager in the web container, using `--privileged`, or mounting
the entire host `/afs` tree.

## Choose a credential design

The supplied profile implements Design A. Design B remains an alternative
architecture, not a supplied deployment mode.

### Design A: direct browser delegation

Apache uses native `mod_auth_gssapi`, reads the dedicated HTTP keytab itself,
and exports a native delegated `FILE:` credential cache for the request.
`mod_waklog` uses that cache to acquire the configured `afs/<cell>` service
ticket and installs an AFS token in the request worker's PAG.

This is the shortest path to a prototype, but it removes the current keytab
isolation. Apache and in-process PHP share a worker security context, so a PHP
compromise can read the HTTP keytab and delegated user credentials. Treat that
as an explicit security-boundary change.

For this profile:

- Add a separately named `afs` target derived from the Dockerfile's `common`
  stage, or an equivalently pinned image. Do not derive from the `gssapi`
  target: it installs proxymech and a fail-closed proxy-specific entrypoint.
- Install the pinned `mod_auth_gssapi` and SSL modules, and retain the TLS
  vhost, port, authorization, and health settings from the `gssapi` target,
  without installing proxymech.
- Supply an AFS-specific entrypoint instead of both current entrypoints. Retain
  their configuration, TLS, and allowlist checks, but omit the gssproxy socket
  checks and the generic anonymous data-root access probe. Remove
  `GSS_USE_PROXY`, `GSSPROXY_SOCKET`, `GSSPROXY_BEHAVIOR`, and
  `KRB5_KTNAME=/run/no-local-keytab`; validate the direct keytab and AFS mount
  instead.
- Use a separate Compose file that omits the `gssproxy` service, the
  application's `depends_on: gssproxy`, and the shared proxy socket volume.
  Keep the internal Tiny File Manager service and TLS port expected by HAProxy.
  Avoid version-dependent Compose merge/reset behavior.
- Bind a dedicated directory containing only the operator-supplied
  `http.keytab` read-only at `/run/secrets-source`. At each container start,
  have the AFS entrypoint validate it and copy it as mode 0400, UID 33, into a
  private tmpfs mounted at `/run/tinyfilemanager-keytab`. Do not use a
  persistent runtime volume, and never mount `/etc/krb5.keytab`.
- Mount a second private, bounded tmpfs at `/run/tinyfilemanager-auth`. Create
  `clientcaches` and `rcache` below it as UID/GID 33, mode 0700. Set
  `KRB5RCACHEDIR=/run/tinyfilemanager-auth/rcache`; never disable the Kerberos
  replay cache with `KRB5RCACHETYPE=none`. Delegated cache files must be unique
  and mode 0600. The patched module must use opaque cache filenames and must
  never log or persist their paths or contents.
- Keep the exact `AuthGroupFile`/`Require group` authorization policy from the
  HAProxy stack.

Apache and in-process PHP can read both the runtime HTTP keytab and delegated
caches in this design. The credential tmpfs is private to the container, not to
one worker: all Apache/PHP workers share UID 33 and can read live caches. The
startup copy limits what is copied; it does not restore the gssproxy isolation
boundary.

The supplied Apache authentication block is:

```apache
<Location "/">
    AuthType GSSAPI
    AuthName "Tiny File Manager"
    GssapiAcceptorName HTTP@files.example.com
    GssapiCredStore keytab:/run/tinyfilemanager-keytab/http.keytab
    GssapiDelegCcacheDir /run/tinyfilemanager-auth/clientcaches
    GssapiDelegCcacheUnique On
    GssapiDelegCcacheEnvVar KRB5CCNAME
    GssapiDelegCcachePerms mode:0600 uid:www-data gid:www-data
    GssapiAllowedMech krb5
    GssapiBasicAuth Off
    GssapiConnectionBound Off
    GssapiLocalName Off
    GssapiNegotiateOnce On
    GssapiSSLonly On
    GssapiUseSessions Off
    AuthGroupFile /run/policy/authorized-users
    AuthzSendForbiddenOnFailure On
    Require group tinyfilemanager
</Location>
```

Replace `files.example.com` with the exact certificate/SPN host. Keep sessions
off so every request establishes a credential context. The client must present
a forwardable credential and explicitly delegate it to this HTTP service;
successful Kerberos authentication alone does not prove delegation.

The pinned `mod_auth_gssapi` does not provide TLS channel binding or AD
Extended Protection. Delegating a reusable TGT increases the impact of a relay
or Apache/PHP compromise. Do not use Design A where those protections are
required. Browser delegation policy limits which HTTP service receives the
credential; it does not constrain which services a compromised HTTP service
can request tickets for with that TGT.

The supplied `mod_auth_gssapi` patch uses opaque cache names and removes the
principal/cache pathname from delegated-cache failure paths. Other module or
GSS debug paths can still expose identity or configuration details. Keep
Apache, `mod_auth_gssapi`, `mod_waklog`, and Kerberos debug/trace logging
disabled and include negative log scans in validation.

`GssapiUseS4U2Proxy` is not a drop-in alternative. It exports an evidence
ticket for a GSSAPI-aware application, while unmodified `mod_waklog` reads a
credential cache directly with libkrb5.

### Design B: GSSAPI-aware AFS implementation

Retain the current keytab-isolating `gssproxy` design and implement a maintained
in-process module that uses a supported provider/gssproxy token interface and
installs the token in the Apache worker's request-specific PAG. An alternative
broker must perform every filesystem operation itself; a sidecar cannot install
a token into another process's PAG. Either design needs authenticated,
request-bound IPC plus replay and cross-worker isolation. Portable GSSAPI alone
does not expose all material needed to construct a legacy AFS token. This is a
research architecture; no proven implementation is supplied here.

Even changing `filter_flags` to `-DELEGATE` and adding delegated-cache export is
insufficient. A proxy-managed credential cache is not a native delegated TGT
that unmodified `mod_waklog` can consume directly with libkrb5; see the pinned
[gssproxy credential-storage source](https://github.com/gssapi/gssproxy/blob/675b592d74c66f5fae5285926d00e6d0cec70e43/src/mechglue/gpp_creds.c#L137-L228).

## How the separate AFS image is built

The supplied image specifically builds against Debian's OpenAFS 1.8.9
userspace ABI and expects an OpenAFS host Cache Manager. An AuriStorFS server is
within scope only through its documented OpenAFS-client/RxKAD interoperability
path. That path must not require RxGK, keyed Cache Managers, combined tokens,
AES-256/SHA-1 wire privacy, or data semantics hidden from an OpenAFS client;
the operator guide makes these target-subtree restrictions an explicit gate. A
native AuriStorFS Cache Manager uses different provider libraries,
configuration, mount type, and web-module integration; it requires a separate
image and the same isolation and live-validation gates.

The supplied multi-stage build pins the base image by digest, module sources by
checksum, and its security-relevant Debian ABI packages by version. Generic
build tools and live Debian mirrors are not fully pinned, so this is not a
bit-for-bit reproducible build. A release process must use a retained package
snapshot and pin every input. The builder needs the Apache development files,
compiler/autotools, Kerberos development files, and the development package
for the exact AFS client used on the host. It must build both the supplied
`mod_auth_gssapi` delegated-cache patch and the `mod_waklog` hardening patches.
The runtime image needs only:

- the reviewed `mod_waklog` DSO and its matching AFS/Kerberos libraries;
- read-only runtime mounts for the AFS cell and Kerberos configuration; and
- the existing Tiny File Manager application and Apache modules.

Keep `fs`, token inspection, and other administrative tools on the host or in
a separate non-serving diagnostic image unless the runtime module demonstrably
requires them.

Do not include a kernel Cache Manager, compiler, source tree, host keytab, TLS
key, ticket cache, or token in the runtime image. Record the source version,
checksum, patches, license, SBOM, Apache module ABI, and AFS library ABI.

The SourceForge [1.1.0 release evaluated
here](https://sourceforge.net/projects/modwaklog/files/modwaklog/1.1.0/) is
dated 2015-08-01. Do not ship it unchanged. A maintained fork or hardening patch
must, at minimum:

- create and verify a fresh PAG before each protected request;
- require a unique, regular, worker-owned mode-0600 `FILE:` cache beneath one
  fixed tmpfs directory;
- verify that the cache principal equals the authenticated GSSAPI principal;
- reject missing, expired, symlinked, mismatched, and `X-GSSPROXY` caches;
- request only the configured `afs/<cell>@REALM` service;
- disable shared token caches, default/location principals, password
  acquisition, and background renewal; eliminate or strictly constrain the PTS
  name-to-ID lookup and its network dependency;
- register cleanup before installing the token, remove the token and cache on
  every success/error/abort path, and terminate the worker if cleanup cannot
  be proven; and
- return a generic error without logging principals, cache names, tickets,
  tokens, or keytab details.

Public 1.1.0 has process-wide token state, an unimplemented token-cache-disable
directive, incomplete identity binding, and cleanup paths that are not adequate
for a multi-user web service. Configuration alone does not correct those
properties.

The supplied vhost uses this user-token configuration at virtual-host scope;
these directives are not valid inside `<Location>`:

```apache
<VirtualHost *:8443>
    WaklogAFSCell example.org
    WaklogAFSCellRealm EXAMPLE.ORG
    WaklogEnabled On
    WaklogUseUserTokens On
    # Retain the complete GSSAPI and AuthGroupFile block here.
</VirtualHost>

<VirtualHost 127.0.0.1:8081>
    WaklogAFSCell example.org
    WaklogAFSCellRealm EXAMPLE.ORG
    WaklogEnabled Off
    WaklogUseUserTokens Off
    # Grant only /healthz.php on this loopback-only vhost.
</VirtualHost>
```

Replace the cell and realm. Do not configure `WaklogDefaultPrincipal` or
`WaklogLocationPrincipal`; those create a shared service identity instead of
per-user access. Do not rely on `WaklogDisableTokenCache`, which the public
release labels as unimplemented.

## Preserve the request's PAG

Use Apache's non-threaded `prefork` MPM, HTTP/1.1, and in-process
`apache2handler` PHP. Refuse startup with `event`/`worker` MPM, HTTP/2, CGI,
FastCGI, or PHP-FPM. The code that creates the PAG, installs the token, and
performs the PHP filesystem operation must execute in the same worker process.

Do not add capabilities speculatively. Prove the required PAG/token syscalls
with the production kernel, AFS client, container runtime, capability set, and
seccomp policy. Fail closed if PAG creation or token removal fails.

## Mount the host AFS subtree

This layer is limited to a rootful Linux Docker Engine host with a supported,
patched, and healthy host-managed AFS Cache Manager. Docker Desktop on macOS or
Windows, rootless Docker, user-namespace remapping, and multiple replicas are
not covered.

Before starting the AFS profile:

1. Mount AFS on the host and verify its filesystem type, cell/volume, health,
   and exact source with `findmnt` and the provider's tools.
2. Prove the Docker daemon can resolve and bind that exact source without a
   user token and without widening `system:anyuser` data or write rights. If it
   requires credentials in the daemon or broader ACLs, this profile is not
   supported.
3. Select one dedicated subtree. Confirm that it contains no out-of-scope
   symlinks or nested AFS mount points.
4. Keep `$root_path` at `/srv/tinyfilemanager/data`, and bind only the selected
   subtree there with
   `bind.create_host_path: false`.
5. Do not use `compose.host-path.yaml`: its private SELinux `Z` relabel and
   UID-33 permission instructions are for ordinary local files, not AFS.
6. Do not run recursive `chown`, `chmod`, or POSIX ACL commands on the AFS
   tree. Configure AFS ACLs with the provider's tools and disposable test data.

The supplied entrypoint verifies an AFS mount is present without traversing
protected content or demanding anonymous read/write access, and it checks that
the mounted cell configuration matches the requested cell. It cannot attest
the bind's cell or volume from filesystem type alone. The operator must verify
the exact source with `fs whichcell` and `fs examine` before every start; a
missing or unmounted source stops the container. Recreate the AFS container
after a host remount.

The supplied Compose file gives the application ordinary egress in addition to
the internal HAProxy backend. Before any production use, restrict that egress
to the required DNS, KDC, PTS, and AFS dependencies with host or network policy.
Do not expose an application port or mount the AFS path into HAProxy, the TLS
initializer, or a credential sidecar.

Run a separate host-side Cache Manager and mount readiness monitor. The
credential-free `/healthz.php` endpoint cannot detect an inaccessible or
stalled AFS namespace. Browser TLS also does not configure or attest encryption
between the host Cache Manager and AFS file servers.

Tiny File Manager's configured root is a pathname prefix, not an AFS
cell/volume boundary. The supplied guard rejects visible symlinks and disables
archive extraction and POSIX **Change Permissions**, but it is not
descriptor-rooted or race-free. Review recursive copy, move, delete, and search
against the exact target namespace.

## Configure delegation and AFS authorization

Complete these steps with the AD/Kerberos and AFS administrators:

1. Keep the certificate name, URL host, DNS record, and `HTTP/<host>` SPN
   identical.
2. Approve client forward delegation to this HTTP service under the
   organization's policy. This selects the recipient HTTP SPN; it is not
   downstream constrained delegation and does not restrict the delegated TGT
   to AFS. Do not enable Basic or NTLM fallback.
3. Configure managed browsers to authenticate to and delegate to the exact
   service. Browser policy is platform-specific; test the deployed policy.
4. Confirm the AFS service principal, Kerberos realm/cross-realm path, PTS
   mapping, clocks, DNS, and container KDC reachability. If the reviewed module
   retains its PTS lookup, allow only the required PTS egress; otherwise remove
   that dependency.
5. Grant each test principal only its intended AFS ACLs. Do not weaken
   `system:anyuser` to make startup pass.

Full delegation places a reusable user credential in a container-private tmpfs
shared by all UID-33 Apache/PHP workers. Limit access to managed intranet
clients and treat compromise of one worker as compromise of every live
delegated credential until it expires.

## Key rotation is profile-specific

The systemd helper in `contrib/haproxy/systemd` is designed for the supplied
gssproxy sidecar. Do not silently reuse it for Design A.

For direct delegation, the supplied separately named root-owned units export
only the exact HTTP principal to a dedicated directory, recreate the AFS
Apache container after replacement, and verify that its private keytab copy
matches the published keytab before recording success. Health checks do not
touch AFS; a real sign-on remains a separate operator validation gate. Keep
the existing current and any validated previous KVNO,
atomic-publication, private-permission, retry, and stopped-stack behavior from
the HAProxy guide. Never point a root unit at a developer-writable checkout.

For Design B, rotate and reload the credential-holding broker instead. Never
mount the full host keytab into any container.

## Required validation gate

Use a disposable AFS subtree and two test users with disjoint ACL canaries.
Do not expose diagnostics or use production data during validation.

- Prove the base HAProxy stack first: real Kerberos login, exact allowlist,
  TLS, no Basic/NTLM fallback, and healthy containers.
- Verify `auth_gssapi`, the reviewed `waklog` module, prefork MPM,
  `apache2handler` PHP, and HTTP/1.1 inside the exact image.
- Verify the host mount and container bind source before every start. A missing
  mount must fail closed; it must not reveal or use the underlying host path.
- Verify a delegated cache is native, unique, mode 0600, on tmpfs, bound to the
  authenticated principal, and removed after the request.
- Verify no delegation, an unauthorized user, an expired cache, a mismatched
  cache, or an anonymous caller receives an AFS token or reaches PHP file
  operations.
- Alternate users in a reused Apache child, then run them concurrently. Each
  user must reach only their own canary, with no token/PAG crossover.
- Test token and cache cleanup after success, denial, client abort, logout,
  child recycling, and container restart. Browser logout does not itself
  revoke a Kerberos credential or kernel AFS token.
- Exercise create, read, edit, upload, download, copy, move, rename, and delete
  on the disposable subtree. Confirm the archive and **Change Permissions**
  routes stay hidden and rejected, then confirm ACL behavior and outside-root
  denial.
- Test KDC failure, AFS server failure/stall, token expiry, HTTP-key rotation,
  host remount, and recovery. The ordinary `/healthz.php` endpoint must remain
  credential-free and is not an AFS readiness check.
- Scan image layers, logs, and artifacts for keytabs, caches, tickets, tokens,
  principals, and private pathnames.

Only a real browser/KDC/AFS test can establish this layer. The repository build
does not substitute for that deployment-specific validation.

## Rollback requirements

The AFS profile must have a tested rollback that stops only its
containers and recreates the unchanged HAProxy stack from
`contrib/haproxy/compose.yaml`. It must not use `down -v` or delete host AFS
data. Keep its image, Compose project, and volumes distinct from the base
stack, and document the complete Compose-file list for every command so an
operator cannot silently switch storage backends.

## References

- [Pinned `mod_auth_gssapi` 1.6.4-3 documentation](https://sources.debian.org/data/main/liba/libapache2-mod-auth-gssapi/1.6.4-3/README)
- [Pinned gssproxy 0.9.1 Apache guide](https://sources.debian.org/data/main/g/gssproxy/0.9.1-1/docs/Apache.md)
- [gssproxy configuration](https://manpages.debian.org/bookworm/gssproxy/gssproxy.conf.5.en.html)
- [`mod_waklog` 1.1.0 files](https://sourceforge.net/projects/modwaklog/files/modwaklog/1.1.0/)
- [`mod_waklog` 1.1.0 source evaluated here](https://sourceforge.net/p/modwaklog/code/ci/6c19351b63207837b6b06aec973ac025a48f9945/tree/)
- [OpenAFS user authentication and PAGs](https://docs.openafs.org/UserGuide/HDRWQ20.html)
- [Debian source for the pinned OpenAFS 1.8.9 packages](https://sources.debian.org/src/openafs/1.8.9-1%2Bdeb12u1/)
- [OpenAFS security advisories](https://openafs.org/frameless/security/)
- [AuriStorFS migration and AFS3 compatibility](https://www.auristor.com/documentation/man/linux/7/auristor_migration.html)
- [AuriStorFS deployment interoperability matrix](https://www.auristor.com/openafs/migrate-to-auristor/auristor-deployment-strategy)
- [AuriStorFS `asetkey` documentation](https://www.auristor.com/documentation/man/linux/8/asetkey.html)
- [AuriStorFS `fs getcrypt` and RxKAD/FCRYPT limits](https://www.auristor.com/documentation/man/linux/1/fs_getcrypt.html)
- [Apache prefork MPM](https://httpd.apache.org/docs/current/en/mod/prefork.html)
- [Docker bind-mount behavior](https://docs.docker.com/engine/storage/bind-mounts/)
