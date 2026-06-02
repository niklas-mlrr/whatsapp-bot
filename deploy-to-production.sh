#!/bin/bash

# WhatsApp Bot - Production Deployment Script
# Run this script on your production server

set -e  # Exit on error

echo "=========================================="
echo "WhatsApp Bot - Production Deployment"
echo "=========================================="
echo ""

# Configuration
PROJECT_DIR="/var/www/html/whatsapp-bot/backend"
PHP_VERSION="8.2"  # Adjust if needed

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  This script requires sudo privileges for some operations."
    echo "Please run with: sudo bash deploy-to-production.sh"
    exit 1
fi

echo "📁 Navigating to project directory..."
cd "$PROJECT_DIR" || exit 1

echo ""
echo "1️⃣  Enabling Apache modules (if using Apache)..."
if command -v a2enmod &> /dev/null; then
    a2enmod headers || true
    a2enmod rewrite || true
    echo "✅ Apache modules enabled"
else
    echo "ℹ️  Apache not detected, skipping..."
fi

echo ""
echo "2️⃣  Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan optimize:clear
echo "✅ Caches cleared"

echo ""
echo "3️⃣  Setting correct permissions..."
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R 755 "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage"
chmod -R 775 "$PROJECT_DIR/bootstrap/cache"
echo "✅ Permissions set"

echo ""
echo "4️⃣  Restarting PHP-FPM..."
if systemctl is-active --quiet "php${PHP_VERSION}-fpm"; then
    systemctl restart "php${PHP_VERSION}-fpm"
    echo "✅ PHP-FPM restarted"
elif systemctl is-active --quiet php-fpm; then
    systemctl restart php-fpm
    echo "✅ PHP-FPM restarted"
else
    echo "⚠️  PHP-FPM service not found"
fi

echo ""
echo "5️⃣  Restarting web server..."
if systemctl is-active --quiet nginx; then
    systemctl restart nginx
    echo "✅ Nginx restarted"
elif systemctl is-active --quiet apache2; then
    systemctl restart apache2
    echo "✅ Apache restarted"
else
    echo "⚠️  Web server not detected"
fi

echo ""
echo "=========================================="
echo "✅ Deployment completed!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Create admin user: php artisan user:create-admin"
echo "2. Test login endpoint: curl -X POST https://lukas-messenger.de/api/login -H 'Content-Type: application/json' -d '{\"phone\":\"+10000000000\",\"password\":\"admin123\"}'"
echo ""
