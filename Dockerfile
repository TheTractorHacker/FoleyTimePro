# FoleyTimePro — Production Docker Image
# Build:  docker build -t foleyit/foleytimepro:latest .
# Push:   docker push foleyit/foleytimepro:latest

FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
    nginx supervisor libzip-dev libpng-dev \
    libfreetype6-dev libjpeg-dev unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.max_accelerated_files=10000\nopcache.memory_consumption=192\nopcache.interned_strings_buffer=16\nopcache.fast_shutdown=1\n' \
    > /usr/local/etc/php/conf.d/opcache.ini

RUN printf 'upload_max_filesize=20M\npost_max_size=20M\nmemory_limit=256M\nmax_execution_time=60\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' \
    > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html

# Cache composer deps as their own layer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --ignore-platform-reqs

# Copy app source
COPY --chown=www-data:www-data . .

# Configure nginx
COPY docker/config/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure PHP-FPM
COPY docker/config/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Configure supervisor
COPY docker/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Runtime dirs + permissions
RUN mkdir -p logs uploads cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 logs uploads cache

# Strip dev-only files from image
RUN rm -rf .git android-app timeclock timeclock.* task-11-23.txt \
           PUSH_NOTIFICATION_SETUP_COMPLETE.md

EXPOSE 80
ENTRYPOINT []
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
