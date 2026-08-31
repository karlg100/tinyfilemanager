# Option 2: native AuriStor provider

> **Status:** this repository supplies the public application overlay and
> Compose profile, but no AuriStor binary or ready-to-run provider image. Build
> the provider base from licensed inputs in an approved private environment.
> The assembled service remains a trusted-intranet, non-production profile
> until the complete live gate below passes. This project has not executed the
> private provider build or the live AuriStor validation because licensed/site
> inputs are intentionally absent.

This option uses a native AuriStorFS/YFS Cache Manager on an EL9 Linux host and
an `auristorfs` mount. It is separate from the
[OpenAFS option](../openafs/README.md): the two images, client ABIs,
configuration files, filesystem types, Compose projects, and validation
records are not interchangeable.

The public pieces are:

- [`Containerfile.provider`](Containerfile.provider), which assembles the
  private provider from operator-supplied offline inputs;
- [`../../Dockerfile.auristor`](../../Dockerfile.auristor), which adds Tiny
  File Manager and fail-closed runtime checks to a private provider base;
- [`../../compose.auristor.yaml`](../../compose.auristor.yaml), which keeps the
  native service isolated from the OpenAFS and non-AFS projects; and
- [`../../auristor.env.example`](../../auristor.env.example), which lists every
  site value required by Compose.

The operator supplies a private EL9 provider image pinned by manifest digest.
Use a loopback-only local registry to keep it on one host, or an approved
access-controlled private registry for multiple hosts. It must contain the
licensed `yfs` and `mod_waklog-yfs` RPMs, the digest-locked delegated credential
integration, and the provider contract described in step 2.

## 1. Prepare the host

