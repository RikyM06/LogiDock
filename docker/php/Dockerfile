# PHP-FPM per lo sviluppo. Stessa versione di PHP della produzione (Ubuntu 24.04: 8.3).
FROM php:8.3-fpm

# Estensioni usate dal progetto: PDO MySQL, intl, mbstring, zip (per Composer).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_mysql intl mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Composer, copiato dall'immagine ufficiale.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurazione "production" di PHP anche in sviluppo: gli errori li gestisce l'applicazione.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY zz-logidock.conf /usr/local/etc/php-fpm.d/zz-logidock.conf

WORKDIR /var/www/html
