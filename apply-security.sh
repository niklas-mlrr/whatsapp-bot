#!/usr/bin/env bash
# VPS Security Hardening Script
# Run as: sudo bash /home/openclaw/apply-security.sh
set -e

echo "=== [1/6] Reloading PHP-FPM (picks up APP_DEBUG=false) ==="
php artisan config:clear --working-directory=/var/www/html/whatsapp-bot/backend 2>/dev/null || true
systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php8.4-fpm 2>/dev/null || systemctl restart php8.2-fpm 2>/dev/null || systemctl restart php8.4-fpm 2>/dev/null
echo "  Done."

echo ""
echo "=== [2/6] Binding phpMyAdmin to localhost only ==="
sed -i 's/^\s*listen 8082;/    listen 127.0.0.1:8082;/g' /etc/nginx/sites-available/phpmyadmin
sed -i 's/^\s*listen 8082;/    listen 127.0.0.1:8082;/g' /etc/nginx/sites-available/phpmyadmin-dev
echo "  Done."

echo ""
echo "=== [3/6] Adding security headers to WhatsApp Nginx config ==="
# Insert headers after the ssl_dhparam line (end of SSL block)
NGINX_CONF=/etc/nginx/sites-available/whatsapp-bot
if ! grep -q 'Strict-Transport-Security' "$NGINX_CONF"; then
    sed -i '/ssl_dhparam.*ssl-dhparams.pem.*$/a\\n    # Security headers\n    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;\n    add_header X-Frame-Options "SAMEORIGIN" always;\n    add_header X-Content-Type-Options "nosniff" always;' "$NGINX_CONF"
    echo "  Headers added."
else
    echo "  Headers already present, skipping."
fi

echo ""
echo "=== [4/6] Disabling X11Forwarding in SSH ==="
sed -i 's/^X11Forwarding yes/X11Forwarding no/' /etc/ssh/sshd_config
systemctl reload ssh
echo "  Done."

echo ""
echo "=== [5/6] Binding Ollama to localhost ==="
OLLAMA_OVERRIDE_DIR=/etc/systemd/system/ollama.service.d
mkdir -p "$OLLAMA_OVERRIDE_DIR"
cat > "$OLLAMA_OVERRIDE_DIR/localhost.conf" <<'EOF'
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
EOF
systemctl daemon-reload
systemctl restart ollama 2>/dev/null || echo "  Ollama service not found via systemd — set OLLAMA_HOST=127.0.0.1 in /etc/environment manually if Ollama is managed differently."
echo "  Done."

echo ""
echo "=== [6/6] Setting up UFW firewall ==="
apt-get install -y ufw -q 2>/dev/null || true
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable
echo "  Done."

echo ""
echo "=== Reloading Nginx ==="
nginx -t && systemctl reload nginx
echo "  Done."

echo ""
echo "=== Security hardening complete ==="
echo "Verify:"
echo "  ss -tlnp | grep -E '8082|11434|4820'"
echo "  ufw status"
echo "  curl -s https://lukas-whatsapp.cloud/api/health || curl -sI https://lukas-whatsapp.cloud | head -5"
