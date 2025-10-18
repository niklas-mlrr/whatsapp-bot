# Nginx Deployment Instructions for Production

## Step 1: Find Your Current Nginx Site Configuration

On your production server, run:

```bash
# Check which config file is being used
sudo nginx -T | grep "server_name lukas-whatsapp.cloud" -B 20

# Or list all site configs
ls -la /etc/nginx/sites-available/
ls -la /etc/nginx/sites-enabled/
```

Common locations:
- `/etc/nginx/sites-available/default`
- `/etc/nginx/sites-available/lukas-whatsapp.cloud`
- `/etc/nginx/conf.d/lukas-whatsapp.cloud.conf`

## Step 2: Backup Current Configuration

```bash
sudo cp /etc/nginx/sites-available/your-config /etc/nginx/sites-available/your-config.backup
```

## Step 3: Update Nginx Configuration

Edit your site configuration file:

```bash
sudo nano /etc/nginx/sites-available/your-config
```

**Add these CRITICAL sections:**

### A. Add CORS Headers (inside the `server` block for port 443):

```nginx
# CORS Headers - Add to all responses
add_header 'Access-Control-Allow-Origin' '*' always;
add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
add_header 'Access-Control-Allow-Credentials' 'true' always;
add_header 'Access-Control-Max-Age' '3600' always;
```

### B. Handle OPTIONS Preflight Requests (inside the `server` block):

```nginx
# Handle preflight OPTIONS requests
if ($request_method = 'OPTIONS') {
    add_header 'Access-Control-Allow-Origin' '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS, PATCH' always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN' always;
    add_header 'Access-Control-Max-Age' '3600' always;
    add_header 'Content-Length' '0';
    add_header 'Content-Type' 'text/plain charset=UTF-8';
    return 204;
}
```

### C. Ensure Correct Document Root:

```nginx
root /var/www/html/whatsapp-bot/backend/public;
index index.php index.html;
```

### D. Ensure Proper Location Block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### E. PHP-FPM Configuration:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;  # Adjust PHP version
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    
    fastcgi_read_timeout 300;
    fastcgi_send_timeout 300;
}
```

## Step 4: Test Nginx Configuration

```bash
sudo nginx -t
```

You should see:
```
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

## Step 5: Reload Nginx

```bash
sudo systemctl reload nginx
# or
sudo systemctl restart nginx
```

## Step 6: Upload Updated Laravel Files

Upload these files to your server:
- `backend/bootstrap/app.php`
- `backend/database/migrations/2025_01_01_000000_unified_database_schema.php`
- `backend/app/Console/Commands/CreateAdminUser.php`

## Step 7: Clear Laravel Caches

```bash
cd /var/www/html/whatsapp-bot/backend
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan optimize:clear
```

## Step 8: Create Admin User

```bash
php artisan user:create-admin
```

## Step 9: Test Login Endpoint

```bash
curl -X POST https://lukas-whatsapp.cloud/api/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+10000000000","password":"admin123"}'
```

Expected response:
```json
{
  "token": "...",
  "user": {
    "id": 1,
    "name": "Admin",
    "phone": "+10000000000"
  }
}
```

## Troubleshooting

### Still Getting 405 Error?

1. **Check nginx error logs:**
   ```bash
   sudo tail -f /var/log/nginx/error.log
   ```

2. **Verify CORS headers are being sent:**
   ```bash
   curl -I -X OPTIONS https://lukas-whatsapp.cloud/api/login
   ```
   
   Should show:
   ```
   Access-Control-Allow-Origin: *
   Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH
   ```

3. **Check if nginx is reading the correct config:**
   ```bash
   sudo nginx -T | grep "Access-Control-Allow-Origin"
   ```

4. **Verify document root:**
   ```bash
   sudo nginx -T | grep "root" | grep whatsapp
   ```

### Check PHP-FPM is Running

```bash
sudo systemctl status php8.2-fpm
# or
sudo systemctl status php-fpm
```

### Check File Permissions

```bash
sudo chown -R www-data:www-data /var/www/html/whatsapp-bot/backend
sudo chmod -R 755 /var/www/html/whatsapp-bot/backend
sudo chmod -R 775 /var/www/html/whatsapp-bot/backend/storage
sudo chmod -R 775 /var/www/html/whatsapp-bot/backend/bootstrap/cache
```

## Complete Example Configuration

See the file `nginx-site-config.conf` for a complete working example.
