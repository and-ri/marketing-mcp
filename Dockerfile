# ── base: runtime with the PHP extensions the app needs ───────────────────────
FROM php:8.5-cli AS base

# unzip lets composer extract package archives (pdo_sqlite and curl ship with the official image).
# Google clients use the REST transport, so the slow-to-compile grpc extension is not needed.
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# bcmath: the pure-PHP protobuf runtime needs it to decode int64 fields (impressions, cost_micros…)
# from Google REST responses. It is bundled with PHP, so this takes seconds.
RUN docker-php-ext-install bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── test: run the suite inside the real runtime ──────────────────────────────
#   docker build --target test .
FROM base AS test

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

COPY . .
RUN vendor/bin/phpunit --colors=never

# ── app: production image (default target) ──────────────────────────────────
FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY src ./src
COPY server.php users.php app.php auth.php ./

RUN mkdir -p /app/data && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "server.php"]
