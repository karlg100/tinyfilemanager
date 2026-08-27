# Tiny File Manager container

This builds a local-filesystem Tiny File Manager service. It requires an
external configuration file, stores managed files outside Apache's document
root, and listens on `127.0.0.1:8080` by default.

Use a current Docker Engine with BuildKit and the `docker compose` plugin. Run
all commands from the repository root and include the shown `-f` option.

Choose a unique project name for this deployment. Compose refuses to run
without it, preventing separate checkouts from sharing a data volume:

```sh
export TFM_PROJECT=tinyfilemanager-local
```

## Build

```sh
docker compose -f contrib/container/compose.yaml build
```

The equivalent direct build is:

```sh
docker build -f contrib/container/Dockerfile \
  -t "tinyfilemanager:$TFM_PROJECT" .
```

## Configure

Create the ignored runtime configuration:

```sh
cp contrib/container/config.php.example contrib/container/config.php
```

Generate a password hash without putting the password in the command line:

```sh
docker run --rm -it --entrypoint tinyfilemanager-hash-password \
  "tinyfilemanager:$TFM_PROJECT"
```

Replace `REPLACE_WITH_PASSWORD_HASH` in `config.php` with the generated hash.
Edit the user name, read-only users, and time zone as needed. The application
upload limit may be lowered in `config.php`; raising the 256 MiB server limit
also requires editing `php.ini` and rebuilding. Keep `$root_path` at
`/srv/tinyfilemanager/data`; startup rejects missing credentials, placeholder
credentials, and baseline-policy mismatches. Per-user directory mappings are
not supported by this image.

The supplied configuration disables settings changes, direct links, media
previews, online document viewing, and URL uploads. Managed files remain
outside Apache's document root and downloads continue through the authenticated
application.

### Change the managed root

Tiny File Manager's `$root_path` is separate from Apache's `DocumentRoot`.
Keep Apache's document root at `/var/www/html` and mount a different host
directory by replacing the `data` volume entry under `volumes:` in
`compose.yaml`:

```yaml
- type: bind
  source: /absolute/path/on/host
  target: /srv/tinyfilemanager/data
  bind:
    create_host_path: false
    selinux: Z
```

The host directory must be readable and writable by container UID/GID `33:33`.
Do not mount managed files below `/var/www/html`, where Apache could serve them
without Tiny File Manager authentication. To change the in-container path
itself, update the Compose target, `$root_path` in `config.php`, and the fixed
path in `validate-config.php`, then rebuild.

For a TLS reverse proxy, set `$container_tls_proxy = true` so session cookies
are marked `Secure`. Keep the container port on loopback, and configure the
proxy to replace rather than append forwarded headers.

## Start

```sh
docker compose -f contrib/container/compose.yaml up -d
docker compose -f contrib/container/compose.yaml ps
```

Open <http://127.0.0.1:8080>. To use another host port, set `TFM_PORT`, for
example `TFM_PORT=8081 docker compose -f contrib/container/compose.yaml up -d`.

View logs or stop the service with:

```sh
docker compose -f contrib/container/compose.yaml logs -f
docker compose -f contrib/container/compose.yaml down
```

The named data volume survives `down`. Running `down -v` deletes it.

The built-in healthcheck is PHP/HTTP liveness only; it does not attest login,
data integrity, or production readiness.

## Scope

- This is a trusted-local, non-production image. Tiny File Manager follows
  filesystem symlinks, so the data mount is not a confinement boundary. Do not
  use restored/untrusted trees or trees containing symlinks.
- `config.php` is trusted executable PHP. Start from the supplied example; do
  not mount configuration obtained from another user or system.
- The image has no TLS terminator. Keep the loopback binding or use the TLS
  proxy mode above; do not expose it directly to an untrusted network.
- The current UI loads assets from public CDNs, so this image is unsuitable
  for controlled or protected data.
- Never put passwords, private keys, or other secrets in the image or build
  context. Mount runtime configuration read-only.
