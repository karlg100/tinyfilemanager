# AFS container profiles

This is the final, optional layer after the
[`contrib/haproxy`](../haproxy/README.md) stack is healthy and a real browser
Kerberos sign-on succeeds. Both provider options require a separately named,
standalone HAProxy + Apache profile that converts a delegated user credential
into a per-request AFS token:

```text
browser -- TLS + delegated Negotiate --> HAProxy -- TCP --> Apache
                                                            | native FILE cache
                                                   provider token module
                                                            | PAG + token
                                                     host AFS mount
```

> **Deployment gate:** Option 1 has pinned source checksums and a pinned base
> image digest and has been built locally on arm64 and amd64. Debian package
> mirrors remain live inputs. Option 2 supplies public assembly and runtime
> checks, but it cannot be built without an operator-supplied, digest-pinned
> private image containing the licensed native provider. A loopback-only local
> registry keeps that image on one host; an approved private registry is
> optional for multiple hosts. Neither option is established by an image
> build: this repository cannot perform your AD,
> browser, KDC, identity, Cache Manager, or file-server tests. Treat either as a
> trusted-intranet, non-production profile until its complete live gate passes.
> Direct delegation exposes the HTTP keytab and live delegated credentials to
> the Apache/PHP UID. Read [`REQUIREMENTS.md`](REQUIREMENTS.md) first.

## Option 1: OpenAFS 1.8

This is the supplied and runnable implementation. It uses
[`compose.yaml`](compose.yaml), the existing [`Dockerfile`](Dockerfile), an
OpenAFS 1.8 Cache Manager on the Linux host, and an `afs` filesystem bind. The
complete recipe is steps 1 through 8 below. Existing deployments and commands
remain unchanged. See the short [OpenAFS provider summary](providers/openafs/README.md).

[`sources.lock`](sources.lock) records this option's source, package, and ABI
inputs; the runtime image carries that manifest and the patch checksums for
inspection.

## Option 2: native AuriStor

This option is a separate native AuriStorFS/YFS provider for the exact
version-1 EL9 provider contract and an `auristorfs` mount. The supplied
private-provider Containerfile,
`Dockerfile.auristor`, and `compose.auristor.yaml` assemble the service from
operator-supplied offline licensed inputs. No AuriStor RPM, library, module, or
image is included here or may enter public Git, CI, artifacts, or registries.

Do not point Option 1 at an `auristorfs` mount, substitute AuriStor libraries
into its image, or relabel an OpenAFS image. Follow the complete
[native AuriStor recipe](providers/auristor/README.md); it includes the private
provider-image contract, configuration, build, start, validation, and rollback
steps.

### Option 1 provider scope

This guide uses **AFS** as the family name. The supplied image is specifically
built against the OpenAFS 1.8 userspace ABI and installs rxkad-k5 tokens through
an OpenAFS Cache Manager on the Linux host.

| Deployment | Status |
| --- | --- |
| OpenAFS 1.8 Cache Manager and OpenAFS servers using rxkad-k5 | Supplied implementation target; the live gate below is still required. |
| OpenAFS 1.8 Cache Manager and AuriStorFS servers that permit OpenAFS-client/RxKAD interoperability | Potential interoperability path; the AuriStor administrator must validate every restriction below, and the live gate is mandatory. |
| Native AuriStorFS/YFS Cache Manager, `yfs-rxgk`/rxgk, or AuriStor-only semantics | Not supplied by this image; requires a separate provider-specific build and validation. |

