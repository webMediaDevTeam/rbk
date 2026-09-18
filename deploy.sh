#!/bin/bash
set -e

echo "🚀 Starting deployment build..."

# ---- 1. Build frontend inside Docker ----
echo "📦 Building frontend with Docker..."
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$(pwd)/frontend:/app" \
  -w /app \
  node:22-alpine \
  sh -c "npm install && npm run build -- --outDir /app/dist-deploy --emptyOutDir"

# ---- 2. Copy frontend build into Laravel public ----
echo "📋 Copying frontend assets into backend/public..."
rm -rf backend/public/build
cp -r frontend/dist-deploy/build backend/public/build
cp frontend/dist-deploy/index.html backend/public/index.html
cp frontend/dist-deploy/favicon.svg backend/public/favicon.svg 2>/dev/null || true
rm -rf frontend/dist-deploy

# ---- 3. Install Laravel dependencies ----
echo "📦 Installing backend dependencies with Docker..."
docker run --rm \
  -v "$(pwd)/backend:/app" \
  -w /app \
  composer:2 \
  sh -c "composer install --no-dev --optimize-autoloader --no-interaction"

# ---- 4. Run migrations ----
echo "🗄️  Running migrations..."
docker run --rm \
  -v "$(pwd)/backend:/app" \
  -w /app \
  --env-file backend/.env \
  php:8.3-cli \
  sh -c "
    apt-get update -qq && apt-get install -qq -y unzip > /dev/null 2>&1 &&
    php artisan migrate --force
  "

echo ""
echo "✅ Deployment build complete!"
echo "   → frontend/dist-deploy/build → backend/public/build/"
echo "   → backend/ is ready to deploy"
