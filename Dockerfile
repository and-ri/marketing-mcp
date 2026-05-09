FROM php:8.2-cli

# php-extension-installer provides pre-compiled binaries (avoids PECL source compilation)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions — pre-built binaries, works fast on amd64 and arm64
RUN install-php-extensions pdo_sqlite grpc

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