First complete the [HAProxy guide](../../../haproxy/README.md) and prove a real
browser Kerberos sign-on. Keep that working project available for rollback.
Then complete the parent [endpoint requirements](../../README.md#endpoint-requirements):
the exact object that owns `HTTP/<host>` must be trusted for delegation, the
test users must be delegable, and each browser must allow both SPNEGO and
delegation only to the exact FQDN.

Record the working non-AFS environment before changing anything. This file is
ignored by Git and contains paths and deployment identity, not secrets:

```sh
cp contrib/afs/non-afs.env.example contrib/afs/.env.non-afs
$EDITOR contrib/afs/.env.non-afs
```

Verify that `.env.non-afs` reproduces every value used by the running HAProxy
stack, including `TFM_DATA_PATH` when its host-path override is active.

Use a rootful EL9 Docker host. Provider-contract version 1 accepts only the
`yfs-2021.05-71.el9` and `mod_waklog-yfs-2021.05-71.el9` package generation on
`x86_64` or `aarch64`. The module directives and `pagsh` process contract were
dynamically checked for this generation; both architectures still require the
complete site live gate. A different provider release requires a reviewed
contract/version update and renewed module, process, and live attestation.

The host—not the container—must run the native Cache Manager. Do not start
`afsd` or load a kernel module in the web image. Docker Desktop, rootless
Docker, user-namespace remapping, multiple application replicas, and an AFS
Cache Manager inside the container are outside this profile.

Install and configure the licensed native client according to the AuriStor
client documentation. Select a new, dedicated, symlink-free test subtree; do
not start with production data. Replace every example value, then verify the
host before involving Docker:

```sh
export TFM_AFS_SOURCE=/afs/example.org/service/tinyfilemanager-auristor-test
export TFM_AFS_CELL=example.org

findmnt -T "$TFM_AFS_SOURCE" -o TARGET,SOURCE,FSTYPE,OPTIONS
test "$(findmnt -T "$TFM_AFS_SOURCE" -n -o FSTYPE)" = auristorfs
test -d "$TFM_AFS_SOURCE" && test ! -L "$TFM_AFS_SOURCE"
test -r /etc/yfs/yfs-client.conf
fs whichcell -path "$TFM_AFS_SOURCE"
fs examine -path "$TFM_AFS_SOURCE"
fs getcrypt
rpm -q yfs
```

Confirm `fs whichcell` reports `TFM_AFS_CELL`, record the exact client and
kernel versions, and have the AuriStor administrator approve the selected
volume and token security policy. `fs getcrypt` reports a Cache Manager
setting; it does not prove the token class or negotiated connection security.
The live gate must prove those separately.

Have that administrator also record the token path expected for this cell.
Native yfs-rxgk uses `yfs-rxgk/_afs.<cell>@REALM`; an allowed RxKAD path uses
`afs/<cell>@REALM`. The matching current keys must be installed through the
vendor-supported process on every relevant Location, Protection, and File
service, with realm trust and downgrade policy reviewed. If keyed Cache Manager
or combined identities are used, record their expected order and ACL meaning
for the live test. Never copy any AFS server or Cache Manager key into this web
container; it receives only the HTTP service keytab described below.

Bind only this subtree. Do not mount all of `/afs`, run recursive `chown` or
`chmod`, apply POSIX ACLs, or add an SELinux `Z` relabel to the AuriStor data
tree. Use AFS ACL tools and disposable test data. If a site SELinux policy is
required, review and test that policy without relabeling the managed
filesystem.

## 2. Build the private provider base

Review and accept the current [AuriStor client EULA](https://www.auristor.com/filesystem/client-installer/)
before downloading or building with vendor packages. Confirm that the planned
builder, registry, users, and use fall within your license. This repository
does not grant redistribution rights and must not receive the packages.

Use the supplied [`Containerfile.provider`](Containerfile.provider) only on an
approved private builder. It builds pinned PHP and `mod_auth_gssapi` sources,
then installs the licensed provider from offline RPM directories passed as
named BuildKit contexts. Its
[`Containerfile.provider.dockerignore`](Containerfile.provider.dockerignore)
keeps every named input out of the public source context.

Prepare four access-controlled directories outside this repository:

```sh
export TFM_PROVIDER_INPUT_ROOT=/secure/tinyfilemanager-auristor-provider
install -d -m 0700 \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-runtime-rpms" \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-build-rpms" \
  "$TFM_PROVIDER_INPUT_ROOT/source-archives" \
  "$TFM_PROVIDER_INPUT_ROOT/provider-inputs/trusted-rpm-keys"
```

Download the two public source archives directly from their authoritative
release locations. Refuse existing files so a prior path or symlink cannot be
silently overwritten:

```sh
test ! -e "$TFM_PROVIDER_INPUT_ROOT/source-archives/php-8.3.33.tar.xz"
test ! -e "$TFM_PROVIDER_INPUT_ROOT/source-archives/mod_auth_gssapi-1.6.4.tar.gz"
curl --fail --location --output \
  "$TFM_PROVIDER_INPUT_ROOT/source-archives/php-8.3.33.tar.xz" \
  https://www.php.net/distributions/php-8.3.33.tar.xz
curl --fail --location --output \
  "$TFM_PROVIDER_INPUT_ROOT/source-archives/mod_auth_gssapi-1.6.4.tar.gz" \
  https://github.com/gssapi/mod_auth_gssapi/archive/refs/tags/v1.6.4.tar.gz
```

Populate the other directories as follows:

- `auristor-runtime-rpms`: the complete signed offline EL9 runtime closure,
  including `httpd`, `mod_ssl`, Kerberos tools, required verification tools
  and libraries, `yfs`, and `mod_waklog-yfs` for one architecture. It must
  contain no OpenAFS, stock PHP/PHP-FPM, or `mod_auth_gssapi` RPM.
- `auristor-build-rpms`: the complete signed offline compiler/header closure
  needed to build PHP and `mod_auth_gssapi`, including Apache/APR, Kerberos,
  OpenSSL, zlib, libzip, and mbstring dependencies. Do not rely on a network
  repository during the build.
- `source-archives`: exactly `php-8.3.33.tar.xz` and
  `mod_auth_gssapi-1.6.4.tar.gz`. Verify them before building:

  ```sh
  (cd "$TFM_PROVIDER_INPUT_ROOT/source-archives" && \
    printf '%s  %s\n' \
      e293ed620cec74651bb4a071317892a478aa6840fab22db45c72d77cd42f9676 \
      php-8.3.33.tar.xz \
      7323affdba44ff560373c996b5eee8ac54fbb6067de5be8737c2f54c64c4e8e6 \
      mod_auth_gssapi-1.6.4.tar.gz | sha256sum -c -)
  ```

- `provider-inputs/trusted-rpm-keys`: every independently verified public key
  needed to validate the distribution and AuriStor RPMs. Place only reviewed
  `.asc` files there. Never place a repository credential or private key in
  `provider-inputs`.

Acquire the RPM closures on an authorized, same-architecture EL9 staging host
after accepting the EULA and configuring only approved EL9 and licensed
AuriStor repositories. Record `dnf repolist --enabled` and the repository
metadata checksums in the private build record. Then use the distribution's
download plugin to resolve every dependency, including packages already
installed on that host:

```sh
sudo dnf install -y curl gnupg2 dnf-plugins-core
dnf repolist --enabled

case "$(uname -m)" in
  x86_64|aarch64) TFM_PROVIDER_RPM_ARCH=$(uname -m) ;;
  *) echo 'provider contract v1 supports only x86_64 or aarch64' >&2; exit 1 ;;
esac
TFM_YFS_NEVRA="yfs-2021.05-71.el9.$TFM_PROVIDER_RPM_ARCH"
TFM_WAKLOG_NEVRA="mod_waklog-yfs-2021.05-71.el9.$TFM_PROVIDER_RPM_ARCH"

dnf download --resolve --alldeps \
  --destdir "$TFM_PROVIDER_INPUT_ROOT/auristor-runtime-rpms" \
  httpd mod_ssl krb5-workstation openssl binutils libcap coreutils \
  findutils grep gawk rpm sed shadow-utils util-linux libxml2 oniguruma \
  sqlite-libs libzip zlib "$TFM_YFS_NEVRA" "$TFM_WAKLOG_NEVRA"

dnf download --resolve --alldeps \
  --destdir "$TFM_PROVIDER_INPUT_ROOT/auristor-build-rpms" \
  gcc gcc-c++ make autoconf automake libtool bison flex re2c patch tar gzip xz \
  diffutils pkgconf-pkg-config httpd-devel apr-devel apr-util-devel \
  krb5-devel openssl-devel libxml2-devel oniguruma-devel sqlite-devel \
  libzip-devel zlib-devel
```

These package names are the version-1 input contract. If the selected EL9
vendor cannot resolve them, stop and update the Containerfile and contract;
do not add a live repository inside the build. The runtime bundle must contain
the exact `yfs-2021.05-71.el9` and `mod_waklog-yfs-2021.05-71.el9` NEVRAs for
the selected architecture. Neither bundle may contain stock PHP,
`mod_auth_gssapi`, OpenAFS, `yfs-client`, or a kernel-module package.

Copy each public RPM signing key from an independently authenticated vendor or
distribution channel into `provider-inputs/trusted-rpm-keys/`. Display and
record its full fingerprint, compare it with the separately published value,
then verify the bundles in a disposable RPM database before building:

```sh
find "$TFM_PROVIDER_INPUT_ROOT/provider-inputs/trusted-rpm-keys" \
  -type f -name '*.asc' -exec gpg --show-keys --with-fingerprint '{}' \;

TFM_RPMDB=$(mktemp -d)
trap 'rm -rf "$TFM_RPMDB"' EXIT
rpm --dbpath "$TFM_RPMDB" --initdb
find "$TFM_PROVIDER_INPUT_ROOT/provider-inputs/trusted-rpm-keys" \
  -type f -name '*.asc' -exec rpm --dbpath "$TFM_RPMDB" --import '{}' +
for rpm_file in \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-runtime-rpms"/*.rpm \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-build-rpms"/*.rpm; do
  rpmkeys --dbpath "$TFM_RPMDB" --checksig "$rpm_file" \
    | grep -Eq ': digests signatures OK$' || exit 1
done
rm -rf "$TFM_RPMDB"
trap - EXIT
```

Do not import a key obtained with an RPM bundle without independently checking
its fingerprint. If current EL9 policy rejects an old or expired signature,
obtain a supported package/key or written vendor guidance; do not bypass
signature verification or weaken system crypto policy.

Each RPM directory needs a `SHA256SUMS` file listing every RPM basename exactly
once and no other RPM:

```sh
for rpm_dir in \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-runtime-rpms" \
  "$TFM_PROVIDER_INPUT_ROOT/auristor-build-rpms"; do
  (cd "$rpm_dir" && sha256sum -- *.rpm > SHA256SUMS)
done
```

Pin a supported EL9 base by digest and select the host architecture. Build to
a temporary local tag; all package-install steps run with networking disabled:

```sh
case "$(uname -m)" in
  x86_64) TFM_PROVIDER_PLATFORM=linux/amd64 ;;
  aarch64) TFM_PROVIDER_PLATFORM=linux/arm64 ;;
  *) echo 'unsupported architecture' >&2; exit 1 ;;
esac
export TFM_EL9_IMAGE=registry.example.com/approved/el9@sha256:REPLACE_WITH_64_HEX
export TFM_PROVIDER_BUILD_TAG=tinyfilemanager-auristor-provider:private-build

docker buildx build --load \
  --platform "$TFM_PROVIDER_PLATFORM" \
  --build-arg "EL9_IMAGE=$TFM_EL9_IMAGE" \
  --build-context "auristor_runtime_rpms=$TFM_PROVIDER_INPUT_ROOT/auristor-runtime-rpms" \
  --build-context "auristor_build_rpms=$TFM_PROVIDER_INPUT_ROOT/auristor-build-rpms" \
  --build-context "source_archives=$TFM_PROVIDER_INPUT_ROOT/source-archives" \
  --build-context "provider_inputs=$TFM_PROVIDER_INPUT_ROOT/provider-inputs" \
  --file contrib/afs/providers/auristor/Containerfile.provider \
  --tag "$TFM_PROVIDER_BUILD_TAG" .
```

The build verifies every input checksum and RPM signature, rejects network
package access, and creates both `/usr/local/bin/php` and the in-process
`php_module` from the same pinned PHP source, including the required Zip
extension. Stock EL9 PHP does not supply the Apache module required to preserve
a request PAG, so stock CLI/FPM and mixed PHP builds are rejected. The build
also applies the supplied delegated-cache patch to pinned `mod_auth_gssapi`,
installs the native module, uses prefork and HTTP/1.1, disables token caching,
excludes OpenAFS/FPM/CGI/HTTP2, and generates the exact provider lock and RPM
manifest. The generated schema is shown in
[`provider.lock.example`](provider.lock.example).

The public [`../../auristor-preflight`](../../auristor-preflight) runs as the
Apache UID at startup. It requires the native control endpoint, enters a fresh
PAG, verifies that it starts without a token, and confirms the selected mount's
cell and documented `fs getcrypt` output. It does not prove
request-time token installation, sibling-worker isolation, or cleanup; those
remain mandatory live tests in step 5.

Run an SBOM/vulnerability review and image-layer secret scan on the local
provider. Never place a vendor RPM, repository credential or certificate,
provider image, keytab, ticket, token, or cache in Git, the public build
context, public CI/artifacts/caches, logs, or a public registry.

The overlay deliberately rejects a mutable local tag. For a single host, the
following tested pattern starts an unauthenticated HTTP registry on IPv4
loopback only. Use it only when policy permits a host-local registry and the
selected Buildx driver is `docker`; a container or remote builder cannot reach
the Docker host as `localhost`. Otherwise use an approved TLS-protected private
registry instead. Never configure a non-loopback insecure registry.

```sh
TFM_BUILDX_DRIVER=$(docker buildx inspect \
  | awk '$1 == "Driver:" { print $2; exit }')
test "$TFM_BUILDX_DRIVER" = docker || {
  echo 'use the docker Buildx driver or an approved TLS registry' >&2
  exit 1
}
unset TFM_BUILDX_DRIVER

export TFM_LOCAL_REGISTRY=localhost:5000
export TFM_LOCAL_REGISTRY_CONTAINER=tinyfilemanager-auristor-registry
export TFM_LOCAL_REGISTRY_VOLUME=tinyfilemanager-auristor-registry-data

if docker container inspect "$TFM_LOCAL_REGISTRY_CONTAINER" >/dev/null 2>&1; then
  echo 'review or remove the existing registry container first' >&2
  exit 1
fi
docker volume create "$TFM_LOCAL_REGISTRY_VOLUME"
docker run --detach \
  --name "$TFM_LOCAL_REGISTRY_CONTAINER" \
  --restart unless-stopped \
  --publish 127.0.0.1:5000:5000 \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
  --security-opt no-new-privileges:true \
  --cap-drop ALL \
  --mount "type=volume,source=$TFM_LOCAL_REGISTRY_VOLUME,target=/var/lib/registry" \
  registry:3.1.1@sha256:1be55279f18a2fe1a74edf2664cac61c1bea305b7b4642dab412e7affdcb3e33
test "$(docker port "$TFM_LOCAL_REGISTRY_CONTAINER" 5000/tcp)" = \
  127.0.0.1:5000
test "$(curl --fail --silent --show-error http://127.0.0.1:5000/v2/)" = '{}'
```

The registry volume contains the licensed provider image. Keep it local,
access-controlled, and out of backups or replication unless those destinations
are separately authorized. Tag and push the base only to that registry, then
copy the `repo@sha256:...` reference reported by the push:

```sh
export TFM_LOCAL_REGISTRY=localhost:5000
export TFM_PROVIDER_LOCAL_TAG=$TFM_LOCAL_REGISTRY/tinyfilemanager-auristor-provider:private-build
export TFM_PROVIDER_REPOSITORY=$TFM_LOCAL_REGISTRY/tinyfilemanager-auristor-provider

docker tag "$TFM_PROVIDER_BUILD_TAG" "$TFM_PROVIDER_LOCAL_TAG"
docker push "$TFM_PROVIDER_LOCAL_TAG"
docker pull "$TFM_PROVIDER_LOCAL_TAG"
TFM_AURISTOR_PROVIDER_IMAGE=$(
  docker image inspect "$TFM_PROVIDER_LOCAL_TAG" \
    --format '{{range .RepoDigests}}{{println .}}{{end}}' \
    | awk -v prefix="$TFM_PROVIDER_REPOSITORY@sha256:" '
        index($0, prefix) == 1 { value = $0; matches++ }
        END { if (matches != 1) exit 1; print value }
      '
) || exit 1
export TFM_AURISTOR_PROVIDER_IMAGE
printf '%s\n' "$TFM_AURISTOR_PROVIDER_IMAGE"
```

Patch the registry image and recreate this loopback service under normal
operations. Stopping it leaves the named volume intact; deleting the volume
removes the host's registry copy of the licensed image. Keep the service
running whenever Compose may need to resolve or rebuild the provider.

For multiple hosts, push only when your AuriStor license and security policy
permit use of an approved private registry. In either mode the Compose value
must be the immutable manifest-digest reference, with this form:

```text
registry.example.com/internal/auristor-apache-provider@sha256:64_lowercase_hex_characters
```

## 3. Create the runtime configuration

Run these commands from the public repository root:

```sh
cp contrib/afs/auristor.env.example contrib/afs/.env.auristor
cp contrib/afs/config.php.example contrib/afs/config.php
cp contrib/haproxy/krb5.conf.example contrib/afs/krb5.conf
cp contrib/haproxy/authorized-users.example contrib/afs/authorized-users
mkdir -p contrib/afs/keytab
cp /secure/path/tls.crt contrib/afs/tls.crt
cp /secure/path/tls.key contrib/afs/tls.key
chmod 0700 contrib/afs/keytab
chmod 0600 contrib/afs/tls.key
```

Create `contrib/afs/yfs-client.conf` as a self-contained, reviewed client
configuration for the selected cell. If the host file is already
self-contained, copy it with:

```sh
sudo install -o "$(id -u)" -g "$(id -g)" -m 0644 \
  /etc/yfs/yfs-client.conf contrib/afs/yfs-client.conf
```

Compose mounts only that file. Resolve or remove site `include` and
`includedir` dependencies rather than exposing additional host configuration.
Do not put repository credentials, client private keys, server keys, or
`KeyFileExt` in it.

Edit the files as follows:

- `.env.auristor`: set `TFM_AURISTOR_PROVIDER_IMAGE` to the immutable value
  printed above (or the approved private-registry equivalent), plus a unique
  `TFM_AURISTOR_PROJECT`, a distinct local/private output image, the exact
  public host, realms, cell, dedicated `auristorfs` source, and absolute file
  paths;
- `krb5.conf`: set the HTTP realm and KDCs;
- `authorized-users`: use one `tinyfilemanager:` line with exact,
  realm-qualified principals; and
- `config.php`: keep the fixed root and remote-authentication controls; change
  only the documented time zone, upload limits, global read-only mode, or
  exact read-only principals.

The certificate SAN, URL, DNS record, and `HTTP/<host>` SPN must use the same
`TFM_PUBLIC_HOST`. The private key must be unencrypted PEM. All runtime files
above are ignored by Git and excluded from the supplied build contexts.

Supply `keytab/http.keytab` using either parent
[keytab option](../../README.md#4-supply-the-http-keytab). It must contain only
AES keys for the exact HTTP principal; never mount the full host keytab or any
AuriStor service key.

For the SSSD/computer-object option, install the parent systemd exporter and
refresh units, but set `TFM_PROJECT` in
`/etc/tinyfilemanager-afs/keytab-sync.env` to the same value as
`TFM_AURISTOR_PROJECT`. Set `TFM_KEYTAB_DIR` in `.env.auristor` to
`/var/lib/tinyfilemanager-keytab/keytabs`. Keep the non-AFS and OpenAFS
watchers disabled while this project owns the consumer role.

## 4. Check, build, and start

Load the values and verify only regular, non-symlink host inputs are selected:

```sh
set -a
. contrib/afs/.env.auristor
set +a

case "$TFM_AURISTOR_PROVIDER_IMAGE" in
  *@sha256:*) ;;
  *) echo 'provider image must be pinned by sha256 digest' >&2; exit 1 ;;
esac
provider_digest=${TFM_AURISTOR_PROVIDER_IMAGE##*@sha256:}
[ "${#provider_digest}" -eq 64 ] || exit 1
case "$provider_digest" in *[!0-9a-f]*) exit 1 ;; esac
unset provider_digest

for file in \
  "$TFM_CONFIG_PATH" "$TFM_KRB5_CONFIG_PATH" \
  "$TFM_AURISTOR_CONFIG_PATH" "$TFM_AUTHORIZED_USERS_PATH" \
  "$TFM_TLS_CERT_PATH" "$TFM_TLS_KEY_PATH" \
  "$TFM_KEYTAB_DIR/http.keytab"; do
  [ -f "$file" ] && [ ! -L "$file" ] || exit 1
done
[ -d "$TFM_KEYTAB_DIR" ] && [ ! -L "$TFM_KEYTAB_DIR" ] || exit 1
[ -d "$TFM_AFS_SOURCE" ] && [ ! -L "$TFM_AFS_SOURCE" ] || exit 1
test "$(findmnt -T "$TFM_AFS_SOURCE" -n -o FSTYPE)" = auristorfs
```

Build and start the separate profile:

```sh
docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml config --quiet
docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml build
docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml up -d --wait
docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml ps
```

The build rejects a mutable provider reference, wrong EL release, changed
lock or RPM inventory, wrong module/package, OpenAFS content, unresolved
libraries, wrong Apache UID, or an unsafe module/SAPI inventory. Startup also
rejects the wrong mount type, writable or missing configuration, wrong HTTP
keytab, invalid TLS material, stale lock, proxy-backed GSSAPI, or a failed
provider preflight.

If SSSD supplies the keytab, enable its watcher only after the containers
exist, then run one reconciliation:

```sh
sudo systemctl enable --now \
  tinyfilemanager-afs-keytab-sync.path \
  tinyfilemanager-afs-keytab-sync.timer
sudo systemctl start tinyfilemanager-afs-keytab-sync.service
```

The container healthcheck proves only loopback Apache/PHP liveness, and HAProxy
health proves only the TLS/Negotiate path. Neither traverses AFS or proves a
delegated credential, PAG, token, ACL, Cache Manager, or file server.
The application healthcheck also reports unhealthy if it sees a shared
`/tmp/waklog_cache.*` file, but Docker does not restart a container merely
because it is unhealthy and HAProxy may still route to it. Treat that state as
a stop-traffic failure, not as automatic containment.

## 5. Complete the live gate

Open the test URL from managed endpoints configured in the parent browser
section. Use two users with disjoint AFS ACL canaries and record the exact host
client, private provider digest, derived image ID, browser policy, and server
policy. All of these tests are mandatory:

1. An allowlisted, delegating user can create, read, edit, upload, download,
   copy, move, rename, and delete only its permitted objects. Archive and
   POSIX-chmod routes remain disabled.
2. A second user cannot see or alter the first user's canary. Alternate the
   users in reused workers, then test concurrent requests.
3. Each request gets a unique native delegated `FILE:` cache, fresh PAG, and
   correct user token. Missing delegation, unlisted principals, anonymous,
   Basic, NTLM, and spoofed identity/cache headers fail before PHP file access.
4. Caches and tokens disappear after success, denial, abort, expiry, worker
   recycle, cleanup failure, and container restart. No later request inherits
   another identity. No shared `/tmp/waklog_cache.*` file appears during or
   after any request path.
5. The observed token is the approved rxkad or yfs-rxgk class and uses the
   required negotiated security level. When yfs-rxgk is required, prove there
   is no rxkad downgrade.
6. KDC, Location, Protection, File service, Cache Manager, mount, and network
   failures fail closed and recover without anonymous fallback or identity
   crossover. Test HTTP-key rotation as well.
7. Unsafe symlinks, nested mounts, and outside-root paths remain inaccessible.
   Recreate the application container after a host remount.
8. The final capability, SELinux, and seccomp policy passes the same tests.
   If a native ioctl or keyring syscall is blocked, derive and hash-pin the
   narrowest reviewed exception; never use `seccomp=unconfined`, `--privileged`,
   or a broad host `/proc` bind.
9. Scan image layers, SBOMs, registry metadata, artifacts, and logs for
   repository credentials, private build inputs, keytabs, tickets, caches,
   tokens, principals, and private paths.

Do not cut over until every result passes against the exact target digest. A
successful build, `httpd -t`, startup preflight, healthcheck, `fs` command, or
single-user browser request is not acceptance.

## 6. Rotate and roll back

For a manually managed HTTP keytab, replace it atomically and recreate only
the native application service:

```sh
keytab_tmp=$(mktemp "$TFM_KEYTAB_DIR/.http.keytab.XXXXXX")
install -m 0600 /secure/path/http.keytab "$keytab_tmp"
mv -f "$keytab_tmp" "$TFM_KEYTAB_DIR/http.keytab"
docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml \
  up -d --no-deps --force-recreate --wait tinyfilemanager
```

The SSSD systemd path/timer detects a changed host keytab, exports only the
HTTP principal, and restarts this Compose project's application container when
its private copy is stale. Review failures with:

```sh
sudo journalctl -u tinyfilemanager-afs-keytab-sync.service
```

To roll back under Option B, first disable the native project's SSSD watchers.
Skip this block entirely for a manually managed service-account keytab:

```sh
sudo systemctl disable --now \
  tinyfilemanager-afs-keytab-sync.path \
  tinyfilemanager-afs-keytab-sync.timer
```

Under either keytab option, stop only the explicitly named native profile,
then restore the previously validated non-AFS project from the environment
recorded in step 1:

```sh

docker compose --env-file contrib/afs/.env.auristor \
  -f contrib/afs/compose.auristor.yaml down

set -a
. contrib/afs/.env.non-afs
set +a
if [ -n "${TFM_DATA_PATH:-}" ]; then
  docker compose --env-file contrib/afs/.env.non-afs \
    -f contrib/haproxy/compose.yaml \
    -f contrib/haproxy/compose.host-path.yaml up -d --wait
else
  docker compose --env-file contrib/afs/.env.non-afs \
    -f contrib/haproxy/compose.yaml up -d --wait
fi
```

If that non-AFS project also uses SSSD rotation, verify that
`/etc/tinyfilemanager-haproxy/keytab-sync.env` names the restored `TFM_PROJECT`,
then re-enable only its watcher. Skip this block for a manually managed
service-account keytab:

```sh
grep -Fx "TFM_PROJECT=$TFM_PROJECT" \
  /etc/tinyfilemanager-haproxy/keytab-sync.env
sudo systemctl enable --now \
  tinyfilemanager-keytab-sync.path \
  tinyfilemanager-keytab-sync.timer
sudo systemctl start tinyfilemanager-keytab-sync.service
```

To roll back to Option 1 instead, use its independently recorded `.env.afs` and
the parent [Option 1 rollback](../../README.md#8-option-1-rotation-cutover-and-rollback).
Never enable more than one keytab consumer watcher.

Never use `down -v` as a data-management command, delete or alter the host AFS
subtree, publish the provider/derived image, or reuse an AuriStor project name
for the OpenAFS profile.

## References

- [AuriStor client installers, signing keys, and EULA](https://www.auristor.com/filesystem/client-installer/)
- [AuriStorFS client configuration](https://www.auristor.com/documentation/man/linux/5/yfs-client.conf.html)
- [AuriStorFS `aklog` and yfs-rxgk security levels](https://www.auristor.com/documentation/man/linux/1/aklog.html)
- [AuriStorFS Cache Manager](https://www.auristor.com/documentation/man/linux/8/afsd.html)
- [AuriStorFS `fs getcrypt`](https://www.auristor.com/documentation/man/linux/1/fs_getcrypt.html)
- [Shared implementation and security requirements](../../REQUIREMENTS.md)
