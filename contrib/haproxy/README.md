# HAProxy, TLS, and AD Kerberos

This optional Compose stack adds HTTPS and Active Directory Kerberos sign-on:

```text
browser -- TLS + Negotiate --> HAProxy -- TCP --> Apache/mod_auth_gssapi
                                                     |          |
                                               gssproxy      REMOTE_USER
                                                (keytab)         |
                                                        Tiny File Manager
```

HAProxy Community does not authenticate GSSAPI in this stack and does not
terminate TLS. It passes the original encrypted connection to Apache. Apache
terminates TLS, authenticates Kerberos, applies the exact principal allowlist,
and supplies server-owned `REMOTE_USER`. Tiny File Manager receives only that
principal name—not a password, ticket, credential cache, or keytab.

The keytab is confined to `gssproxy`; PHP cannot read it. Delegation, Basic
authentication, NTLM, protocol transition, and local-password fallback are
disabled.

`REMOTE_USER` is an identity string, not an AFS credential. Finish this guide
and validate a real Kerberos browser sign-on before considering the separate
[AFS and mod_waklog container profile](../afs/README.md). That guide
describes a separate implementation with a different credential path and
child image; binding `/afs` into the supplied container does not provide
per-user AFS access.

## Requirements

- A current Docker Engine on Linux, or Docker Desktop using Linux containers
  on macOS or Windows.
- A DNS name that resolves to the container host, a matching TLS certificate,
  and synchronized clocks.
- Network access from the `gssproxy` container to AD DNS and KDCs.
- An AES `HTTP/files.example.com@EXAMPLE.COM` service principal and keytab.
- A browser configured to allow Integrated Authentication for the site.

Run every command from the repository root and keep the environment variables
set in the same shell.

## 1. Set the deployment values

Linux or macOS:

```sh
export TFM_PROJECT=tinyfilemanager-ad
export TFM_PUBLIC_HOST=files.example.com
export TFM_AD_REALM=EXAMPLE.COM
export TFM_HTTPS_PORT=8443
```

Windows PowerShell:

```powershell
$env:TFM_PROJECT = "tinyfilemanager-ad"
$env:TFM_PUBLIC_HOST = "files.example.com"
$env:TFM_AD_REALM = "EXAMPLE.COM"
$env:TFM_HTTPS_PORT = "8443"
```

The default bind address is `127.0.0.1`. For remote intranet clients, set
`TFM_BIND_ADDRESS` to the server's LAN address (or `0.0.0.0`) and restrict the
port with the host firewall.

## 2. Create the common runtime files

Linux or macOS:

```sh
cp contrib/haproxy/config.php.example contrib/haproxy/config.php
cp contrib/haproxy/krb5.conf.example contrib/haproxy/krb5.conf
cp contrib/haproxy/authorized-users.example contrib/haproxy/authorized-users
```

Windows PowerShell:

```powershell
Copy-Item contrib/haproxy/config.php.example contrib/haproxy/config.php
Copy-Item contrib/haproxy/krb5.conf.example contrib/haproxy/krb5.conf
Copy-Item contrib/haproxy/authorized-users.example contrib/haproxy/authorized-users
```

Edit `krb5.conf` for the AD realm. Replace the example principal in
`authorized-users` with the exact, realm-qualified principals allowed to use
the service. Keep the file to one `tinyfilemanager:` group line. Edit the time
zone and optional read-only principals in `config.php`; keep its authentication
and path settings unchanged.

Place these operator-supplied files in `contrib/haproxy`:

- `tls.crt`: PEM certificate/chain with `TFM_PUBLIC_HOST` in its SAN.
- `tls.key`: matching, unencrypted PEM private key.

On Linux or macOS, restrict the TLS private key:

```sh
chmod 0600 contrib/haproxy/tls.key
```

On Windows, use NTFS permissions to grant read access only to the operator and
administrators. Use local files, not a UNC or redirected network-share path.
Runtime files must be regular files, not symlinks or reparse points. They are
ignored by Git and excluded from every supplied Docker build context.

## 3. Choose the keytab source

