# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32

ARG PHP_IMAGE=php:8.3.33-apache-bookworm@sha256:69e641a40929c9db9d1ce43b982d46f6701ccdd5f7b4c32d5bd773cae6882393

FROM ${PHP_IMAGE} AS module-builder

ARG MAG_SOURCE_URI=https://github.com/gssapi/mod_auth_gssapi/archive/refs/tags/v1.6.4.tar.gz
ARG MAG_SOURCE_DIGEST=7323affdba44ff560373c996b5eee8ac54fbb6067de5be8737c2f54c64c4e8e6
ARG WAKLOG_SOURCE_URI=https://downloads.sourceforge.net/project/modwaklog/modwaklog/1.1.0/mod_waklog-1.1.0.tgz
ARG WAKLOG_SOURCE_DIGEST=81b7e7ea38ba6c723b55f234ded9b30e983d227ad82398a368946741a4494a51

COPY contrib/afs/patches /patches
COPY contrib/afs/afs-preflight.c /build/afs-preflight.c

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    set -eux; \
    sed -i 's,http://deb.debian.org,https://deb.debian.org,g' \
      /etc/apt/sources.list.d/debian.sources; \
    apt-get -o Acquire::Retries=5 update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
      apache2-dev=2.4.68-1~deb12u1 \
      autoconf \
      automake \
      bison \
      build-essential \
      ca-certificates \
      curl \
      flex \
      libkrb5-dev=1.20.1-2+deb12u5 \
      libopenafs-dev=1.8.9-1+deb12u1 \
      libssl-dev=3.0.20-1~deb12u2 \
      libtool \
      pkg-config; \
    install -d /build/auth /build/waklog /out; \
    curl -fL --retry 5 --retry-all-errors -o /tmp/mod_auth_gssapi.tar.gz "$MAG_SOURCE_URI"; \
    echo "$MAG_SOURCE_DIGEST  /tmp/mod_auth_gssapi.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/mod_auth_gssapi.tar.gz -C /build/auth --strip-components=1; \
    patch --batch --forward -d /build/auth -p1 < /patches/mod_auth_gssapi/0001-delegated-cache-contract.patch; \
    cd /build/auth; \
    autoreconf -fi; \
    CFLAGS='-O2 -fstack-protector-strong -D_FORTIFY_SOURCE=2' \
      LDFLAGS='-Wl,-z,relro,-z,now' \
      ./configure --with-apxs=/usr/bin/apxs --with-apr=/usr/bin/apr-1-config; \
    make -j"$(nproc)" V=1; \
    install -m 0555 src/.libs/mod_auth_gssapi.so /out/mod_auth_gssapi.so; \
    curl -fL --retry 5 --retry-all-errors -o /tmp/mod_waklog.tgz "$WAKLOG_SOURCE_URI"; \
    echo "$WAKLOG_SOURCE_DIGEST  /tmp/mod_waklog.tgz" | sha256sum -c -; \
    tar -xzf /tmp/mod_waklog.tgz -C /build/waklog --strip-components=1; \
    for patch_file in /patches/mod_waklog/*.patch; do \
      patch --batch --forward -d /build/waklog -p1 < "$patch_file"; \
    done; \
    cd /build/waklog; \
    autoreconf -fi; \
    CFLAGS='-O2 -fstack-protector-strong -D_FORTIFY_SOURCE=2' \
      LDFLAGS='-Wl,-z,relro,-z,now' \
      ./configure \
        --with-apxs=/usr/bin/apxs \
        --with-afs-libs=/usr/lib/afs \
        --with-afs-headers=/usr/include/afs \
        --with-krb5-libs="/usr/lib/$(gcc -print-multiarch)" \
        --with-krb5-headers=/usr/include; \
    make -j"$(nproc)" V=1; \
    make check; \
    install -m 0555 .libs/mod_waklog.so /out/mod_waklog.so; \
    gcc -O2 -Wall -Wextra -Werror -fstack-protector-strong \
      -o /out/afs-preflight /build/afs-preflight.c \
      -lkopenafs -lafsauthent -lafsrpc; \
    strip --strip-unneeded /out/mod_auth_gssapi.so /out/mod_waklog.so /out/afs-preflight; \
    ldd /out/mod_auth_gssapi.so; \
    ldd /out/mod_waklog.so; \
    /build/waklog/test_hardening

FROM ${PHP_IMAGE} AS runtime

