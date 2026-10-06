# Backend Laravel — imagen lista para un docker-compose global (multi-repo).
# Este contenedor solo sirve la API. La DB, el frontend y el compose global
# viven fuera de este repo.
FROM php:8.3-apache

# Dependencias del sistema + extensiones PHP que necesita Laravel/MySQL.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache: reescritura para public/.htaccess y DocumentRoot en public/.
RUN a2enmod rewrite headers
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
COPY docker/apache/laravel.conf /etc/apache2/sites-available/000-default.conf

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Instala dependencias primero para aprovechar la caché de capas.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# Copia el resto del proyecto (respeta .dockerignore).
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# Config PHP de producción (opcache, límites, zona horaria UTC).
COPY docker/php/production.ini /usr/local/etc/php/conf.d/laravel-production.ini

# Permisos de escritura para el usuario de Apache.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint: espera MySQL, genera APP_KEY si falta y migra.
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

# La ruta /up existe (health: '/up' en bootstrap/app.php).
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
