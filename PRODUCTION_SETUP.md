# Production Server Setup Checklist

## 1. Fixed Migration Issue ✅
The circular dependency between `chats` and `whatsapp_messages` tables has been fixed.

## 2. CORS Configuration for Login

### Backend Changes Made:
- Added CORS middleware to API routes in `bootstrap/app.php`

### Required Environment Variables:

Add these to your production `.env` file on the server:

```bash
# CORS Configuration
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com,http://localhost:5173

# Or for testing, allow all origins (NOT RECOMMENDED for production):
CORS_ALLOWED_ORIGINS=*
```

### After Updating Files on Server:

1. **Upload the updated files (CRITICAL):**
   - `backend/bootstrap/app.php` (CORS middleware)
   - `backend/public/.htaccess` (CORS headers - **THIS IS THE KEY FIX**)
   - `backend/database/migrations/2025_01_01_000000_unified_database_schema.php`
   - `backend/app/Console/Commands/CreateAdminUser.php`

2. **Verify Apache modules are enabled (if using Apache):**
   ```bash
   sudo a2enmod headers
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

3. **Clear Laravel cache:**
   ```bash
   cd /var/www/html/whatsapp-bot/backend
   php artisan config:clear
   php artisan cache:clear
   php artisan route:clear
   php artisan optimize:clear
   ```

4. **Create admin user:**
   ```bash
   php artisan user:create-admin
   ```
   
   Or with custom credentials:
   ```bash
   php artisan user:create-admin --name="Admin" --phone="+1234567890" --password="your-secure-password"
   ```

5. **Restart PHP-FPM (if applicable):**
   ```bash
   sudo systemctl restart php8.2-fpm
   # or
   sudo systemctl restart php-fpm
   ```

6. **Restart web server:**
   ```bash
   sudo systemctl restart nginx
   # or
   sudo systemctl restart apache2
   ```

## 3. Verify Login Works

Test the login endpoint:
```bash
curl -X POST https://your-domain.com/api/login \
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

## 4. Common Issues

### Issue: 405 Method Not Allowed
**Cause:** CORS middleware not applied or web server blocking POST requests

**Solution:**
1. Verify `bootstrap/app.php` has CORS middleware
2. Check nginx/apache configuration allows POST to `/api/login`
3. Clear all caches

### Issue: 500 Internal Server Error
**Cause:** Missing environment variables or database connection

**Solution:**
1. Check `.env` file has all required variables
2. Run `php artisan config:clear`
3. Check Laravel logs: `tail -f storage/logs/laravel.log`

### Issue: CORS Error in Browser
**Cause:** `CORS_ALLOWED_ORIGINS` not set correctly

**Solution:**
Set in `.env`:
```bash
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com
```

## 5. Security Recommendations

1. **Change default password immediately after first login**
2. **Set specific CORS origins** (not `*`) in production
3. **Enable HTTPS** for both frontend and backend
4. **Set `APP_ENV=production`** in `.env`
5. **Set `APP_DEBUG=false`** in `.env`
