# Production PHP-FPM Setup for Laravel Backend

## Overview
This guide configures your Laravel backend to use PHP-FPM 8.2 instead of the development server, providing better performance, security, and reliability.

## Step 1: Stop the Development Server

```bash
# Kill the current php artisan serve process
sudo kill 32821

# Verify it's stopped
sudo netstat -tulpn | grep :8000
# Should return nothing
```

## Step 2: Configure Nginx for PHP-FPM

### Backup Current Configuration

```bash
sudo cp /etc/nginx/sites-available/whatsapp-bot /etc/nginx/sites-available/whatsapp-bot.backup.$(date +%Y%m%d)
```

### Create New Configuration

```bash
sudo nano /etc/nginx/sites-available/whatsapp-bot
```

Replace the entire file with this secure configuration:

```nginx
server {
    server_name lukas-whatsapp.cloud www.lukas-whatsapp.cloud;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Frontend - Vue.js
    root /var/www/html/whatsapp-bot/frontend/vue-project/dist;
    index index.html;

    # ACME Challenge for Certbot
    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        root /var/www/html/whatsapp-bot;
    }

    # Vue Frontend - SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Laravel Backend API
    location /api {
        alias /var/www/html/whatsapp-bot/backend/public;
        
        # Handle OPTIONS requests for CORS
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' 'https://lukas-whatsapp.cloud' always;
            add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
            add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
            add_header 'Access-Control-Allow-Credentials' 'true' always;
            add_header 'Access-Control-Max-Age' '3600' always;
            add_header 'Content-Length' '0';
            add_header 'Content-Type' 'text/plain';
            return 204;
        }

        # CORS headers for actual requests
        add_header 'Access-Control-Allow-Origin' 'https://lukas-whatsapp.cloud' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;

        # Try to serve file directly, fallback to index.php
        try_files $uri $uri/ @api;

        # PHP files
        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $request_filename;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
            fastcgi_read_timeout 300;
            fastcgi_send_timeout 300;
        }
    }

    # Rewrite all API requests to index.php
    location @api {
        rewrite ^/api/(.*)$ /api/index.php?/$1 last;
    }

    # Handle index.php for API
    location = /api/index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/html/whatsapp-bot/backend/public/index.php;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param PHP_VALUE "upload_max_filesize=100M \n post_max_size=100M";
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
        
        # CORS headers
        add_header 'Access-Control-Allow-Origin' 'https://lukas-whatsapp.cloud' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
    }

    # Broadcasting endpoint
    location /broadcasting {
        alias /var/www/html/whatsapp-bot/backend/public;
        try_files $uri $uri/ @broadcasting;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }
    }

    location @broadcasting {
        rewrite ^/broadcasting/(.*)$ /broadcasting/index.php?/$1 last;
    }

    # WebSocket for Soketi
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port 443;
        proxy_set_header Origin $scheme://$host;
        proxy_read_timeout 300;
        proxy_send_timeout 300;
        proxy_cache_bypass $http_upgrade;
    }

    # Node.js WhatsApp receiver
    location /ws/ {
        proxy_pass http://127.0.0.1:3000/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # phpMyAdmin
    location /phpmyadmin {
        root /usr/share/;
        index index.php index.html index.htm;
        auth_basic "Restricted Area";
        auth_basic_user_file /etc/nginx/.phpmyadmin_passwd;

        location ~ ^/phpmyadmin/(.+\.php)$ {
            root /usr/share/;
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }

        location ~* ^/phpmyadmin/(.+\.(jpg|jpeg|gif|css|png|js|ico|html|xml|txt))$ {
            root /usr/share/;
        }
    }

    # Deny access to sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~ /\.git {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }

    # SSL Configuration
    listen 443 ssl http2;
    ssl_certificate /etc/letsencrypt/live/lukas-whatsapp.cloud/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/lukas-whatsapp.cloud/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    # Enable OCSP stapling
    ssl_stapling on;
    ssl_stapling_verify on;
    ssl_trusted_certificate /etc/letsencrypt/live/lukas-whatsapp.cloud/chain.pem;
    resolver 8.8.8.8 8.8.4.4 valid=300s;
    resolver_timeout 5s;
}

# HTTP to HTTPS redirect
server {
    listen 80;
    server_name lukas-whatsapp.cloud www.lukas-whatsapp.cloud;
    return 301 https://$host$request_uri;
}
```

## Step 3: Optimize PHP-FPM Configuration

### Configure PHP-FPM Pool

```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

Find and update these settings for better performance:

```ini
; Process manager settings
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

; Performance tuning
request_terminate_timeout = 300
request_slowlog_timeout = 10s
slowlog = /var/log/php8.2-fpm-slow.log

; Security
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_flag[allow_url_fopen] = off
```

### Update PHP-FPM php.ini for Production

```bash
sudo nano /etc/php/8.2/fpm/php.ini
```

Key settings to verify/update:

```ini
; Memory and execution
memory_limit = 256M
max_execution_time = 300
max_input_time = 300

; File uploads
upload_max_filesize = 100M
post_max_size = 100M

; Error handling (production)
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php8.2-fpm-errors.log

; Security
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off

; Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1

