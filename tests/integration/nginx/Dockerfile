# SPDX-FileCopyrightText: 2026 Thomas Vincent
# SPDX-License-Identifier: GPL-2.0-or-later

# Cacti develop on nginx with PHP-FPM in one container. Base images are pinned by
# index digest (docker buildx imagetools inspect); Dependabot bumps them.
FROM composer:2@sha256:919efe8e62179c71dfd31fad035fd671fe7454211c6b2aa30a10af0ab4acedba AS composer

FROM php:8.4-fpm-bookworm@sha256:e4b1eec29a5be22428aa93fb43da75a0e39006e403da0c3e93631dde92f067df

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx git unzip openssl rrdtool snmp fping mariadb-client \
      libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libicu-dev \
      libldap2-dev libzip-dev libsnmp-dev \
 && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -q DEB_BUILD_MULTIARCH)" \
 && docker-php-ext-install -j"$(nproc)" gd gmp intl ldap mysqli pdo_mysql snmp sockets pcntl zip gettext

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN printf '%s\n' \
      'memory_limit = 512M' 'max_execution_time = 60' 'date.timezone = UTC' \
      'display_errors = Off' 'log_errors = On' \
      > /usr/local/etc/php/conf.d/zz-mcp-it.ini \
 && printf '%s\n' '[www]' 'catch_workers_output = yes' 'decorate_workers_output = no' \
      > /usr/local/etc/php-fpm.d/zz-mcp-it.conf

COPY cacti.conf /etc/nginx/conf.d/cacti.conf

# Run PHP-FPM in the background and nginx in the foreground, so the container
# lives as long as nginx does.
RUN printf '%s\n' '#!/bin/sh' 'set -e' 'php-fpm -D' 'exec nginx -g "daemon off;"' \
      > /usr/local/bin/mcp-it-entrypoint \
 && chmod 0755 /usr/local/bin/mcp-it-entrypoint
ENTRYPOINT ["/usr/local/bin/mcp-it-entrypoint"]
