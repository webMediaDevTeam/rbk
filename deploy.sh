#!/bin/bash
set -e

echo "🚀 Starting deployment build..."

# ---- 1. Build frontend inside Docker ----
echo "📦 Building frontend with Docker..."
docker run --rm \
  -v "$(pwd)/frontend:/app" \
  -w /app \
  node:22-alpine \
  sh -c "npm install && npm run build"

# ---- 2. Copy frontend build into Laravel public ----
echo "📋 Copying frontend assets into backend/public..."
rm -rf backend/public/build
cp -r frontend/dist/build backend/public/build
cp frontend/dist/index.html backend/public/index.html

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
echo "   → frontend/assets → backend/public/build/"
echo "   → backend/ is ready to deploy"
