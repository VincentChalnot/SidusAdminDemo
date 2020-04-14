# Base image is a ubuntu server image with some enhancements
FROM bluebeargaming/ubuntu-server

USER root

RUN add-apt-repository ppa:ondrej/php

# Custom php extensions
RUN apt-get update && apt-get install -y \
    mariadb-client \
    nginx \
    php7.4-fpm \
    php7.4-cli \
    php7.4-intl \
    php7.4-xml \
    php7.4-zip \
    php7.4-mbstring \
    php7.4-gd \
    php7.4-mysql \
    php7.4-curl \
    php7.4-apcu \
    php-xdebug

# Fix fastcgi params in nginx
COPY nginx/snippets/php-fpm.conf /etc/nginx/snippets/php-fpm.conf

# Enabling our custom configs
COPY php/mods-available/symfony.ini /etc/php/7.4/mods-available/symfony.ini
COPY php/mods-available/xdebug.ini /etc/php/7.4/mods-available/xdebug.ini
RUN phpenmod symfony
# xdebug is already enabled by default

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER 1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY start.sh /var/www/start.sh

# Rollback default shell
USER www-data

ENTRYPOINT ["/var/www/start.sh"]
