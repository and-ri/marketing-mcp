#!/usr/bin/env bash
# First-time deployment script.
# Usage: ./deploy.sh mcp.example.com you@example.com
set -euo pipefail

DOMAIN="${1:?Usage: ./deploy.sh <domain> <email>}"
EMAIL="${2:?Usage: ./deploy.sh <domain> <email>}"

echo "==> Replacing YOUR_DOMAIN with $DOMAIN in nginx/mcp.conf"
sed -i "s/YOUR_DOMAIN/$DOMAIN/g" nginx/mcp.conf

echo "==> Starting nginx on HTTP-only (init.conf) to pass ACME challenge"
# Rename configs so only init.conf is active
mv nginx/mcp.conf nginx/mcp.conf.disabled
docker compose up -d nginx

echo "==> Waiting for nginx to be ready..."
sleep 3

echo "==> Requesting Let's Encrypt certificate for $DOMAIN"
docker compose run --rm certbot certonly \
    --webroot -w /var/www/certbot \
    -d "$DOMAIN" \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email

echo "==> Activating HTTPS config"
mv nginx/mcp.conf.disabled nginx/mcp.conf

echo "==> Starting all services"
docker compose up -d
docker compose exec nginx nginx -s reload

echo ""
echo "==> Done. Server is running at https://$DOMAIN"
echo "==> Create your first user:"
echo "    docker compose exec mcp php users.php add <name>"