LABEL org.opencontainers.image.title="Tiny File Manager AFS profile" \
      org.opencontainers.image.description="Direct-delegation AFS profile using the OpenAFS 1.8 ABI" \
      org.opencontainers.image.source="https://github.com/prasathmani/tinyfilemanager" \
      org.opencontainers.image.lifecycle="trusted-intranet-non-production"

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    set -eux; \
    sed -i 's,http://deb.debian.org,https://deb.debian.org,g' \
      /etc/apt/sources.list.d/debian.sources; \
    apt-get -o Acquire::Retries=5 update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
      krb5-user=1.20.1-2+deb12u5 \
      libafsauthent2=1.8.9-1+deb12u1 \
      libafsrpc2=1.8.9-1+deb12u1 \
      libapache2-mod-auth-gssapi=1.6.4-3 \
      libkopenafs2=1.8.9-1+deb12u1; \
    rm -rf /var/lib/apt/lists/*

COPY --from=module-builder /out/mod_auth_gssapi.so /usr/lib/apache2/modules/mod_auth_gssapi.so
COPY --from=module-builder /out/mod_waklog.so /usr/lib/apache2/modules/mod_waklog.so
COPY --from=module-builder /out/afs-preflight /usr/local/bin/afs-preflight
COPY tinyfilemanager.php /var/www/html/index.php
COPY translation.json /var/www/html/translation.json
COPY LICENSE /usr/share/licenses/tinyfilemanager/LICENSE
COPY contrib/afs/LICENSE.mod-auth-gssapi /usr/share/licenses/mod-auth-gssapi/LICENSE
COPY contrib/afs/LICENSE.mod-waklog /usr/share/licenses/mod-waklog/LICENSE
COPY contrib/afs/sources.lock /usr/share/doc/tinyfilemanager-afs/sources.lock
COPY contrib/afs/patches/SHA256SUMS /usr/share/doc/tinyfilemanager-afs/patches.SHA256SUMS
COPY contrib/container/apache.conf /etc/apache2/conf-available/tinyfilemanager.conf
COPY contrib/container/healthz.php /var/www/html/healthz.php
COPY contrib/container/php.ini /usr/local/etc/php/conf.d/tinyfilemanager.ini
COPY contrib/afs/validate-config.php /usr/local/lib/tinyfilemanager/validate-config.php
COPY contrib/afs/ports.conf /etc/apache2/ports.conf
COPY contrib/afs/tinyfilemanager-afs.conf /etc/apache2/sites-available/tinyfilemanager-afs.conf
COPY contrib/afs/waklog.load /etc/apache2/mods-available/waklog.load
COPY contrib/afs/afs-entrypoint /usr/local/bin/afs-entrypoint
COPY contrib/afs/container-healthcheck-afs /usr/local/bin/container-healthcheck-afs

RUN set -eux; \
    rm -f /var/www/html/index.html; \
    install -d -m 0755 /etc/openafs /run/policy; \
    install -m 000 /dev/null /etc/krb5.conf; \
    install -m 000 /dev/null /etc/openafs/CellServDB; \
    install -m 000 /dev/null /etc/openafs/ThisCell; \
    install -m 000 /dev/null /run/policy/authorized-users; \
    install -m 000 /dev/null /var/www/html/config.php; \
    chown -R root:root \
      /var/www/html \
      /usr/local/lib/tinyfilemanager \
      /etc/apache2/ports.conf \
      /etc/apache2/sites-available/tinyfilemanager-afs.conf \
      /etc/apache2/mods-available/waklog.load; \
    find /var/www/html /usr/local/lib/tinyfilemanager -type d -exec chmod 0555 '{}' +; \
    find /var/www/html /usr/local/lib/tinyfilemanager -type f ! -name config.php -exec chmod 0444 '{}' +; \
    chmod 0444 \
      /etc/apache2/ports.conf \
      /etc/apache2/sites-available/tinyfilemanager-afs.conf \
      /etc/apache2/mods-available/waklog.load; \
    chmod 0555 \
      /usr/local/bin/afs-entrypoint \
      /usr/local/bin/afs-preflight \
      /usr/local/bin/container-healthcheck-afs; \
    a2enconf tinyfilemanager; \
    a2enmod auth_gssapi authz_groupfile headers request ssl waklog; \
    a2dissite 000-default tinyfilemanager || true; \
    a2ensite tinyfilemanager-afs; \
    a2query -M | grep -qx prefork; \
    php -l /var/www/html/index.php; \
    php -l /var/www/html/healthz.php; \
    php -l /usr/local/lib/tinyfilemanager/validate-config.php; \
    test ! -e /etc/apache2/mods-enabled/http2.load; \
    test ! -e /etc/apache2/mods-enabled/cgi.load; \
    test ! -e /etc/apache2/mods-enabled/cgid.load; \
    test ! -e /etc/apache2/mods-enabled/proxy_fcgi.load

EXPOSE 8443

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD ["/usr/local/bin/container-healthcheck-afs"]

ENTRYPOINT ["/usr/local/bin/afs-entrypoint"]
CMD ["apache2-foreground"]
