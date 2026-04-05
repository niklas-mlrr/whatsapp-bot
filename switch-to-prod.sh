#!/bin/bash
# Switch from DEV to PROD environment
# Stops all DEV services and starts PROD services

set -e

echo "=== Switching to PROD environment ==="

# Stop DEV services
echo "Stopping DEV services..."

# Stop all user PM2 processes
pm2 delete all 2>/dev/null || echo "No DEV PM2 processes running"

# Kill any remaining dev frontend process
pkill -f "vite.*5173" 2>/dev/null || true

echo "DEV services stopped."

# Start PROD services
echo "Starting PROD services..."

# Stop all root PM2 processes first to avoid duplicates
sudo pm2 delete all 2>/dev/null || true

# Start systemd reverb service
sudo systemctl start reverb

# Start PM2 processes as root
sudo pm2 start /var/www/html/whatsapp-bot/receiver/index.js --name node-server --cwd /var/www/html/whatsapp-bot/receiver
sudo pm2 start "php /var/www/html/whatsapp-bot/backend/artisan queue:work --queue=high,default,low --sleep=3 --tries=3 --max-time=3600 --timeout=120" --name laravel-queue
sudo pm2 start "php artisan serve --host=127.0.0.1 --port=8000" --name laravel-api --cwd /var/www/html/whatsapp-bot/backend

# Save PM2 state for root
sudo pm2 save

echo ""
echo "=== PROD environment is now running ==="
sudo pm2 list
echo ""
echo "Reverb status:"
sudo systemctl status reverb --no-pager | head -5