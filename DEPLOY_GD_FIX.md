# Deploy GD Fix to Server

## What Changed
- Moved `/test-gd` endpoint outside the dev-only block so it's accessible in production
- Added GD extension configuration for PHP 8.4

## Step 1: Deploy Updated Routes File

On your server, navigate to your backend directory and pull the latest changes:

```bash
cd /var/www/html/whatsapp-bot/backend

# If using git
git pull origin main

# Or manually upload the updated routes/api.php file
```

## Step 2: Clear Laravel Cache

```bash
cd /var/www/html/whatsapp-bot/backend

# Clear route cache
php artisan route:clear

# Clear config cache
php artisan config:clear

# Clear all caches
php artisan cache:clear

# Optimize for production
php artisan optimize
```

## Step 3: Test the GD Endpoint

```bash
curl https://lukas-whatsapp.cloud/api/test-gd
```

Expected output:
```json
{
  "gd_loaded": true,
  "gd_info": {
    "GD Version": "bundled (2.1.0 compatible)",
    ...
  },
  "php_info": {
    "php_version": "8.4.x",
    "php_sapi": "fpm-fcgi",
    "loaded_ini": "/etc/php/8.4/fpm/php.ini",
    "app_env": "production"
  },
  "loaded_extensions": [...],
  "imagecreatefromstring_exists": true,
  "imagesx_exists": true,
  "imagesy_exists": true
}
```

## Step 4: If GD Still Shows False

The GD extension file might not exist for PHP 8.4. Install it:

```bash
# Install GD for PHP 8.4
sudo apt-get update
sudo apt-get install php8.4-gd

# Verify the extension file exists
ls -la /usr/lib/php/*/gd.so

# Restart PHP-FPM
sudo systemctl restart php8.4-fpm

# Test again
curl https://lukas-whatsapp.cloud/api/test-gd
```

## Step 5: Verify Image Processing Works

Send an image to your WhatsApp bot and check the logs:

```bash
tail -f /var/www/html/whatsapp-bot/backend/storage/logs/laravel.log
```

You should see:
- ✅ No "GD extension not loaded" warnings
- ✅ Thumbnail generation messages
- ✅ Image dimensions detected

## Quick One-Liner

```bash
cd /var/www/html/whatsapp-bot/backend && \
git pull && \
php artisan optimize && \
sudo apt-get install -y php8.4-gd && \
sudo systemctl restart php8.4-fpm && \
curl https://lukas-whatsapp.cloud/api/test-gd
```
