#!/usr/bin/env bash
set -euo pipefail

# Deploy WingCoach Node.js admin backend to ari-server
# Target: /home/ari/wingcoach-admin/
# Proxied by Apache: /projects/video-coaching/ → localhost:3010

SERVER="ari-server"
REMOTE_DIR="/home/ari/wingcoach-admin"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== Deploying WingCoach Node.js backend ==="
echo "    Local:  $LOCAL_DIR"
echo "    Remote: $SERVER:$REMOTE_DIR"
echo ""

# 1. Create remote directory + logs dir if needed
echo "--- Creating remote directories ---"
ssh "$SERVER" "mkdir -p $REMOTE_DIR/uploads $REMOTE_DIR/routes $REMOTE_DIR/public /home/ari/logs"

# 2. Rsync Node.js files (explicit list — no PHP, no node_modules, no uploads)
echo "--- Syncing Node.js files ---"
rsync -avz --delete \
  "$LOCAL_DIR/server.js" \
  "$LOCAL_DIR/db.js" \
  "$LOCAL_DIR/email.js" \
  "$LOCAL_DIR/stripe.js" \
  "$LOCAL_DIR/package.json" \
  "$LOCAL_DIR/package-lock.json" \
  "$LOCAL_DIR/ecosystem.config.js" \
  "$LOCAL_DIR/.env" \
  "$SERVER:$REMOTE_DIR/"

# Sync routes/ and public/ directories (with --delete to remove stale files)
rsync -avz --delete "$LOCAL_DIR/routes/" "$SERVER:$REMOTE_DIR/routes/"
rsync -avz --delete "$LOCAL_DIR/public/" "$SERVER:$REMOTE_DIR/public/"

# 3. Copy coaching.db ONLY if server doesn't already have one
echo "--- Checking coaching.db ---"
if ssh "$SERVER" "[ ! -f $REMOTE_DIR/coaching.db ]"; then
  if [ -f "$LOCAL_DIR/coaching.db" ]; then
    echo "    No DB on server — copying local coaching.db"
    rsync -avz "$LOCAL_DIR/coaching.db" "$SERVER:$REMOTE_DIR/"
  else
    echo "    No local coaching.db either — app will create one on first run"
  fi
else
  echo "    coaching.db already exists on server — skipping"
fi

# 4. Install production dependencies on server
echo "--- Installing dependencies ---"
ssh "$SERVER" "cd $REMOTE_DIR && npm install --production"

# 5. Start or restart with PM2
echo "--- Starting/restarting PM2 ---"
ssh "$SERVER" "cd $REMOTE_DIR && pm2 delete wingcoach 2>/dev/null || true && pm2 start ecosystem.config.js && pm2 save"

# 6. Verify
echo ""
echo "--- PM2 status ---"
ssh "$SERVER" "pm2 list"

echo ""
echo "=== Deploy complete ==="
echo ""
echo "App should be accessible at:"
echo "  https://ari.tricktionary.com/projects/video-coaching/admin"
echo ""
echo "=== Apache proxy config ==="
echo "If not already configured, add this to BOTH VirtualHost blocks"
echo "(:80 and :443) in /etc/apache2/sites-available/ari.tricktionary.com.conf:"
echo ""
echo "    # WingCoach Node.js app"
echo "    ProxyPass /projects/video-coaching/ http://localhost:3010/"
echo "    ProxyPassReverse /projects/video-coaching/ http://localhost:3010/"
echo ""
echo "Then: sudo systemctl reload apache2"
echo ""
echo "To add it manually:"
echo "  ssh ari-server"
echo "  sudo nano /etc/apache2/sites-available/ari.tricktionary.com.conf"
echo "  # Paste the ProxyPass lines above into both VirtualHost blocks"
echo "  sudo systemctl reload apache2"
