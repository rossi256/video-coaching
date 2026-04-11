#!/bin/bash
# Deploy WingCoach to STAGING (ari.tricktionary.com)
# Usage: bash deploy-staging.sh [website|backend|all|init]
set -e

SERVER="ari-server"
WEB_ROOT="/home/ari/public_html/projects/video-coaching"
DB_NAME="video-coaching"
DB_USER="ari"
URL="https://ari.tricktionary.com/projects/video-coaching/"

MODE="${1:-all}"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== WingCoach Staging Deploy ($MODE) ==="
echo "Server: $SERVER → $WEB_ROOT"
echo ""

deploy_website() {
  echo "[1/2] Deploying website (PHP wrappers + static assets)..."
  rsync -avz --delete \
    --exclude='.git/' --exclude='.DS_Store' \
    --exclude='api/' --exclude='vendor/' --exclude='uploads/' --exclude='composer.json' --exclude='composer.lock' \
    "$PROJECT_DIR/website/" "$SERVER:$WEB_ROOT/"
  # Set staging base path via .htaccess env var
  ssh "$SERVER" "echo 'SetEnv WINGCOACH_BASE_PATH /projects/video-coaching' > $WEB_ROOT/.htaccess"
  echo "  Website deployed."
}

deploy_backend() {
  echo "[2/2] Deploying PHP backend (api/ + vendor/)..."
  rsync -avz --delete \
    --exclude='.DS_Store' --exclude='config.staging.php' \
    "$PROJECT_DIR/backend-php/api/" "$SERVER:$WEB_ROOT/api/"
  # Use staging config
  echo "  Swapping config for staging..."
  scp "$PROJECT_DIR/backend-php/api/config.staging.php" "$SERVER:$WEB_ROOT/api/config.php"
  # Deploy composer.json and install deps on server
  scp "$PROJECT_DIR/backend-php/composer.json" "$SERVER:$WEB_ROOT/composer.json"
  echo "  Running composer install on server..."
  ssh "$SERVER" "cd $WEB_ROOT && composer install --no-dev --no-interaction 2>&1"
  echo "  PHP backend deployed."
}

case "$MODE" in
  website)  deploy_website ;;
  backend)  deploy_backend ;;
  all)
    deploy_website
    deploy_backend
    ;;
  init)
    echo "Running first-time setup..."
    ssh "$SERVER" "mkdir -p $WEB_ROOT/uploads && chmod 755 $WEB_ROOT/uploads"
    echo "  Upload directory created."
    deploy_website
    deploy_backend
    echo ""
    echo "Apply schema manually:"
    echo "  ssh $SERVER \"mysql -u $DB_USER -p $DB_NAME < $WEB_ROOT/api/schema.sql\""
    echo ""
    echo "Set up abandoned checkout cron:"
    echo "  ssh $SERVER"
    echo "  crontab -e"
    echo "  */5 * * * * php $WEB_ROOT/api/cron/abandoned-checkout.php >> /home/ari/logs/wingcoach-cron.log 2>&1"
    ;;
  *)
    echo "Usage: bash deploy-staging.sh [website|backend|all|init]"
    exit 1
    ;;
esac

echo ""
echo "=== Staging deploy complete ==="
echo "Website: $URL"
echo "Admin:   ${URL}admin"
