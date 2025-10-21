# Setup PHP-FPM Properly for Laravel Backend

## Current Issue
Your Laravel backend is running via `php artisan serve` (development server) on port 8000, which uses CLI PHP without GD extension. We need to configure it to use PHP-FPM 8.2 properly.

## Step 1: Stop the Development Server

```bash
# Kill the current php artisan serve process
sudo kill 32821

# Or find and kill it
sudo pkill -f "php artisan serve"

# Verify it's stopped
sudo netstat -tulpn | grep :8000
```

## Step 2: Update Nginx Configuration

Replace the proxy setup with proper PHP-FPM configuration:

```bash
# Backup current config
sudo cp /etc/nginx/sites-available/whatsapp-bot /etc/nginx/sites-available/whatsapp-bot.backup

# Edit the config
sudo nano /etc/nginx/sites-available/whatsapp-bot
```

Replace the `/api/` location block with this:

```nginx
location /api/ {
    alias /var/www/html/whatsapp-bot/backend/public/;
    try_files $uri $uri/ @laravel;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }
}

location @laravel {
    rewrite /api/(.*)$ /api/index.php?/$1 last;
}

location ~ ^/api/index\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/html/whatsapp-bot/backend/public/index.php;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    
    # CORS headers
    add_header 'Access-Control-Allow-Origin' 'https://lukas-whatsapp.cloud' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
    add_header 'Access-Control-Allow-Credentials' 'true' always;
}
```

## Step 3: Test Nginx Configuration

```bash
# Test the configuration
sudo nginx -t

# If successful, reload Nginx
sudo systemctl reload nginx
```

## Step 4: Verify PHP-FPM is Working

```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Test the endpoint
curl https://lukas-whatsapp.cloud/api/test-gd
```

You should now see:
- `"gd_loaded": true`
- `"php_sapi": "fpm-fcgi"` (not "cli-server")

## Alternative: Keep Using Development Server but Enable GD in CLI

If you prefer to keep using `php artisan serve` (not recommended for production):

```bash
# Enable GD in CLI PHP
echo "extension=gd" | sudo tee /etc/php/8.2/cli/conf.d/20-gd.ini

# Restart the development server
sudo kill 32821
cd /var/www/html/whatsapp-bot/backend
php artisan serve --host=127.0.0.1 --port=8000 &
```

## Recommended: Create a Systemd Service for Laravel

Instead of manually running `php artisan serve`, create a proper service:

```bash
sudo nano /etc/systemd/system/laravel-backend.service
```

Add this content:

```ini
[Unit]
Description=Laravel Backend Application
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/whatsapp-bot/backend
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Then:

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable and start the service
sudo systemctl enable laravel-backend
sudo systemctl start laravel-backend

# Check status
sudo systemctl status laravel-backend
```

## Which Approach to Use?

**For Production (Recommended):**
- Use PHP-FPM directly (Step 2-4)
- Better performance, proper process management
- GD already works in PHP-FPM

**For Development/Quick Fix:**
- Keep using `php artisan serve` but enable GD in CLI
- Easier but less performant
- Not recommended for production

Choose your approach and let me know which one you'd like to implement!
