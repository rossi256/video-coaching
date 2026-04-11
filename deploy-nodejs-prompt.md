## Task: Deploy Node.js WingCoach Backend to ari-server

**Context:**
The video-coaching project has two parts:
1. A PHP frontend (already deployed at ari-server:/home/ari/public_html/projects/video-coaching/)
2. A Node.js/Express backend (server.js, routes/, db.js, public/, etc.) — LOCAL ONLY, never deployed

The Node.js backend is an admin tool running on a separate port (3010), proxied by Apache.
The ecosystem.config.js shows the target: /home/ari/public_html/projects/video-coaching with PM2 name "wingcoach".

**BUT** — before deploying blindly, check the apache-proxy-snippet.conf to understand how the proxy is set up, and README.md for any deploy notes.

**What to do:**

1. Read apache-proxy-snippet.conf and README.md to understand the intended server setup.

2. Create a deploy script `deploy-nodejs.sh` that:
   - rsyncs the Node.js files to ari-server at the correct path (check ecosystem.config.js — path is /home/ari/public_html/projects/video-coaching, but DON'T overwrite the PHP files in that same dir)
   - The Node app files should go to a SEPARATE path: /home/ari/wingcoach-admin/ (or similar — check README for intent)
   - Run `npm install --production` on server after sync
   - Start/restart via PM2 using ecosystem.config.js
   - Verify it's running with `pm2 list`

3. If the apache-proxy-snippet.conf shows a ProxyPass for port 3010, also output the snippet and instructions to add it to Apache config (ssh ari-server, then edit /etc/apache2/sites-available/ — show the command but don't execute it, just print it)

4. Run the deploy script.

**SSH alias:** `ari-server` (already configured in ~/.ssh/config)

**Node.js files to deploy (NOT the PHP/website parts):**
- server.js
- db.js
- email.js
- stripe.js
- routes/ (entire folder)
- public/ (entire folder — this is the admin UI)
- package.json
- package-lock.json
- ecosystem.config.js
- .env (if exists — check first)
- coaching.db (SQLite — only if server doesn't already have one)

**Do NOT deploy:**
- backend-php/ (already deployed separately)
- website/ (PHP frontend, already deployed)
- coaching-hub/ (already deployed)
- private-coaching/ (already deployed)
- node_modules/ (install fresh on server)
- uploads/ (don't overwrite server uploads)

**After deploy:** Confirm the admin is accessible at https://ari.tricktionary.com/projects/video-coaching/admin (or whatever path the proxy sets up).
