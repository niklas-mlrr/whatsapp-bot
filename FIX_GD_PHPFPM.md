# Fix GD Extension Not Loading in PHP-FPM (Web Server)

## Problem
GD extension works in CLI (`php -m | grep gd` shows `gd`) but doesn't work in the web server context (PHP-FPM). This is because **CLI PHP and PHP-FPM use different php.ini files**.

## Step 1: Diagnose the Issue

Visit this URL on your server to check GD status in web context:
```
https://your-domain.com/api/test-gd
```

This will show:
- Whether GD is loaded in the web server
- Which php.ini file is being used
- PHP version and SAPI
- All loaded extensions

## Step 2: Find the Correct php.ini File

SSH into your server and run:

```bash
# Find PHP-FPM's php.ini location
php-fpm8.2 -i | grep "Loaded Configuration File"

# Or check which PHP-FPM version is running
sudo systemctl status php*-fpm

# For PHP 8.2, the FPM php.ini is usually at:
# /etc/php/8.2/fpm/php.ini

# For PHP 8.1:
# /etc/php/8.1/fpm/php.ini

# For PHP 8.0:
# /etc/php/8.0/fpm/php.ini
```

## Step 3: Enable GD in PHP-FPM's php.ini

### Option A: Check if GD is commented out

Edit the PHP-FPM php.ini file:
```bash
# For PHP 8.2
sudo nano /etc/php/8.2/fpm/php.ini

# Look for this line (it might be commented with a semicolon):
;extension=gd

# If you find it, uncomment it (remove the semicolon):
extension=gd
```

### Option B: Add GD extension manually

If the line doesn't exist, add it to the php.ini file:
```bash
# For PHP 8.2
sudo nano /etc/php/8.2/fpm/php.ini

# Add this line in the "Dynamic Extensions" section:
extension=gd
```

### Option C: Use the conf.d directory (Recommended)

Most modern PHP installations use a `conf.d` directory for extensions:

```bash
# For PHP 8.2
sudo nano /etc/php/8.2/fpm/conf.d/20-gd.ini

# Add this single line:
extension=gd

# Save and exit
```

## Step 4: Verify GD Module File Exists

Check if the GD shared object file exists:
```bash
# For PHP 8.2
ls -la /usr/lib/php/20220829/gd.so

# For PHP 8.1
ls -la /usr/lib/php/20210902/gd.so

# If the file doesn't exist, reinstall php-gd:
sudo apt-get install --reinstall php8.2-gd
```

## Step 5: Restart PHP-FPM

After making changes, restart PHP-FPM:
```bash
# For PHP 8.2
sudo systemctl restart php8.2-fpm

# For PHP 8.1
sudo systemctl restart php8.1-fpm

# Verify it restarted successfully
sudo systemctl status php8.2-fpm
```

## Step 6: Restart Web Server

Also restart your web server:
```bash
# For Nginx
sudo systemctl restart nginx

# For Apache
sudo systemctl restart apache2
```

## Step 7: Verify the Fix

1. **Via API endpoint:**
   ```bash
   curl https://your-domain.com/api/test-gd
   ```
   
   Should show:
   ```json
   {
     "gd_loaded": true,
     "gd_info": { ... }
   }
   ```

2. **Via logs:**
   Send an image to your WhatsApp bot and check the logs:
   ```bash
   tail -f /path/to/backend/storage/logs/laravel.log
   ```
   
   You should NO LONGER see:
   - `GD extension not loaded, skipping thumbnail generation`
   - `GD extension not loaded, cannot get image dimensions`

## Common Issues & Solutions

### Issue 1: Multiple PHP Versions Installed

If you have multiple PHP versions, make sure you're editing the correct one:

```bash
# Check which PHP version your web server uses
sudo systemctl status php*-fpm

# You might see multiple services:
# php7.4-fpm
# php8.1-fpm
# php8.2-fpm

# Find which one is active and running
sudo systemctl list-units | grep php-fpm
```

### Issue 2: GD Still Not Loading After Restart

```bash
# Check PHP-FPM error logs
sudo tail -f /var/log/php8.2-fpm.log

# Check if there are any errors loading the extension
sudo journalctl -u php8.2-fpm -n 50

# Verify the extension file exists and has correct permissions
ls -la /usr/lib/php/*/gd.so
```

### Issue 3: Permission Issues

```bash
# Ensure PHP-FPM can read the extension file
sudo chmod 644 /usr/lib/php/*/gd.so

# Ensure the conf.d file has correct permissions
sudo chmod 644 /etc/php/8.2/fpm/conf.d/20-gd.ini
```

### Issue 4: SELinux Blocking (CentOS/RHEL)

If you're on CentOS/RHEL with SELinux enabled:
```bash
# Check SELinux status
sestatus

# If enforcing, temporarily set to permissive for testing
sudo setenforce 0

# If this fixes it, you need to configure SELinux properly
sudo setsebool -P httpd_execmem 1
```

## Quick Fix Script

Run this script on your server (adjust PHP version as needed):

```bash
#!/bin/bash

# Set your PHP version
PHP_VERSION="8.2"

echo "Fixing GD extension for PHP $PHP_VERSION..."

# Reinstall GD
sudo apt-get install --reinstall php${PHP_VERSION}-gd -y

# Create conf.d entry
echo "extension=gd" | sudo tee /etc/php/${PHP_VERSION}/fpm/conf.d/20-gd.ini

# Restart services
sudo systemctl restart php${PHP_VERSION}-fpm
sudo systemctl restart nginx

# Verify
echo "Checking if GD is loaded..."
php -m | grep gd

echo "Done! Now test via: curl https://your-domain.com/api/test-gd"
```

Save as `fix-gd.sh`, make executable, and run:
```bash
chmod +x fix-gd.sh
sudo ./fix-gd.sh
```

## Expected Result

After following these steps:
- ✅ `/api/test-gd` endpoint shows `"gd_loaded": true`
- ✅ Images receive thumbnails automatically
- ✅ Image dimensions are detected
- ✅ No GD warnings in logs

## Still Not Working?

If GD still doesn't load after all these steps:

1. **Check PHP-FPM pool configuration:**
   ```bash
   sudo nano /etc/php/8.2/fpm/pool.d/www.conf
   
   # Look for any php_admin_value or php_value directives
   # that might be disabling extensions
   ```

2. **Create a phpinfo page:**
   ```bash
   # In your Laravel public directory
   echo "<?php phpinfo(); ?>" | sudo tee /var/www/html/info.php
   
   # Visit: https://your-domain.com/info.php
   # Search for "gd" on the page
   # Delete the file after checking for security
   ```

3. **Check if there's a custom PHP build:**
   ```bash
   php -v
   # If you see anything unusual, you might have a custom PHP build
   # that needs GD compiled differently
   ```
