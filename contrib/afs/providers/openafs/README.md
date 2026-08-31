# Option 1: OpenAFS 1.8 provider

This is the provider currently supplied by `contrib/afs`. It is the default for
the existing [AFS recipe](../../README.md) and preserves all existing build,
start, rotation, and rollback commands.

## Provider contract

- Build: [`../../Dockerfile`](../../Dockerfile), using the pinned public inputs
  in [`../../sources.lock`](../../sources.lock).
- Runtime: [`../../compose.yaml`](../../compose.yaml).
- Host: rootful Linux Docker Engine with a host-managed OpenAFS 1.8 Cache
  Manager.
- Filesystem type: exactly `afs`.
- Client configuration: `ThisCell` and `CellServDB` mounted at `/etc/openafs`.
- Token path: rxkad-k5 through the hardened OpenAFS-linked `mod_waklog` and its
  provider-linked PAG preflight.

Follow the complete parent recipe rather than treating this summary as a quick
start. The AD object, browser delegation, HTTP-only keytab, two-user isolation,
cleanup, failure, cutover, and rollback gates all remain mandatory.

## AuriStor server interoperability is not a native provider

This OpenAFS client may be tested against AuriStor servers only when the
AuriStor administrator approves the documented OpenAFS-client/RxKAD security
and data-model restrictions. The mount remains `afs`, the client remains
OpenAFS, and the connection does not gain native yfs-rxgk or AuriStor-only
semantics.

Do not use this image with an `auristorfs` mount, install AuriStor libraries
over its OpenAFS libraries, or relabel it as native AuriStor. Use the separate
[Option 2 provider contract](../auristor/README.md) for that case.
