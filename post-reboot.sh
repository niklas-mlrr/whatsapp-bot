#!/bin/bash
set -euo pipefail

export HOME=/root
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Ensure services are up after reboot
systemctl restart nginx

# Restore PM2 process dump if available
pm2 resurrect || true

# Safety net: start PROD services if not running
# Delete any stale processes first to avoid duplicates
pm2 delete all 2>/dev/null || true

# Start fresh PROD processes
pm2 start /var/www/html/whatsapp-bot/receiver/index.js --name node-server --cwd /var/www/html/whatsapp-bot/receiver
pm2 start "php /var/www/html/whatsapp-bot/backend/artisan queue:work --queue=high,default,low --sleep=3 --tries=3 --max-time=3600 --timeout=120" --name laravel-queue
pm2 start "php artisan serve --host=127.0.0.1 --port=8000" --name laravel-api --cwd /var/www/html/whatsapp-bot/backend

# Refresh queue workers for new code/config
cd /var/www/html/whatsapp-bot/backend
php artisan queue:restart || true

# Persist current PM2 state for next reboot
pm2 save