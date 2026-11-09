FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        libsqlite3-dev \
        libssl-dev \
        zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_sqlite

# gRPC is compiled from source — slow on the first build, then cached by Docker
RUN pecl install grpc && docker-php-ext-enable grpc

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY src ./src
COPY server.php users.php app.php auth.php ./

RUN mkdir -p /app/data && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "server.php"]