See AuriStor's [migration guide](https://www.auristor.com/documentation/man/linux/7/auristor_migration.html)
and [deployment strategy](https://www.auristor.com/openafs/migrate-to-auristor/auristor-deployment-strategy)
for the OpenAFS-client interoperability boundary. For this path:

- the AuriStor Location, Protection, and File services must not require RxGK,
  keyed Cache Managers, combined tokens, or AES-256/SHA-1 wire privacy; and
- the selected subtree must not depend on per-object ACLs, oversized volume
  IDs or directories, extended attributes, or alternate data streams hidden
  from an OpenAFS client, read-write replicas, directory ACL inheritance,
  mandatory locks, or other AuriStor-only semantics. Deleting a visible object
  can also delete hidden extended attributes or alternate data streams.

Have the AuriStor administrator approve the exact subtree and security policy
before testing. Renaming a provider or accepting an `auristorfs` mount would
not make this OpenAFS-linked module compatible with a native AuriStorFS Cache
Manager.

Option 1 otherwise assumes one rootful Linux Docker host, one container
replica, rxkad-k5, Apache prefork/mod_php, and HTTP/1.1. Docker Desktop,
rootless/user-namespace-remapped Docker, rxgk, and a Cache Manager inside the
container are not supported.

Steps 1 through 8 below are the preserved Option 1 recipe. Run every command
from the repository root and replace every example name. Option 2 has a
separate recipe so provider-specific commands and rollback stay isolated. The
endpoint section applies to both providers.

## 1. Prove the non-AFS stack first

Complete the [HAProxy guide](../haproxy/README.md). Verify HTTPS, a real
Kerberos login, the exact principal allowlist, rejection of an unlisted user,
and key rotation if SSSD is used. Keep that deployment available for rollback.

Record the exact working HAProxy environment, then create a separate AFS test
environment. These ignored files make cutover and rollback explicit; they do
not contain passwords, keys, or tickets:

```sh
cp contrib/afs/non-afs.env.example contrib/afs/.env.non-afs
cp contrib/afs/afs.env.example contrib/afs/.env.afs
$EDITOR contrib/afs/.env.non-afs
$EDITOR contrib/afs/.env.afs
set -a
. contrib/afs/.env.afs
set +a
```

`.env.non-afs` must reproduce every value used by the working HAProxy stack.
Keep a different project and port in `.env.afs` while testing. `127.0.0.1` is
suitable only for a browser running on the Docker host or an explicit local
tunnel. For a managed test endpoint, bind the host's LAN address and allow only
the test-client subnet through the host firewall.

## 2. Prepare OpenAFS (Option 1)

The AFS administrator must complete these server-side prerequisites:

- The exact `afs/example.org@EXAMPLE.COM` principal exists. Its current
  rxkad-k5 AES keys are installed in `KeyFileExt` on every applicable database
  and file server. With OpenAFS 1.8 `asetkey`, AES256 and AES128 use enctype
  numbers 18 and 17, for example:

  ```sh
  asetkey add rxkad_krb5 KVNO 18 /secure/afs-service.keytab afs/example.org
  asetkey add rxkad_krb5 KVNO 17 /secure/afs-service.keytab afs/example.org
  ```

  Key creation and distribution are an AFS/Kerberos-administrator task; never
  place this service keytab in this repository or any web container. Follow
  the OpenAFS [`asetkey` documentation](https://manpages.debian.org/testing/openafs-krb5/asetkey.8.en.html)
  and preserve the previous key during a coordinated rotation.
- For AuriStorFS servers used through OpenAFS-client/RxKAD interoperability,
  have the AuriStor administrator confirm that the relevant Location,
  Protection, and File services remain available to OpenAFS clients, then
  install the same `afs/<cell>@REALM` rxkad_krb5 keys with the vendor `asetkey` in
  `/etc/yfs/server/KeyFileExt` on every relevant service host, directly or with
  the vendor's Update Server. Native `yfs-rxgk` may coexist, but this profile
  does not consume it and the servers must not require it from this client.
  Follow the AuriStor
  [`asetkey` documentation](https://www.auristor.com/documentation/man/linux/8/asetkey.html)
  and migration guide, and preserve the previous key during rotation.
- Every user has the intended Kerberos-to-PTS mapping and only the required
  AFS ACLs. Do not grant broad `system:anyuser` access to make startup pass.
- DNS, clocks, realm trust/capaths, cell configuration, and the container
  host's KDC reachability are correct.

Install and start the distribution-supported OpenAFS 1.8 client on the Docker
host. This is also the client used for the documented AuriStorFS OpenAFS/RxKAD
interoperability path; a native AuriStorFS Cache Manager is not consumed by
this image. Select a new, dedicated, symlink-free test subtree; do not start
with production data. Verify the host credential path before involving Docker:

```sh
findmnt -T "$TFM_AFS_SOURCE" -o TARGET,SOURCE,FSTYPE,OPTIONS
test "$(findmnt -T "$TFM_AFS_SOURCE" -n -o FSTYPE)" = afs
test -d "$TFM_AFS_SOURCE" && test ! -L "$TFM_AFS_SOURCE"
test "$(fs whichcell -path "$TFM_AFS_SOURCE" | awk -F"'" 'NR == 1 {print $2}')" = "$TFM_AFS_CELL"
fs examine -path "$TFM_AFS_SOURCE"
fs getcrypt

pagsh -c '
  cache_dir=$(mktemp -d /tmp/tfm-afs-k5.XXXXXX) || exit 1
  chmod 0700 "$cache_dir"
  export KRB5CCNAME="DIR:$cache_dir"
  trap '\''unlog; kdestroy; rm -rf "$cache_dir"'\'' EXIT
  kinit user@EXAMPLE.COM &&
  kvno afs/example.org@EXAMPLE.COM &&
  aklog -cell example.org &&
  tokens &&
  fs listacl -path "$TFM_AFS_SOURCE"
'
```

The disposable PAG and credential-cache directory prevent this preflight from
destroying the operator's existing token or TGT.

The AES enctypes in an rxkad-k5 service key protect Kerberos key material; they
do not provide AuriStor AES-256 data-plane security. This client uses
RxKAD/FCRYPT. `fs getcrypt` reports the Cache Manager default, while the token
and server policy determine the actual connection; have the AFS administrator
approve that wire-security boundary. See AuriStor's
[`fs getcrypt` documentation](https://www.auristor.com/documentation/man/linux/1/fs_getcrypt.html).

The Docker daemon must see the same mounted path. Mount only this subtree—not
`/afs`. Confirm it contains no out-of-scope symlinks or AFS volume mount points.
Do not run `chown`, `chmod`, POSIX ACL, or SELinux relabel commands on it. Tiny
File Manager is not an AFS ACL editor.

## 3. Create the Option 1 runtime files

Create only regular local files and directories; do not use symlinks, reparse
points, or a shared/system directory:

```sh
mkdir -p contrib/afs/openafs contrib/afs/keytab
cp contrib/afs/config.php.example contrib/afs/config.php
cp contrib/haproxy/krb5.conf.example contrib/afs/krb5.conf
cp contrib/haproxy/authorized-users.example contrib/afs/authorized-users
cp contrib/afs/ThisCell.example contrib/afs/openafs/ThisCell
cp contrib/afs/CellServDB.example contrib/afs/openafs/CellServDB
cp /secure/path/tls.crt contrib/afs/tls.crt
cp /secure/path/tls.key contrib/afs/tls.key
chmod 0700 contrib/afs/keytab
chmod 0600 contrib/afs/tls.key
```

Edit:

- `krb5.conf` for `TFM_AD_REALM` and its KDCs;
- `openafs/ThisCell` and `openafs/CellServDB` for `TFM_AFS_CELL`;
- `authorized-users` to one `tinyfilemanager:` line containing exact,
  realm-qualified principals; and
- `config.php` only for time zone, upload limits, global read-only mode, or
  exact read-only principals. Keep the fixed root, remote-authentication, TLS,
  disabled archive/chmod, and disabled direct/network feature settings.

The certificate SAN, URL hostname, DNS record, and `HTTP/<host>` SPN must all
use `TFM_PUBLIC_HOST`. The key must be unencrypted PEM. Runtime inputs are
ignored by Git and excluded from the supplied build contexts.

## 4. Supply the HTTP keytab

Use the same SPN owner chosen in the HAProxy guide. The keytab must contain
only AES entries for `HTTP/files.example.com@EXAMPLE.COM`.

### Option A: dedicated service account

Copy its HTTP-only keytab into the dedicated directory:

```sh
cp /secure/path/http.keytab contrib/afs/keytab/http.keytab
chmod 0600 contrib/afs/keytab/http.keytab
export TFM_KEYTAB_DIR="$PWD/contrib/afs/keytab"
```

The AFS endpoint configuration below must trust the user service account that
owns this SPN for delegation. This is intentionally different from the
authentication-only HAProxy profile, where delegation stays disabled.

### Option B: AD-joined Linux host with SSSD rotation

First complete HAProxy guide Option B so `/etc/krb5.keytab` contains the exact
HTTP principal and SSSD renews it. Install the AFS-specific exporter/consumer
service before Compose. Its first run safely publishes the HTTP-only keytab
while the new AFS project is stopped:

```sh
sudo install -d -o root -g root -m 0700 /etc/tinyfilemanager-afs
sudo install -o root -g root -m 0755 \
  contrib/haproxy/systemd/tinyfilemanager-keytab-sync \
  /usr/local/sbin/tinyfilemanager-keytab-sync
sudo install -o root -g root -m 0755 \
  contrib/afs/systemd/tinyfilemanager-afs-keytab-refresh \
  /usr/local/sbin/tinyfilemanager-afs-keytab-refresh
sudo install -o root -g root -m 0644 \
  contrib/afs/systemd/tinyfilemanager-afs-keytab-sync.service \
  contrib/afs/systemd/tinyfilemanager-afs-keytab-sync.path \
  contrib/afs/systemd/tinyfilemanager-afs-keytab-sync.timer \
  /etc/systemd/system/
sudo install -o root -g root -m 0600 \
  contrib/afs/systemd/keytab-sync.env.example \
  /etc/tinyfilemanager-afs/keytab-sync.env
sudoedit /etc/tinyfilemanager-afs/keytab-sync.env
sudo systemctl daemon-reload
sudo systemctl disable --now \
  tinyfilemanager-keytab-sync.path \
  tinyfilemanager-keytab-sync.timer
sudo systemctl start tinyfilemanager-afs-keytab-sync.service
$EDITOR contrib/afs/.env.afs
set -a
. contrib/afs/.env.afs
set +a
```

Set `TFM_KEYTAB_DIR=/var/lib/tinyfilemanager-keytab/keytabs` in `.env.afs`
before sourcing it. Its `TFM_PROJECT` must exactly match `TFM_PROJECT` in
`/etc/tinyfilemanager-afs/keytab-sync.env`; the supplied examples both use
`tinyfilemanager-afs`. Otherwise the rotation service cannot find the app.

The Linux host computer object owns the HTTP SPN in this option. Configure
that object for delegation as described in the endpoint section. Never mount
the full host keytab or use `ktpass` against the joined computer account.

## 5. Check, build, and start Option 1

Reload the recorded AFS environment from the repository root:

```sh
set -a
. contrib/afs/.env.afs
set +a
```

Preflight the host paths without reading key contents:

```sh
for file in \
  "$TFM_CONFIG_PATH" "$TFM_KRB5_CONFIG_PATH" \
  "$TFM_AUTHORIZED_USERS_PATH" "$TFM_TLS_CERT_PATH" "$TFM_TLS_KEY_PATH" \
  "$TFM_KEYTAB_DIR/http.keytab"; do
  [ -f "$file" ] && [ ! -L "$file" ] || exit 1
done
for dir in "$TFM_OPENAFS_CONFIG_DIR" "$TFM_KEYTAB_DIR" "$TFM_AFS_SOURCE"; do
  [ -d "$dir" ] && [ ! -L "$dir" ] || exit 1
done
test "$(findmnt -T "$TFM_AFS_SOURCE" -n -o FSTYPE)" = afs
```

Before starting the profile, complete the [endpoint requirements](#endpoint-requirements)
for the SPN-owning AD object and the selected test browser. Authentication-only
browser policy from the HAProxy guide does not forward the credential required
by mod_waklog.

Build and start the isolated test profile:

```sh
docker compose -f contrib/afs/compose.yaml config >/dev/null
docker compose -f contrib/afs/compose.yaml build
docker compose -f contrib/afs/compose.yaml up -d --wait
docker compose -f contrib/afs/compose.yaml ps
```

For Option B, enable automatic reconciliation now that the AFS stack exists,
then run it once to verify the direct Apache consumer:

```sh
sudo systemctl enable --now \
  tinyfilemanager-afs-keytab-sync.path \
  tinyfilemanager-afs-keytab-sync.timer
sudo systemctl start tinyfilemanager-afs-keytab-sync.service
```

Open `https://files.example.com:9443` from a configured test endpoint. The
entrypoint fails closed for a missing AFS mount, mismatched cell
configuration, wrong keytab, proxy-backed GSSAPI, non-prefork MPM, or invalid
runtime configuration. The host-side `fs whichcell`/`fs examine` preflight is
the authoritative check that the selected bind is the intended cell and
volume.

Health checks prove the proxy-to-Apache TLS path returns the expected Negotiate
challenge and that the private Apache/PHP endpoint responds. They do not touch
AFS and cannot detect an inaccessible or stalled Cache Manager. Monitor the
host Cache Manager and selected mount separately.

## 6. What Option 1 changes

This is not the non-AFS container with another bind mount:

- `gssproxy` is absent. Apache directly reads the HTTP-only keytab and requires
  the browser to delegate a native credential.
- The pinned `mod_auth_gssapi` patch requires a unique, opaque, mode-0600
  `FILE:` cache and fails if delegation or cache storage fails.
- The pinned `mod_waklog` patch creates a fresh PAG for every protected
  request, binds the cache principal to the authenticated user, requests only
  `afs/TFM_AFS_CELL@TFM_AFS_REALM`, removes the token on cleanup, and terminates
  a worker when cleanup cannot be proved.
- Apache uses prefork, in-process PHP, and HTTP/1.1 so PAG creation, token use,
  and cleanup stay in one process. Archive extraction and POSIX chmod are
  disabled.

All Apache/PHP workers share UID 33. A compromise can read the runtime HTTP
keytab and every live delegated cache. The module also lacks TLS channel
binding/AD Extended Protection. Browser policy limits the service receiving a
delegated TGT; it does not limit what a compromised service can do with it.

The AFS configuration rejects visible symlink components, but that pathname
check is subject to races and Tiny File Manager still lacks descriptor-rooted,
operation-time AFS volume confinement. Use only a trusted, dedicated tree with
no symlinks or nested volume mount points. That residual is one reason the
profile remains non-production.

## 7. Mandatory Option 1 live validation

Use two test users with disjoint ACL canaries in the disposable subtree:

1. Verify an allowlisted, delegated user can create, read, edit, upload,
   download, copy, move, rename, and delete only its permitted files.
2. Verify a second user cannot see or modify the first user's canary. Alternate
   the users in a reused Apache worker, then test them concurrently.
3. Verify an allowlisted user without delegation fails before PHP file access;
   an unlisted principal gets `403`; Basic, NTLM, anonymous, and spoofed
   identity headers fail.
4. Verify each request receives a unique native cache and a fresh PAG. Verify
   that delegated caches and tokens are gone after success, denial, abort,
   child recycle, container restart, and ticket expiry; a worker can remain in
   a tokenless PAG until its next request creates a new one. Confirm that the
   next request receives a fresh PAG with no identity crossover.
5. Test KDC loss, AFS loss/stall, host remount, key rotation, and recovery.
   Recreate the container after any host AFS remount.
6. Scan container logs and image history for principals, cache paths, keytabs,
   tickets, tokens, and private paths. Debug Kerberos/module logging must remain
   off.

Do not cut over until all six checks pass in the target environment.

## 8. Option 1 rotation, cutover, and rollback

For Option A, copy the new keytab to a same-directory temporary file, rename it
over `http.keytab`, then recreate the app:

```sh
keytab_tmp=$(mktemp "$TFM_KEYTAB_DIR/.http.keytab.XXXXXX")
install -m 0600 /secure/path/http.keytab "$keytab_tmp"
mv -f "$keytab_tmp" "$TFM_KEYTAB_DIR/http.keytab"
docker compose -f contrib/afs/compose.yaml \
  up -d --no-deps --force-recreate --wait tinyfilemanager
```

For Option B, the installed AFS service reuses the HTTP-only exporter, watches
SSSD's `/etc/krb5.keytab`, and restarts Apache only when its private copy is
stale. That refresh causes a brief authentication interruption. Do not enable
the AFS and non-AFS keytab-sync path/timer pairs at the same time. The service
uses the root-equivalent local Docker socket; keep the installed helpers,
units, environment file, and their parent directories root-owned and not
group/other writable. Review failures with:

```sh
sudo journalctl -u tinyfilemanager-afs-keytab-sync.service
```

After live validation, edit `.env.afs` to use the production bind address and
port. Load `.env.non-afs` to stop the known working project, then load
`.env.afs` to start the already validated AFS project. Never run both on the
same address and port:

```sh
set -a
. contrib/afs/.env.non-afs
set +a
docker compose -f contrib/haproxy/compose.yaml down

set -a
. contrib/afs/.env.afs
set +a
docker compose -f contrib/afs/compose.yaml up -d --wait
```

If the non-AFS deployment uses a host data directory, add
`-f contrib/haproxy/compose.host-path.yaml` to each HAProxy command in this
section.

For Option B only, stop the AFS rotation watchers first:

```sh
sudo systemctl disable --now \
  tinyfilemanager-afs-keytab-sync.path \
  tinyfilemanager-afs-keytab-sync.timer
```

With either keytab option, reload the AFS environment before stopping that
project, then reload the recorded non-AFS environment before starting the
original stack:

```sh
set -a
. contrib/afs/.env.afs
set +a
docker compose -f contrib/afs/compose.yaml down

set -a
. contrib/afs/.env.non-afs
set +a
docker compose -f contrib/haproxy/compose.yaml up -d --wait
```

For Option B only, restore the non-AFS rotation watchers after its containers
are healthy:

```sh
sudo systemctl enable --now \
  tinyfilemanager-keytab-sync.path \
  tinyfilemanager-keytab-sync.timer
sudo systemctl start tinyfilemanager-keytab-sync.service
```

If returning to the authentication-only posture, remove
`AuthNegotiateDelegateAllowlist`, clear Firefox's `Authentication.Delegated`
list, and unset `AuthNegotiateDelegateByKdcPolicy`; retain only each browser's
authentication allowlist. After AD replication, clear delegation on the exact
SPN-owning object and obtain fresh tickets:

```powershell
# Computer-object Option B; use the service-user identity for Option A.
Set-ADAccountControl -Identity LINUXHOST -TrustedForDelegation $false
klist purge
```

Never use `down -v` as a data-management command, and never delete or alter the
host AFS subtree during rollback.

## Endpoint requirements

Delegation is required in three separate places: the AD object that owns the
HTTP SPN, the user credential, and the browser. Authentication without all
three is insufficient. Use the exact FQDN; do not use an IP address, short
name, wildcard, or parent-domain allowlist.

### Active Directory service object

This profile uses **unconstrained Kerberos delegation** of a forwardable user
TGT. Microsoft classifies this as the least secure delegation model. Use a
dedicated service object and a narrowly exposed intranet host. If policy does
not permit unconstrained delegation, this profile is not compatible; do not
substitute protocol transition or constrained delegation because this
direct-delegation implementation requires the delegated TGT.

The object to trust is the object that owns
`HTTP/files.example.com`—not each Windows client computer:

- SSSD/computer-object option: trust the joined Linux host computer object.
- Dedicated account option: trust that user service object.
- Windows endpoint computer objects only receive browser policy. Enabling
  delegation on every workstation is incorrect and unnecessarily dangerous.

In an elevated Windows PowerShell session with the AD module, verify that the
SPN is unique and owned by the expected computer, then enable Kerberos-only
delegation:

```powershell
setspn -F -Q HTTP/files.example.com
setspn -L EXAMPLE\LINUXHOST
Get-ADComputer LINUXHOST -Properties ServicePrincipalName,TrustedForDelegation,TrustedToAuthForDelegation,AccountNotDelegated
$identity = "LINUXHOST"
Set-ADAccountControl -Identity $identity `
  -TrustedForDelegation $true `
  -TrustedToAuthForDelegation $false
$account = Get-ADComputer $identity -Properties TrustedForDelegation,TrustedToAuthForDelegation
if (-not $account.TrustedForDelegation -or $account.TrustedToAuthForDelegation) {
  throw "The SPN owner does not have the required delegation flags"
}
```

For a user-class service account, set `$identity` to that account and replace
both `Get-ADComputer` calls with `Get-ADUser`; the
`Set-ADAccountControl` and two-flag verification are otherwise identical. In
Active Directory Users and Computers, the equivalent setting is the SPN owner's
**Delegation** tab, **Trust this computer/user for delegation to any service
(Kerberos only)**.

Do not enable `TrustedToAuthForDelegation`; that is protocol transition/S4U,
not browser credential forwarding. Confirm the SPN did not move and the HTTP
service ticket has `forwardable` and `ok_as_delegate` flags:

```powershell
klist purge
klist get HTTP/files.example.com
klist
```

See Microsoft [`setspn`](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/setspn),
[`Set-ADAccountControl`](https://learn.microsoft.com/en-us/powershell/module/activedirectory/set-adaccountcontrol),
and [Kerberos delegation troubleshooting](https://learn.microsoft.com/en-us/troubleshoot/windows-server/windows-security/kerberos-authentication-troubleshooting-guidance).

The user must be allowed to delegate. Members of **Protected Users** and users
marked **Account is sensitive and cannot be delegated** cannot use this
profile. Windows Credential Guard also blocks unconstrained credential
delegation; do not disable Credential Guard to make this work. Use a different
architecture where those protections are required. See Microsoft's
[Protected Users](https://learn.microsoft.com/en-us/windows-server/security/credentials-protection-and-management/protected-users-security-group)
and [Credential Guard](https://learn.microsoft.com/en-us/windows/security/identity-protection/credential-guard/how-it-works)
documentation.

The endpoint must already hold a forwardable credential that its browser can
use: a domain Kerberos logon/TGT on Windows, a managed Kerberos SSO credential
on macOS, or a GSS-visible forwardable cache on Linux. A browser policy page
only proves that policy loaded; the delegated-cache and two-user AFS tests in
step 7 are the proof that forwarding works.

### Microsoft Edge

On managed Windows endpoints, deploy these mandatory policies through the
Microsoft Edge ADMX template under **HTTP authentication**, or as `REG_SZ`
values under `HKLM\SOFTWARE\Policies\Microsoft\Edge`:

```text
AuthServerAllowlist = files.example.com
AuthNegotiateDelegateAllowlist = files.example.com
```

Restart Edge and verify both at `edge://policy`. Do not enable
`EnableAuthNegotiatePort`; the SPN remains `HTTP/files.example.com` even when
the test URL uses port 9443.

On managed macOS with Edge 147 or newer, deploy the same two string preferences
in the `com.microsoft.Edge` profile and also set:

```text
AuthNegotiateDelegateByKdcPolicy = true
```

That additional macOS policy requires the KDC's `OK-AS-DELEGATE` flag as well
as the explicit allowlist. See the Edge
[`AuthServerAllowlist`](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/authserverallowlist),
[`AuthNegotiateDelegateAllowlist`](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-browser-policies/authnegotiatedelegateallowlist),
and [`AuthNegotiateDelegateByKdcPolicy`](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/authnegotiatedelegatebykdcpolicy)
policy references.

### Google Chrome

On managed Windows endpoints, set the same mandatory string policies with the
Chrome ADMX template or under
`HKLM\SOFTWARE\Policies\Google\Chrome`:

```text
AuthServerAllowlist = files.example.com
AuthNegotiateDelegateAllowlist = files.example.com
```

On managed macOS, deploy those two strings in the `com.google.Chrome` profile
and set `AuthNegotiateDelegateByKdcPolicy = true`. On managed Linux, place the
same policies in a JSON file under `/etc/opt/chrome/policies/managed/`:

```json
{
  "AuthServerAllowlist": "files.example.com",
  "AuthNegotiateDelegateAllowlist": "files.example.com",
  "AuthNegotiateDelegateByKdcPolicy": true
}
```

Restart Chrome and verify at `chrome://policy`. Keep
`EnableAuthNegotiatePort` disabled or unset. See Chromium's
[`AuthServerAllowlist`](https://chromium.googlesource.com/chromium/src/+/HEAD/components/policy/resources/templates/policy_definitions/HTTPAuthentication/AuthServerAllowlist.yaml),
[`AuthNegotiateDelegateAllowlist`](https://chromium.googlesource.com/chromium/src/+/HEAD/components/policy/resources/templates/policy_definitions/HTTPAuthentication/AuthNegotiateDelegateAllowlist.yaml),
and [`AuthNegotiateDelegateByKdcPolicy`](https://chromium.googlesource.com/chromium/src/+/HEAD/components/policy/resources/templates/policy_definitions/HTTPAuthentication/AuthNegotiateDelegateByKdcPolicy.yaml)
definitions.

### Mozilla Firefox

Deploy the enterprise `Authentication` policy. Configure SPNEGO and delegation
for only the exact HTTPS origin; leave `NTLM` empty and disallow non-FQDN and
proxy authentication:

```json
{
  "policies": {
    "Authentication": {
      "SPNEGO": ["https://files.example.com"],
      "Delegated": ["https://files.example.com"],
      "NTLM": [],
      "AllowNonFQDN": {"SPNEGO": false, "NTLM": false},
      "AllowProxies": {"SPNEGO": false, "NTLM": false},
      "Locked": true
    }
  }
}
```

On Windows, the equivalent Firefox ADMX list entries are:

```text
HKLM\Software\Policies\Mozilla\Firefox\Authentication\SPNEGO\1 = https://files.example.com
HKLM\Software\Policies\Mozilla\Firefox\Authentication\Delegated\1 = https://files.example.com
```

On macOS, deploy the same `Authentication` dictionary in the managed
`org.mozilla.firefox` profile. On Linux, install `policies.json` in the
`distribution/` directory of the managed Firefox installation; the absolute
vendor/package path varies. Restart Firefox and verify at `about:policies`.
See Mozilla's [Authentication policy](https://firefox-admin-docs.mozilla.org/reference/policies/authentication/).

### Safari

Apple's Kerberos SSO extension can make Safari authenticate. Deploy a managed
`com.apple.extensiblesso` payload using:

```text
ExtensionIdentifier = com.apple.AppSSOKerberos.KerberosExtension
TeamIdentifier = apple
Type = Credential
Realm = EXAMPLE.COM
Hosts = [ files.example.com ]
```

The `Hosts` list scopes Kerberos authentication. Apple does not document a
Safari policy equivalent to `AuthNegotiateDelegateAllowlist` or Firefox's
`Authentication.Delegated`, and `credentialBundleIdACL` controls which apps
may use the extension—it is not forward delegation. Therefore Safari is
authentication-only for this recipe and is **not a supported AFS endpoint**
unless a site-specific live test proves Apache receives a native delegated TGT
and the full two-user isolation gate passes. See Apple's
[Kerberos SSO deployment guide](https://support.apple.com/guide/deployment/kerberos-sso-extension-depe6a1cda64/web)
and [`ExtensibleSingleSignOnKerberos`](https://developer.apple.com/documentation/devicemanagement/extensiblesinglesignonkerberos)
payload reference.
