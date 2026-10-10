#!/bin/bash
# Switch from PROD to DEV environment
# Stops all PROD services and starts DEV services

set -e

echo "=== Switching to DEV environment ==="

# Stop PROD services
echo "Stopping PROD services..."

# Stop systemd reverb service
sudo systemctl stop reverb 2>/dev/null || echo "reverb.service not running"

# Stop all PM2 processes running as root (PROD)
sudo pm2 delete all 2>/dev/null || echo "No PM2 processes as root"

# Kill any remaining PROD processes
sudo pkill -f "whatsapp-bot/receiver" 2>/dev/null || true
sudo pkill -f "whatsapp-bot/queue:work" 2>/dev/null || true
sudo pkill -f "whatsapp-bot/backend/artisan serve" 2>/dev/null || true

echo "PROD services stopped."

# Start DEV services
echo "Starting DEV services..."

cd /home/openclaw/projects/whatsapp_dev

# Stop existing DEV PM2 processes by name to avoid duplicates.
# Not `pm2 delete all`: that would also kill unrelated user processes (agent-monitor dashboard).
for name in dev-backend dev-receiver dev-queue dev-frontend dev-reverb; do
    pm2 delete "$name" >/dev/null 2>&1 || true
done

# Start PM2 services (backend, receiver, queue, reverb)
pm2 start ecosystem.config.cjs

# Start frontend in background
pm2 start "npm run dev -- --host 0.0.0.0 --port 5173" --name dev-frontend --cwd /home/openclaw/projects/whatsapp_dev/frontend/vue-project

echo ""
echo "=== DEV environment is now running ==="
pm2 list