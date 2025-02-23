FROM php:7.4-fpm

RUN apt-get update && apt-get install -y \
      libxml2-dev \
      libicu-dev \
      libcurl3-dev \
      libsqlite3-dev \
      libedit-dev \
      zlib1g-dev \
      libfreetype6-dev \
      libjpeg62-turbo-dev \
      libmemcached-dev \
      libzip-dev \
      libonig-dev \
      libpq-dev
RUN docker-php-ext-install \
        intl \
        pdo_mysql \
        pdo_pgsql \
        opcache \
        bcmath \
        soap \
        sockets \
        zip \
     && docker-php-ext-configure gd \
     && docker-php-ext-install -j$(nproc) gd

RUN pecl install apcu \
    && pecl install apcu_bc \
    && docker-php-ext-enable apcu --ini-name 10-docker-php-ext-apcu.ini \
    && docker-php-ext-enable apc --ini-name 20-docker-php-ext-apc.ini

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN curl -sS https://get.symfony.com/cli/installer | bash -s -- "--install-dir=/usr/local/bin"

RUN apt-get update && apt-get install -y \
    openssh-client \
    git \
    gnupg \
    apt-transport-https

RUN curl -sL https://deb.nodesource.com/setup_14.x | bash
RUN apt-get install -y nodejs
RUN mkdir /.npm && chown -R 1000:1000 "/.npm"

WORKDIR /var/www/xtools

CMD ["php-fpm"]
