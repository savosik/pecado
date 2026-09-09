FROM php:8.3-fpm

# Arguments
ARG user=laravel
ARG uid=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    librabbitmq-dev \
    supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sockets \
    && pecl install amqp redis \
    && docker-php-ext-enable amqp redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user
RUN useradd -G www-data,root -u $uid -d /home/$user $user \
    && mkdir -p /home/$user/.composer \
    && chown -R $user:$user /home/$user

# Set working directory
WORKDIR /var/www


# Планировщик Laravel запускает supervisor (docker/supervisor/conf.d/scheduler.conf,
# `php artisan schedule:work`), а не cron. Cron здесь стоял с первого коммита и не
# работал ни дня: Debian-cron даёт заданиям PATH=/usr/bin:/bin, а php в этом образе
# лежит в /usr/local/bin — `php artisan schedule:run` падал с «php: not found» в /dev/null.
# Обнаружено 2026-09-09. Не возвращать cron без строки PATH= в crontab.

USER $user
