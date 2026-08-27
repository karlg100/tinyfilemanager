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

## Requirements

- A current Docker Engine on Linux, or Docker Desktop using Linux containers
  on macOS or Windows.
- A DNS name that resolves to the container host, a matching TLS certificate,
  and synchronized clocks.
- Network access from the `gssproxy` container to AD DNS and KDCs.
- A dedicated `HTTP/files.example.com@EXAMPLE.COM` service principal and an
  AES keytab containing only that principal.
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

## 2. Create the runtime files

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
- `http.keytab`: keytab for exactly `HTTP/TFM_PUBLIC_HOST@TFM_AD_REALM`.

On Linux or macOS, restrict the private inputs:

```sh
chmod 0600 contrib/haproxy/tls.key contrib/haproxy/http.keytab
```

On Windows, use NTFS permissions to grant read access only to the operator and
administrators. Use local files, not a UNC or redirected network-share path.
All six runtime files must be regular files, not symlinks or reparse points.
They are ignored by Git and excluded from every supplied Docker build context.

Check this on Linux or macOS before starting:

```sh
for file in config.php krb5.conf authorized-users tls.crt tls.key http.keytab; do
  path="contrib/haproxy/$file"
  [ -f "$path" ] && [ ! -L "$path" ] || exit 1
done
```

On Windows PowerShell:

```powershell
$paths = "config.php", "krb5.conf", "authorized-users", "tls.crt", "tls.key", "http.keytab"
$files = $paths | ForEach-Object { Get-Item -LiteralPath "contrib\haproxy\$_" -ErrorAction Stop }
if ($files.Where({ $_.PSIsContainer -or ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) }).Count) { throw "Runtime inputs must be regular files" }
```

An AD administrator should register the SPN with duplicate checking, for
example:

```powershell
setspn -S HTTP/files.example.com EXAMPLE\tinyfilemanager-http
```

Export the AES keytab with your organization's supported AD procedure. Use a
dedicated account, do not enable delegation, and do not place extra principals
in the keytab. The service refuses weak key types and unexpected principals.

## 3. Build and start

```sh
docker compose -f contrib/haproxy/compose.yaml build
docker compose -f contrib/haproxy/compose.yaml up -d --wait
docker compose -f contrib/haproxy/compose.yaml ps
```

Open `https://files.example.com:8443`, substituting your host and port. The
certificate name, URL host, DNS record, and HTTP SPN must agree. A request
without Kerberos receives only a `Negotiate` challenge; an authenticated
principal absent from `authorized-users` receives `403 Forbidden`.

View logs or stop the stack with:

```sh
docker compose -f contrib/haproxy/compose.yaml logs -f
docker compose -f contrib/haproxy/compose.yaml down
```

The Docker-managed data and copied TLS key remain in named volumes after
`down`. In named-volume mode, `down -v` deletes both, including all managed
files. A bind-mounted host data directory survives `down -v`.

### Use a host directory for managed files

First prepare a dedicated directory using the Linux, macOS, or Windows steps
in the [container guide](../container/README.md#serve-an-existing-host-directory).
Keep `TFM_DATA_PATH` set, then use both Compose files for every command:

```sh
docker compose -f contrib/haproxy/compose.yaml -f contrib/haproxy/compose.host-path.yaml up -d --wait
```

### Apply runtime-file changes

After changing or atomically replacing any of the six runtime files, rerun the
regular-file check and recreate the consumers. This remounts new file inodes,
revalidates configuration, and refreshes the private TLS volume.

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
  Compose file applies private labels to dedicated runtime files and data.
  Rootless or user-namespace-remapped Docker requires a live socket/UID test.
- macOS: use Docker Desktop with local shared files. Confirm its Linux VM can
  resolve the AD realm and reach the KDCs.
- Windows: select Linux containers and local NTFS paths. Confirm Docker
  Desktop can reach AD DNS/KDCs and configure the browser's Integrated
  Authentication allowlist for the site.

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
- Anyone with Docker control can access mounted secrets and managed data.
- Healthchecks prove the proxy, HTTPS endpoint, PHP, and credential socket are
  live. Only a real AD/browser test proves DNS, SPN, key version, KDC access,
  authorization, and Kerberos-only negotiation.

See the upstream [gssproxy Apache guide](https://github.com/gssapi/gssproxy/blob/main/docs/Apache.md),
[HAProxy Community authentication documentation](https://www.haproxy.com/documentation/haproxy-configuration-tutorials/security/authentication/basic-authentication/),
and Microsoft [`setspn`](https://learn.microsoft.com/windows-server/administration/windows-commands/setspn)
documentation for the underlying components.
