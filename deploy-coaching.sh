#!/bin/bash
# Deploy WingCoach to PRODUCTION (coaching.tricktionary.com)
# Usage: bash deploy-coaching.sh [website|backend|hub|private|all|init]
set -e

SERVER="coaching-server"
WEB_ROOT="/home/coaching/public_html"
DB_NAME="coaching"
DB_USER="coaching"
URL="https://coaching.tricktionary.com"

MODE="${1:-all}"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== WingCoach Production Deploy ($MODE) ==="
echo "Server: $SERVER → $WEB_ROOT"
echo ""

deploy_hub() {
  echo "[hub] Deploying coaching hub landing page..."
  scp "$PROJECT_DIR/coaching-hub/index.html" "$SERVER:$WEB_ROOT/index.html"
  echo "  Landing page deployed."
}

deploy_assets() {
  echo "[assets] Deploying shared images..."
  ssh "$SERVER" "mkdir -p $WEB_ROOT/assets"
  # Hero images
  rsync -avz \
    --exclude='.DS_Store' \
    "$PROJECT_DIR/public/assets/hero1-bg-michi riding toeside onehanded from left to right towards camera looks into cam.jpg" \
    "$SERVER:$WEB_ROOT/assets/"
  rsync -avz \
    "$PROJECT_DIR/public/assets/hero3-bg michi mid-railey in intersection upper third right third of image -rest free.jpg" \
    "$SERVER:$WEB_ROOT/assets/"
  # Coach portrait
  scp "$PROJECT_DIR/public/assets/coch portrait/TRCK3186.jpg" "$SERVER:$WEB_ROOT/assets/coach-portrait.jpg"
  # Coach action photos (for V2 coaching hub sections)
  scp "$PROJECT_DIR/public/assets/coach action/TRCK7644.jpg" "$SERVER:$WEB_ROOT/assets/coach-action-TRCK7644.jpg"
  scp "$PROJECT_DIR/public/assets/coach action/20250805_4326-1.jpg" "$SERVER:$WEB_ROOT/assets/coach-action-20250805_4326-1.jpg"
  scp "$PROJECT_DIR/public/assets/coach action/TRCK7317.jpg" "$SERVER:$WEB_ROOT/assets/coach-action-TRCK7317.jpg"
  echo "  Assets deployed."
}

deploy_website() {
  echo "[website] Deploying WingCoach app (PHP wrappers + static)..."
  ssh "$SERVER" "mkdir -p $WEB_ROOT/video-coaching"
  rsync -avz --delete \
    --exclude='.git/' --exclude='.DS_Store' \
    --exclude='api/' --exclude='vendor/' --exclude='uploads/' --exclude='composer.json' --exclude='composer.lock' \
    "$PROJECT_DIR/website/" "$SERVER:$WEB_ROOT/video-coaching/"
  echo "  Website deployed."
}

deploy_private() {
  echo "[private] Deploying VIP coaching landing page..."
  ssh "$SERVER" "mkdir -p $WEB_ROOT/vip-coaching"
  scp "$PROJECT_DIR/vip-coaching/index.html" "$SERVER:$WEB_ROOT/vip-coaching/index.html"
  echo "  VIP coaching page deployed."
}

deploy_backend() {
  echo "[backend] Deploying PHP backend (api/)..."
  rsync -avz --delete \
    --exclude='.DS_Store' --exclude='config.staging.php' --exclude='config.production-coaching.php' \
    "$PROJECT_DIR/backend-php/api/" "$SERVER:$WEB_ROOT/video-coaching/api/"
  # Use production coaching config
  echo "  Swapping config for production..."
  scp "$PROJECT_DIR/backend-php/api/config.production-coaching.php" "$SERVER:$WEB_ROOT/video-coaching/api/config.php"
  # Deploy composer.json and install deps on server
  scp "$PROJECT_DIR/backend-php/composer.json" "$SERVER:$WEB_ROOT/video-coaching/composer.json"
  echo "  Running composer install on server..."
  ssh "$SERVER" "cd $WEB_ROOT/video-coaching && composer install --no-dev --no-interaction 2>&1"
  echo "  PHP backend deployed."
}

case "$MODE" in
  hub)      deploy_hub ;;
  assets)   deploy_assets ;;
  website)  deploy_website ;;
  backend)  deploy_backend ;;
  private)  deploy_private ;;
  all)
    deploy_hub
    deploy_assets
    deploy_website
    deploy_backend
    deploy_private
    ;;
  init)
    echo "Running first-time setup..."
    ssh "$SERVER" "mkdir -p $WEB_ROOT/video-coaching/uploads && chmod 755 $WEB_ROOT/video-coaching/uploads"
    echo "  Upload directory created."
    deploy_hub
    deploy_assets
    deploy_website
    deploy_backend
    echo ""
    echo "=== MANUAL STEPS ==="
    echo "1. Apply schema:"
    echo "   ssh $SERVER \"mysql -u $DB_USER -p $DB_NAME < $WEB_ROOT/video-coaching/api/schema.sql\""
    echo ""
    echo "2. Add Stripe webhook URL in dashboard:"
    echo "   ${URL}/video-coaching/api/webhook.php"
    echo ""
    echo "3. Set up abandoned checkout cron:"
    echo "   ssh $SERVER"
    echo "   crontab -e"
    echo "   */5 * * * * php $WEB_ROOT/video-coaching/api/cron/abandoned-checkout.php >> /home/coaching/logs/wingcoach-cron.log 2>&1"
    ;;
  *)
    echo "Usage: bash deploy-coaching.sh [website|backend|hub|assets|private|all|init]"
    exit 1
    ;;
esac

echo ""
echo "=== Production deploy complete ==="
echo "Hub:     $URL"
echo "App:     ${URL}/video-coaching/"
echo "VIP:     ${URL}/vip-coaching/"
echo "Admin:   ${URL}/video-coaching/admin"