Use one option. A dedicated service account has the smaller credential scope
and works on all three supported container hosts. A Linux host computer object
can let SSSD rotate the keys automatically, but couples the HTTP service to the
host's machine password.

### Option A: dedicated service account

An AD administrator should register the SPN with duplicate checking. For a
user-class service account, run in an elevated Windows PowerShell window:

```powershell
setspn -F -Q HTTP/files.example.com
setspn -U -S HTTP/files.example.com EXAMPLE\tinyfilemanager-http
```

Export an AES keytab with your organization's supported AD procedure. It must
contain only `HTTP/TFM_PUBLIC_HOST@TFM_AD_REALM`; do not enable delegation.
Store it in a dedicated directory:

If upgrading from this branch's earlier layout, move the existing file first:

Linux or macOS:

```sh
mkdir -p contrib/haproxy/keytab
mv contrib/haproxy/http.keytab contrib/haproxy/keytab/http.keytab
```

Windows PowerShell:

```powershell
New-Item -ItemType Directory -Force contrib/haproxy/keytab | Out-Null
Move-Item contrib/haproxy/http.keytab contrib/haproxy/keytab/http.keytab
```

Linux or macOS:

```sh
mkdir -p contrib/haproxy/keytab
cp /secure/path/http.keytab contrib/haproxy/keytab/http.keytab
chmod 0700 contrib/haproxy/keytab
chmod 0600 contrib/haproxy/keytab/http.keytab
```

Windows PowerShell:

```powershell
New-Item -ItemType Directory -Force contrib/haproxy/keytab | Out-Null
Copy-Item C:\secure\path\http.keytab contrib/haproxy/keytab/http.keytab
```

Restrict the Windows directory and file with NTFS permissions. Do not set
`TFM_KEYTAB_DIR`; Compose uses `contrib/haproxy/keytab` by default.

### Option B: Linux host computer object and SSSD rotation

This option is only for a Linux systemd host already joined to AD with SSSD's
AD provider, configured to use `/etc/krb5.keytab` as its host keytab, and
running rootful Docker. The supplied path unit watches that exact file; a
custom SSSD `krb5_keytab` path requires corresponding, reviewed helper and unit
changes. This option does not apply to Docker Desktop on macOS or Windows.
Install `adcli`, SSSD's configured renewal helper, the Kerberos client tools,
and the Docker CLI from the host distribution. The export helper specifically
requires Bash, GNU coreutils, util-linux (`flock`), and MIT Kerberos `klist` and
`ktutil`.

Prefer the joined host's AD `dNSHostName` as `TFM_PUBLIC_HOST`. An alias usually
needs delegated or administrative permission to update the computer object's
SPNs. Do not change SSSD's `ad_hostname` merely to fit an HTTP alias.

Confirm the join, then add the SPN and keys with `adcli`:

```sh
# Set the exact lowercase ad_hostname/dNSHostName from the AD join.
export TFM_AD_HOST_FQDN=linuxhost.example.com
sudo systemctl is-active --quiet sssd
sudo adcli testjoin --domain=example.com --host-keytab=/etc/krb5.keytab
sudo adcli update \
  --domain=example.com \
  --host-fqdn="$TFM_AD_HOST_FQDN" \
  --host-keytab=/etc/krb5.keytab \
  --add-service-principal="HTTP/$TFM_PUBLIC_HOST"
sudo klist -kte /etc/krb5.keytab
```

For an alias that the computer account cannot add, use a narrowly delegated
credential cache; do not grant broader rights than needed to update that
computer object's SPNs:

```sh
sudo -i
kinit delegated-spn-admin@EXAMPLE.COM
adcli update --domain=example.com \
  --host-fqdn=linuxhost.example.com \
  --host-keytab=/etc/krb5.keytab \
  -C \
  --add-service-principal=HTTP/files.example.com
kdestroy
exit
```

Alternatively, an AD administrator can register the SPN from an elevated
Windows PowerShell window. Replace `LINUXHOST` with the Linux computer name:

```powershell
setspn -F -Q HTTP/files.example.com
setspn -C -S HTTP/files.example.com EXAMPLE\LINUXHOST
setspn -L EXAMPLE\LINUXHOST
```

