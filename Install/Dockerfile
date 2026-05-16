# FoleyTimePro — GitHub-clone based image (used by CI/CD)
# For local builds use the root Dockerfile instead.

FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    nginx supervisor git libzip-dev libpng-dev \
    libfreetype6-dev libjpeg62-turbo-dev unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.max_accelerated_files=10000\nopcache.memory_consumption=192\nopcache.interned_strings_buffer=16\nopcache.fast_shutdown=1\n' \
    > /usr/local/etc/php/conf.d/opcache.ini

RUN printf 'upload_max_filesize=20M\npost_max_size=20M\nmemory_limit=256M\nmax_execution_time=60\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' \
    > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

COPY www.conf /usr/local/etc/php-fpm.d/www.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

RUN git clone https://github.com/TheTractorHacker/FoleyTimePro.git . \
    && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction \
    && mkdir -p logs uploads cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 logs uploads cache \
    && rm -rf .git android-app Install/.git

EXPOSE 80
ENTRYPOINT []
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
