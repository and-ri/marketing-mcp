FROM ghcr.io/and-ri/marketing-mcp-base:php8.2

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY src ./src
COPY server.php users.php app.php auth.php ./

RUN mkdir -p /app/data && chmod 777 /app/data

EXPOSE 8080

CMD ["php", "server.php"]
