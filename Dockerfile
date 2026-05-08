FROM php:8.2-cli

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        git \
        unzip \
        zlib1g-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# gRPC extension — required by Google Ads PHP library
RUN pecl install grpc && docker-php-ext-enable grpc

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (layer cache)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Application source
COPY src ./src
COPY server.php users.php app.php auth.php ./

# Persistent data directory (SQLite DB lives here)
RUN mkdir -p /app/data && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "server.php"]
