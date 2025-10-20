# Installing PHP GD Extension on Server

## Problem
The server is missing the PHP GD extension, which is required for processing image dimensions. The application now handles this gracefully, but installing GD will enable full image dimension detection.

## Solution

### For Ubuntu/Debian Servers

1. **Install the GD extension:**
   ```bash
   sudo apt-get update
   sudo apt-get install php8.2-gd
   ```

2. **Restart PHP-FPM (if using nginx):**
   ```bash
   sudo systemctl restart php8.2-fpm
   ```

3. **Or restart Apache (if using Apache):**
   ```bash
   sudo systemctl restart apache2
   ```

4. **Verify installation:**
   ```bash
   php -m | grep gd
   ```
   
   You should see `gd` in the output.

### For CentOS/RHEL Servers

1. **Install the GD extension:**
   ```bash
   sudo yum install php-gd
   ```

2. **Restart PHP-FPM or Apache:**
   ```bash
   sudo systemctl restart php-fpm
   # or
   sudo systemctl restart httpd
   ```

### Verify in Laravel

After installation, you can verify GD is loaded by creating a temporary route:

```php
Route::get('/test-gd', function() {
    return [
        'gd_loaded' => extension_loaded('gd'),
        'gd_info' => function_exists('gd_info') ? gd_info() : 'GD not available'
    ];
});
```

## Current Status

✅ **Fixed:** The application now gracefully handles missing GD extension
- Images will still be received and stored
- Image dimensions will be `null` in metadata if GD is not available
- No more 500 errors when receiving images

⚠️ **Optional:** Install GD extension to enable image dimension detection

## Testing

After deploying the fix:
1. Send an image to your WhatsApp bot
2. Check that it's received without errors
3. If GD is not installed, you'll see a warning in logs but the image will still be processed
4. After installing GD, image dimensions will be automatically detected
