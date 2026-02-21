FROM php:8.3-fpm

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        opcache \
        zip \
        gd \
        fileinfo \
        bcmath \
        mbstring

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Create dev user with host UID/GID for correct file permissions
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN groupadd -g ${GROUP_ID} devuser || true \
    && useradd -u ${USER_ID} -g ${GROUP_ID} -m -s /bin/bash devuser

# Configure PHP-FPM to run workers as devuser
RUN sed -i "s/user = www-data/user = devuser/" /usr/local/etc/php-fpm.d/www.conf \
    && sed -i "s/group = www-data/group = devuser/" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
