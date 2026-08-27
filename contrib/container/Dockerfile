# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32

ARG PHP_IMAGE=php:8.3.33-apache-bookworm@sha256:69e641a40929c9db9d1ce43b982d46f6701ccdd5f7b4c32d5bd773cae6882393
FROM ${PHP_IMAGE} AS common

LABEL org.opencontainers.image.title="Tiny File Manager" \
      org.opencontainers.image.description="Local-filesystem Tiny File Manager service" \
      org.opencontainers.image.source="https://github.com/prasathmani/tinyfilemanager" \
      org.opencontainers.image.lifecycle="trusted-local-non-production"

# Build ZipArchive and retain only its runtime libraries. The other recommended
# extensions are already present in the pinned official PHP image.
RUN set -eux; \
    saved_apt_mark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev=1.7.3-1+b1; \
    docker-php-ext-install -j"$(nproc)" zip; \
    apt-mark auto '.*' > /dev/null; \
    if [ -n "$saved_apt_mark" ]; then apt-mark manual $saved_apt_mark; fi; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
      | awk '/=>/ { library = $(NF-1); if (index(library, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", library); print "*" library }' \
      | sort -u \
      | xargs -r dpkg-query --search \
      | awk 'sub(":$", "", $1) { print $1 }' \
      | sort -u \
      | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'foreach (["fileinfo", "iconv", "mbstring", "posix", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "missing PHP extension: $extension\n"); exit(1); } }'

COPY tinyfilemanager.php /var/www/html/index.php
COPY translation.json /var/www/html/translation.json
COPY contrib/container/healthz.php /var/www/html/healthz.php
COPY LICENSE /usr/share/licenses/tinyfilemanager/LICENSE
COPY contrib/container/apache.conf /etc/apache2/conf-available/tinyfilemanager.conf
COPY contrib/container/vhost.conf /etc/apache2/sites-available/tinyfilemanager.conf
COPY contrib/container/php.ini /usr/local/etc/php/conf.d/tinyfilemanager.ini
COPY contrib/container/container-entrypoint /usr/local/bin/container-entrypoint
COPY contrib/container/container-healthcheck /usr/local/bin/container-healthcheck
COPY contrib/container/hash-password /usr/local/bin/tinyfilemanager-hash-password
COPY contrib/container/validate-config.php /usr/local/lib/tinyfilemanager/validate-config.php

RUN set -eux; \
    rm -f /var/www/html/index.html; \
    install -d -o www-data -g www-data -m 0770 /srv/tinyfilemanager/data; \
    install -m 000 /dev/null /var/www/html/config.php; \
    chown -R root:root /var/www/html /usr/local/lib/tinyfilemanager; \
    find /var/www/html /usr/local/lib/tinyfilemanager -type d -exec chmod 0555 '{}' +; \
    find /var/www/html /usr/local/lib/tinyfilemanager -type f ! -name config.php -exec chmod 0444 '{}' +; \
    chmod 0555 /usr/local/bin/container-entrypoint \
      /usr/local/bin/container-healthcheck \
      /usr/local/bin/tinyfilemanager-hash-password; \
    a2enmod headers; \
    a2enconf tinyfilemanager; \
    a2dissite 000-default; \
    a2ensite tinyfilemanager; \
    php -l /var/www/html/index.php; \
    php -l /var/www/html/healthz.php; \
    php -l /usr/local/lib/tinyfilemanager/validate-config.php; \
    apache2ctl configtest

VOLUME ["/srv/tinyfilemanager/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD ["/usr/local/bin/container-healthcheck"]

ENTRYPOINT ["/usr/local/bin/container-entrypoint"]
CMD ["apache2-foreground"]

# Optional target used by contrib/haproxy. The default runtime below remains
# the local-password image. This target contains the gssproxy client only;
# the HTTP service keytab stays in the separate gssproxy container.
FROM common AS gssapi

LABEL org.opencontainers.image.description="Tiny File Manager with Apache GSSAPI authentication"

COPY contrib/haproxy/ports.conf /etc/apache2/ports.conf
COPY contrib/haproxy/tinyfilemanager-gssapi.conf /etc/apache2/sites-available/tinyfilemanager-gssapi.conf
COPY contrib/haproxy/gssapi-entrypoint /usr/local/bin/gssapi-entrypoint
COPY contrib/haproxy/container-healthcheck-tls /usr/local/bin/container-healthcheck-tls

RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      gssproxy=0.9.1-1+b1 \
      libapache2-mod-auth-gssapi=1.6.4-3; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod auth_gssapi authz_groupfile ssl; \
    a2dissite tinyfilemanager; \
    a2ensite tinyfilemanager-gssapi; \
    install -m 000 /dev/null /etc/krb5.conf; \
    test -s /etc/gss/mech.d/proxymech.conf; \
    find /usr/lib -type f -path '*/gssproxy/proxymech.so' -print -quit \
      | grep -q .

RUN set -eux; \
    chown root:root \
      /etc/apache2/ports.conf \
      /etc/apache2/sites-available/tinyfilemanager-gssapi.conf \
      /usr/local/bin/gssapi-entrypoint \
      /usr/local/bin/container-healthcheck-tls; \
    chmod 0444 \
      /etc/apache2/ports.conf \
      /etc/apache2/sites-available/tinyfilemanager-gssapi.conf; \
    chmod 0555 \
      /usr/local/bin/gssapi-entrypoint \
      /usr/local/bin/container-healthcheck-tls; \
    test -e /etc/apache2/mods-enabled/auth_gssapi.load; \
    test -e /etc/apache2/mods-enabled/ssl.load

EXPOSE 8443

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD ["/usr/local/bin/container-healthcheck-tls"]

ENTRYPOINT ["/usr/local/bin/gssapi-entrypoint"]
CMD ["apache2-foreground"]

FROM common AS runtime