`setspn` changes AD only. Back on Linux, synchronize the AD SPNs into the host
keytab and verify the exact HTTP principal:

```sh
sudo adcli update \
  --domain=example.com \
  --host-fqdn="$TFM_AD_HOST_FQDN" \
  --host-keytab=/etc/krb5.keytab \
  --add-service-principal="HTTP/$TFM_PUBLIC_HOST"
sudo klist -kte /etc/krb5.keytab
```

If machine credentials cannot synchronize an alias registered with `setspn`,
use the delegated credential-cache command above.

Do not use `ktpass` against the joined computer account: it can reset the
machine password outside SSSD/adcli and break the join.

SSSD's AD provider normally checks daily and renews a machine password older
than `ad_maximum_machine_account_password_age` (30 days by default); `0`
disables renewal. Verify that the distribution's configured renewal helper is
installed and succeeds. Current SSSD can use `realm` (with realmd and its
PolicyKit support), while other builds use `adcli`. Leave
`ad_machine_account_password_renewal_opts` at the distribution default because
SSSD documents it as a test option. See the upstream
[SSSD AD-provider documentation](https://github.com/SSSD/sssd/blob/master/src/man/sssd-ad.5.xml)
and the [RHEL renewal guide](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/9/html/integrating_rhel_systems_directly_with_windows_active_directory/managing-direct-connections-to-ad_integrating-rhel-systems-directly-with-active-directory#modifying-the-default-kerberos-host-keytab-renewal-interval).

#### Install the HTTP-only export service

Never mount `/etc/krb5.keytab` into the container. The supplied service takes a
stable private snapshot, keeps only the exact HTTP principal's AES keys, and
publishes them to `/var/lib/tinyfilemanager-keytab/keytabs/http.keytab`. It
always preserves the current KVNO and preserves a matching previous HTTP KVNO
when one exists so AD replication can complete. A newly enrolled HTTP SPN can
legitimately begin with only the current KVNO. The host keytab must be
root-owned and inaccessible to group and others.

Install the root-owned helper, units, and non-secret settings:

```sh
sudo install -d -o root -g root -m 0700 /etc/tinyfilemanager-haproxy
sudo install -o root -g root -m 0755 \
  contrib/haproxy/systemd/tinyfilemanager-keytab-sync \
  /usr/local/sbin/tinyfilemanager-keytab-sync
sudo install -o root -g root -m 0644 \
  contrib/haproxy/systemd/tinyfilemanager-keytab-sync.service \
  contrib/haproxy/systemd/tinyfilemanager-keytab-sync.path \
  contrib/haproxy/systemd/tinyfilemanager-keytab-sync.timer \
  /etc/systemd/system/
sudo install -o root -g root -m 0600 \
  contrib/haproxy/systemd/keytab-sync.env.example \
  /etc/tinyfilemanager-haproxy/keytab-sync.env
sudoedit /etc/tinyfilemanager-haproxy/keytab-sync.env
sudo systemctl daemon-reload
sudo systemctl start tinyfilemanager-keytab-sync.service
sudo klist -kte /var/lib/tinyfilemanager-keytab/keytabs/http.keytab
```

Set the same project and the exported directory for Compose:

```sh
export TFM_KEYTAB_DIR=/var/lib/tinyfilemanager-keytab/keytabs
```

After starting the stack in the next section, enable the change watcher and
hourly reconciliation, then run one final check:

```sh
sudo systemctl enable --now \
  tinyfilemanager-keytab-sync.path \
  tinyfilemanager-keytab-sync.timer
sudo systemctl start tinyfilemanager-keytab-sync.service
sudo systemctl status --no-pager \
  tinyfilemanager-keytab-sync.path \
  tinyfilemanager-keytab-sync.timer
```

When SSSD/adcli closes `/etc/krb5.keytab`, the path unit exports a new
HTTP-only keytab atomically. The helper restarts and health-checks `gssproxy`
and Apache if the stack is running, then records the applied key digest. The
timer catches missed events and retries failed refreshes. Expect a brief
authentication interruption during that restart. A failed run retries after
five minutes; the unit's start-rate limit is disabled for that bounded cadence.

Inspect failures with:

```sh
sudo journalctl -u tinyfilemanager-keytab-sync.service
```

The helper deliberately uses the local root Docker socket and discovers one
stack by `TFM_PROJECT`; its installed script, environment file, and units must
remain root-owned and not group- or other-writable. It never prints key bytes.

The [adcli manual](https://manpages.debian.org/testing/adcli/adcli.8.en.html#UPDATING_THE_MACHINE_ACCOUNT_PASSWORD_AND_OTHER_ATTRIBUTES)
explains SPN synchronization and why the previous KVNO is retained. Red Hat
also documents the underlying
[HTTP-only keytab export pattern](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/9/html/deploying_web_servers_and_reverse_proxies/configuring-the-squid-caching-proxy-server_deploying-web-servers-and-reverse-proxies#setting-up-squid-as-a-caching-proxy-with-kerberos-authentication_configuring-the-squid-caching-proxy-server).

## 4. Check, build, and start

Check the common files on Linux or macOS:

```sh
for file in config.php krb5.conf authorized-users tls.crt tls.key; do
  path="contrib/haproxy/$file"
  [ -f "$path" ] && [ ! -L "$path" ] || exit 1
done
keytab_dir=${TFM_KEYTAB_DIR:-"$PWD/contrib/haproxy/keytab"}
[ -d "$keytab_dir" ] && [ ! -L "$keytab_dir" ] || exit 1
[ -f "$keytab_dir/http.keytab" ] && [ ! -L "$keytab_dir/http.keytab" ] || exit 1
```

For Option A on Windows PowerShell:

```powershell
$keytabDirectory = Get-Item -LiteralPath "contrib\haproxy\keytab" -ErrorAction Stop
if (-not $keytabDirectory.PSIsContainer -or ($keytabDirectory.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw "The keytab path must be a regular directory" }
$paths = "config.php", "krb5.conf", "authorized-users", "tls.crt", "tls.key", "keytab/http.keytab"
$files = $paths | ForEach-Object { Get-Item -LiteralPath "contrib\haproxy\$_" -ErrorAction Stop }
if ($files.Where({ $_.PSIsContainer -or ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) }).Count) { throw "Runtime inputs must be regular files" }
```

Build and start:

```sh
docker compose -f contrib/haproxy/compose.yaml build
docker compose -f contrib/haproxy/compose.yaml up -d --wait
docker compose -f contrib/haproxy/compose.yaml ps
```

Open `https://files.example.com:8443`, substituting your host and port. The
certificate name, URL host, DNS record, and HTTP SPN must agree. A request
without Kerberos receives only a `Negotiate` challenge; an authenticated
principal absent from `authorized-users` receives `403 Forbidden`.

### Configure browser authentication

Configure managed browsers for the exact FQDN. This authentication-only stack
does **not** need credential delegation: leave every delegation allowlist empty.
Do not use wildcards, short names, IP addresses, NTLM, or Basic fallback.

- Microsoft Edge: set `AuthServerAllowlist=files.example.com` through the Edge
  **HTTP authentication** policy (or the equivalent managed preference on
  macOS). Restart and check `edge://policy`.
- Google Chrome: set `AuthServerAllowlist=files.example.com` through Chrome
  policy (`HKLM\SOFTWARE\Policies\Google\Chrome` on Windows,
  `com.google.Chrome` on macOS, or managed policy JSON on Linux). Restart and
  check `chrome://policy`.
- Mozilla Firefox: set enterprise policy
  `Authentication.SPNEGO=["https://files.example.com"]`, keep
  `Authentication.Delegated` and `Authentication.NTLM` empty, and set the
  non-FQDN and proxy options to false. Restart and check `about:policies`.
- Safari: deploy Apple's Kerberos SSO extension with realm `EXAMPLE.COM` and
  `Hosts=[files.example.com]`. The host list scopes authentication.

Policy pages prove only that configuration loaded; a real Kerberos sign-on is
the gate. The [AFS endpoint requirements](../afs/README.md#endpoint-requirements)
add explicit delegation and explain why Safari is not assumed to forward a
credential. See the Edge
[`AuthServerAllowlist`](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/authserverallowlist),
Chromium [`AuthServerAllowlist`](https://chromium.googlesource.com/chromium/src/+/HEAD/components/policy/resources/templates/policy_definitions/HTTPAuthentication/AuthServerAllowlist.yaml),
Firefox [Authentication policy](https://firefox-admin-docs.mozilla.org/reference/policies/authentication/),
and Apple [Kerberos SSO guide](https://support.apple.com/guide/deployment/kerberos-sso-extension-depe6a1cda64/web).

View logs or stop the stack with:

```sh
docker compose -f contrib/haproxy/compose.yaml logs -f
docker compose -f contrib/haproxy/compose.yaml down
```

The Docker-managed data and copied TLS key remain in named volumes after
`down`. In named-volume mode, `down -v` deletes both, including all managed
files. A bind-mounted host data directory and either host keytab directory
survive `down -v`.

### Use a host directory for managed files

First prepare a dedicated directory using the Linux, macOS, or Windows steps
in the [container guide](../container/README.md#serve-an-existing-host-directory).
Keep `TFM_DATA_PATH` set, then use both Compose files for every command:

```sh
docker compose -f contrib/haproxy/compose.yaml -f contrib/haproxy/compose.host-path.yaml up -d --wait
```

### Apply manual runtime-file changes

After changing or atomically replacing a common runtime file or an Option A
keytab, rerun the regular-file check and recreate the consumers. Option B
keytabs are managed by the systemd service instead.

Named-volume mode:

```sh
docker compose -f contrib/haproxy/compose.yaml up -d --force-recreate --wait tls-secrets gssproxy tinyfilemanager haproxy
docker compose -f contrib/haproxy/compose.yaml ps
```

Host-directory mode:

```sh
docker compose -f contrib/haproxy/compose.yaml -f contrib/haproxy/compose.host-path.yaml up -d --force-recreate --wait tls-secrets gssproxy tinyfilemanager haproxy
docker compose -f contrib/haproxy/compose.yaml -f contrib/haproxy/compose.host-path.yaml ps
```

## Host notes

- Linux: standard rootful Docker is the reference deployment. On SELinux, the
  Compose file privately relabels dedicated runtime inputs and data. Rootless
  or user-namespace-remapped Docker requires a separate UID/socket design and
  is not supported by the SSSD rotation service.
- macOS: use Docker Desktop with local shared files and Option A. Confirm its
  Linux VM can resolve the AD realm and reach the KDCs.
- Windows: select Linux containers, local NTFS paths, and Option A. The
  `setspn` command in Option B administers a separate AD-joined Linux host; it
  does not make Docker Desktop use Windows machine credentials.

## Security limits

- This remains the trusted-intranet, non-production Tiny File Manager image.
  The symlink, executable-config, and public-CDN limits in the
  [container guide](../container/README.md#scope) still apply.
- The pinned [`mod_auth_gssapi`](https://sources.debian.org/data/main/liba/libapache2-mod-auth-gssapi/1.6.4-3/src/mod_auth_gssapi.c)
  does not supply the TLS channel binding described by
  [RFC 4559](https://www.rfc-editor.org/rfc/rfc4559). This is not AD Extended
  Protection/CBT; do not use it where relay resistance is a requirement
  without a separately validated authenticator.
- Kerberos replay state is private to one `gssproxy` instance. Run one replica;
  multi-replica operation is not supported by this example.
- Anyone with host root or Docker control can access mounted secrets and
  managed data.
- Healthchecks prove the proxy, HTTPS endpoint, PHP, and credential socket are
  live. Only a real AD/browser test proves DNS, SPN, key version, KDC access,
  authorization, and Kerberos-only negotiation. Validate one real SSSD
  rotation and subsequent browser sign-on before relying on automatic renewal.

See the upstream [gssproxy Apache guide](https://github.com/gssapi/gssproxy/blob/main/docs/Apache.md),
[HAProxy Community authentication documentation](https://www.haproxy.com/documentation/haproxy-configuration-tutorials/security/authentication/basic-authentication/),
and Microsoft [`setspn`](https://learn.microsoft.com/windows-server/administration/windows-commands/setspn)
documentation for the underlying components.