; OPcache (already enabled, verify settings)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
```

## Step 4: Set Proper Permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/html/whatsapp-bot/backend/storage
sudo chown -R www-data:www-data /var/www/html/whatsapp-bot/backend/bootstrap/cache

# Set permissions
sudo chmod -R 775 /var/www/html/whatsapp-bot/backend/storage
sudo chmod -R 775 /var/www/html/whatsapp-bot/backend/bootstrap/cache

# Secure sensitive files
sudo chmod 600 /var/www/html/whatsapp-bot/backend/.env
```

## Step 5: Test and Apply Configuration

```bash
# Test Nginx configuration
sudo nginx -t

# If successful, reload Nginx
sudo systemctl reload nginx

# Restart PHP-FPM with new settings
sudo systemctl restart php8.2-fpm

# Verify services are running
sudo systemctl status nginx
sudo systemctl status php8.2-fpm
```

## Step 6: Verify Everything Works

```bash
# Test the GD endpoint
curl https://lukas-whatsapp.cloud/api/test-gd

# Should show:
# "gd_loaded": true
# "php_sapi": "fpm-fcgi"
# "php_version": "8.2.29"

# Test a simple API endpoint
curl https://lukas-whatsapp.cloud/api/test

# Check PHP-FPM logs
sudo tail -f /var/log/php8.2-fpm.log
```

## Step 7: Monitor and Maintain

### Enable Log Rotation

```bash
sudo nano /etc/logrotate.d/php8.2-fpm
```

Add:

```
/var/log/php8.2-fpm*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
    sharedscripts
    postrotate
        /usr/lib/php/php8.2-fpm-reopenlogs
    endscript
}
```

### Monitor PHP-FPM Performance

```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Monitor active processes
sudo watch -n 1 'ps aux | grep php-fpm | wc -l'

# Check slow queries
sudo tail -f /var/log/php8.2-fpm-slow.log
```

## Step 8: Security Hardening

### Configure Firewall (if not already done)

```bash
# Allow only necessary ports
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp
sudo ufw enable

# Verify
sudo ufw status
```

### Install and Configure Fail2Ban

```bash
# Install fail2ban
sudo apt-get install fail2ban -y

# Create custom jail for Nginx
sudo nano /etc/fail2ban/jail.local
```

Add:

```ini
[nginx-http-auth]
enabled = true
port = http,https
logpath = /var/log/nginx/error.log

[nginx-limit-req]
enabled = true
port = http,https
logpath = /var/log/nginx/error.log

[php-url-fopen]
enabled = true
port = http,https
logpath = /var/log/php8.2-fpm.log
```

```bash
# Restart fail2ban
sudo systemctl restart fail2ban
sudo systemctl enable fail2ban
```

## Step 9: Optimize Laravel for Production

```bash
cd /var/www/html/whatsapp-bot/backend

# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Optimize Composer autoloader
composer install --optimize-autoloader --no-dev
```

## Step 10: Test Image Processing

Send an image to your WhatsApp bot and verify:

```bash
# Monitor logs in real-time
sudo tail -f /var/www/html/whatsapp-bot/backend/storage/logs/laravel.log

# You should see:
# ✅ No "GD extension not loaded" warnings
# ✅ Thumbnail generation messages
# ✅ Image dimensions detected
```

## Troubleshooting

### If API returns 404

```bash
# Check Nginx error logs
sudo tail -f /var/log/nginx/error.log

# Check if public/index.php exists
ls -la /var/www/html/whatsapp-bot/backend/public/index.php
```

### If API returns 502 Bad Gateway

```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Check PHP-FPM error logs
sudo tail -f /var/log/php8.2-fpm.log

# Verify socket exists
ls -la /run/php/php8.2-fpm.sock
```

### If GD still not working

```bash
# Verify GD is loaded in PHP-FPM
php-fpm8.2 -m | grep gd

# Check the conf.d file
cat /etc/php/8.2/fpm/conf.d/20-gd.ini

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

## Performance Benchmarks

After setup, you should see:
- **Response time**: < 100ms for API calls
- **Memory usage**: ~50-100MB per PHP-FPM worker
- **Concurrent requests**: 50+ simultaneous connections
- **Image processing**: < 2 seconds with thumbnails

## Maintenance Commands

```bash
# Restart all services
sudo systemctl restart php8.2-fpm nginx

# Check service status
sudo systemctl status php8.2-fpm nginx reverb

# View logs
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/php8.2-fpm.log
sudo tail -f /var/www/html/whatsapp-bot/backend/storage/logs/laravel.log

# Monitor system resources
htop
```

## Backup Configuration

```bash
# Backup all configs
sudo tar -czf ~/nginx-php-backup-$(date +%Y%m%d).tar.gz \
    /etc/nginx/sites-available/whatsapp-bot \
    /etc/php/8.2/fpm/pool.d/www.conf \
    /etc/php/8.2/fpm/php.ini
```

## Summary

✅ **Secure**: PHP-FPM runs as www-data, proper file permissions, security headers  
✅ **Performant**: OPcache enabled, optimized pool settings, HTTP/2  
✅ **Reliable**: Process manager handles crashes, log rotation, monitoring  
✅ **Maintainable**: Clear logs, easy to debug, proper error handling  
✅ **GD Extension**: Fully working for image processing
