# SPDX-FileCopyrightText: 2026 Thomas Vincent
# SPDX-License-Identifier: GPL-2.0-or-later

# Cacti 1.2.31 on Apache with mod_php. Base image pinned by index digest
# (docker buildx imagetools inspect php:8.2-apache); Dependabot bumps it.
FROM php:8.2-apache@sha256:81af315d350bc9a59794cc3b353b9c772062a34a1300138274532f221f854cc5

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip openssl rrdtool snmp mariadb-client \
      libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libicu-dev \
      libldap2-dev libzip-dev libsnmp-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -q DEB_BUILD_MULTIARCH)" \
 && docker-php-ext-install -j"$(nproc)" gd gmp intl ldap mysqli pdo_mysql snmp sockets pcntl zip gettext

# Cacti's installer wants these; display_errors stays off and errors go to the log.
RUN printf '%s\n' \
      'memory_limit = 512M' 'max_execution_time = 60' 'date.timezone = UTC' \
      'display_errors = Off' 'log_errors = On' \
      > /usr/local/etc/php/conf.d/zz-mcp-it.ini

COPY cacti.conf /etc/apache2/conf-available/cacti.conf
RUN a2enconf cacti
