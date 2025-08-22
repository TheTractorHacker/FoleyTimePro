FROM php:8.2-fpm

# Install Nginx, Git, Supervisor, and other dependencies
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    supervisor \
    libzip-dev \
    unzip \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mysqli zip gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy Nginx and Supervisor configuration
COPY nginx.conf /etc/nginx/sites-available/default
RUN rm /etc/nginx/sites-enabled/default && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory
WORKDIR /var/www/html

# Add git safe directory
RUN git config --global --add safe.directory /var/www/html

# Clone the repository
RUN find . -mindepth 1 -delete && git clone https://github.com/D-Best-Apps/Timesmart.git .

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Change ownership of the files to www-data
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for Nginx
EXPOSE 80

# Run Supervisor to start Nginx and PHP-FPM
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
