# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32

ARG HAPROXY_IMAGE=haproxy:3.2.22-alpine3.24@sha256:79799e8b2977e60802774fa53d29e6b54e045402cdd8a8b9fe43923e7095a047
FROM ${HAPROXY_IMAGE}

LABEL org.opencontainers.image.title="Tiny File Manager HAProxy frontend" \
      org.opencontainers.image.description="TCP pass-through frontend for Tiny File Manager TLS and GSSAPI" \
      org.opencontainers.image.source="https://github.com/prasathmani/tinyfilemanager" \
      org.opencontainers.image.lifecycle="trusted-intranet-non-production"

USER root
COPY --chown=haproxy:haproxy contrib/haproxy/haproxy.cfg /usr/local/etc/haproxy/haproxy.cfg
COPY --chown=root:root contrib/haproxy/haproxy-healthcheck /usr/local/bin/haproxy-healthcheck

RUN set -eux; \
    chmod 0444 /usr/local/etc/haproxy/haproxy.cfg; \
    chmod 0555 /usr/local/bin/haproxy-healthcheck; \
    TFM_PUBLIC_HOST=files.example.com \
      haproxy -c -f /usr/local/etc/haproxy/haproxy.cfg

USER haproxy

EXPOSE 8443

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
  CMD ["/usr/local/bin/haproxy-healthcheck"]
