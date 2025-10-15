# Production Deployment Guide - WhatsApp Bot

## 🔒 Security Hardening Checklist

This guide covers all security measures that MUST be implemented before deploying to a public VPS (Hostinger or any other hosting provider).

---

## 1. Environment Variables Configuration

### Backend (.env)

Create/update `backend/.env` with the following **REQUIRED** security settings:

```bash
# Application Settings
APP_NAME="WhatsApp Bot"
APP_ENV=production
APP_DEBUG=false  # CRITICAL: Must be false in production
APP_URL=https://yourdomain.com

# Generate a new key with: php artisan key:generate
APP_KEY=base64:YOUR_GENERATED_KEY_HERE

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsapp_bot_prod
DB_USERNAME=whatsapp_user
DB_PASSWORD=STRONG_PASSWORD_HERE  # Use a strong, unique password

# Security - CRITICAL SETTINGS
# Generate with: openssl rand -hex 32
WEBHOOK_SECRET=YOUR_64_CHAR_WEBHOOK_SECRET_HERE
RECEIVER_API_KEY=YOUR_64_CHAR_API_KEY_HERE

# CORS Configuration
# Replace with your actual frontend domain(s), comma-separated
CORS_ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com

# Sanctum Configuration
SANCTUM_STATEFUL_DOMAINS=yourdomain.com,www.yourdomain.com

# Session Configuration
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true  # HTTPS only
SESSION_SAME_SITE=strict

# Receiver Configuration
RECEIVER_URL=http://localhost:3000  # Internal communication
RECEIVER_TLS_INSECURE=false  # Only true for self-signed certs

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=warning  # Don't log debug info in production

# Cache & Queue
CACHE_DRIVER=redis  # Recommended for production
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Frontend (.env)

Create/update `frontend/vue-project/.env.production`:

```bash
# API Configuration
VITE_API_URL=https://yourdomain.com/api
VITE_WS_URL=wss://yourdomain.com

# App Configuration
VITE_APP_NAME="WhatsApp Bot"
VITE_APP_ENV=production
VITE_APP_DEBUG=false
```


### Receiver (.env)

Create/update `receiver/.env`:

```bash
# Server Configuration
PORT=3000
NODE_ENV=production

# WhatsApp Configuration
WHATSAPP_CLIENT_NAME="WhatsApp Bot"
WHATSAPP_AUTH_DIR="./baileys_auth_info"

# Backend API Configuration
BACKEND_API_URL="http://localhost:8000/api/whatsapp-webhook"
WEBHOOK_SECRET=YOUR_64_CHAR_WEBHOOK_SECRET_HERE  # MUST match backend

# Security
RECEIVER_API_KEY=YOUR_64_CHAR_API_KEY_HERE  # MUST match backend

# Logging
LOG_LEVEL="warning"
LOG_TO_FILE=true
LOG_FILE_PATH="./logs/app.log"

# Media Handling
MAX_MEDIA_SIZE_MB=16
MEDIA_DOWNLOAD_TIMEOUT_MS=30000
```

---

## 2. Generate Secure Secrets

Run these commands to generate secure secrets:

```bash
# Generate Webhook Secret (64 characters)
openssl rand -hex 32

# Generate Receiver API Key (64 characters)
openssl rand -hex 32

# Generate Laravel APP_KEY
cd backend
php artisan key:generate
```

**IMPORTANT**: 
- Use DIFFERENT secrets for `WEBHOOK_SECRET` and `RECEIVER_API_KEY`
- Store these secrets securely (password manager)
- Never commit them to version control

---

## 3. Database Security

### Create Dedicated Database User

```sql
-- Connect to MySQL as root
mysql -u root -p

-- Create database
CREATE DATABASE whatsapp_bot_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create dedicated user with limited privileges
CREATE USER 'whatsapp_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

-- Grant only necessary privileges
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER 
ON whatsapp_bot_prod.* 
TO 'whatsapp_user'@'localhost';

FLUSH PRIVILEGES;
```

### Secure MySQL Configuration

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
# Bind to localhost only (if backend is on same server)
bind-address = 127.0.0.1

# Disable remote root login
skip-networking = 0

# Enable SSL for remote connections (if needed)
require_secure_transport = ON
```

---

## 4. File Permissions

Set proper file permissions on the server:

```bash
# Backend
cd backend
chmod -R 755 storage bootstrap/cache
chmod 644 .env
chown -R www-data:www-data storage bootstrap/cache

# Receiver
cd ../receiver
chmod 644 .env
chmod -R 700 baileys_auth_info
chmod -R 755 logs media

# Ensure .env files are not web-accessible
# Add to .htaccess or nginx config
```

---

## 5. Web Server Configuration

### Nginx Configuration (Recommended)

Create `/etc/nginx/sites-available/whatsapp-bot`:

```nginx
# Rate limiting zones
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
limit_req_zone $binary_remote_addr zone=api:10m rate=120r/m;
limit_req_zone $binary_remote_addr zone=webhook:10m rate=60r/m;

server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    
    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    
    # Hide server version
    server_tokens off;
    
    root /var/www/whatsapp-bot/frontend/vue-project/dist;
    index index.html;
    
    # Frontend (SPA)
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    # Backend API
    location /api {
        # Rate limiting
        limit_req zone=api burst=20 nodelay;
        
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
    
    # Login endpoint with stricter rate limiting
    location /api/login {
        limit_req zone=login burst=3 nodelay;
        
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # Webhook endpoint
    location /api/whatsapp/webhook {
        limit_req zone=webhook burst=10 nodelay;
        
        # Only allow from receiver service (localhost)
        allow 127.0.0.1;
        deny all;
        
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # Deny access to sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    location ~ /\.env {
        deny all;
    }
    
    # File upload size limit
    client_max_body_size 50M;
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/whatsapp-bot /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 6. SSL/TLS Certificate

Install Let's Encrypt certificate:

```bash
sudo apt update
sudo apt install certbot python3-certbot-nginx

# Obtain certificate
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com

# Auto-renewal is configured automatically
# Test renewal:
sudo certbot renew --dry-run
```

---

## 7. Firewall Configuration

Configure UFW (Uncomplicated Firewall):

```bash
# Enable firewall
sudo ufw enable

# Allow SSH (change port if using non-standard)
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Deny direct access to backend and receiver ports
sudo ufw deny 8000/tcp
sudo ufw deny 3000/tcp

# Check status
sudo ufw status verbose
```

---

## 8. Application Deployment

### Backend Deployment

```bash
cd backend

# Install dependencies (production only)
composer install --no-dev --optimize-autoloader

# Run migrations
php artisan migrate --force

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create storage link
php artisan storage:link

# Set permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Frontend Deployment

```bash
cd frontend/vue-project

# Install dependencies
npm ci --production

# Build for production
npm run build

# Deploy dist folder to web root
sudo cp -r dist/* /var/www/whatsapp-bot/frontend/vue-project/dist/
```

### Receiver Deployment

```bash
cd receiver

# Install dependencies
npm ci --production

# Install PM2 for process management
sudo npm install -g pm2

# Start receiver service
pm2 start index.js --name whatsapp-receiver

# Save PM2 configuration
pm2 save

# Setup PM2 to start on boot
pm2 startup
```

---

## 9. Process Management

### Backend (Laravel)

Use Supervisor to manage Laravel queue workers:

Create `/etc/supervisor/conf.d/whatsapp-bot.conf`:

```ini
[program:whatsapp-bot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/whatsapp-bot/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/whatsapp-bot/backend/storage/logs/worker.log
stopwaitsecs=3600
```

Start the worker:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start whatsapp-bot-worker:*
```

### Receiver (Node.js)

PM2 configuration is already set up in step 8.

Monitor processes:

```bash
# View logs
pm2 logs whatsapp-receiver

# Monitor status
pm2 monit

# Restart if needed
pm2 restart whatsapp-receiver
```

---

## 10. Monitoring & Logging

### Log Rotation

Create `/etc/logrotate.d/whatsapp-bot`:

```
/var/www/whatsapp-bot/backend/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}

/var/www/whatsapp-bot/receiver/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

### Health Monitoring

Set up a cron job to monitor services:

```bash
# Add to crontab
crontab -e

# Check services every 5 minutes
*/5 * * * * curl -f http://localhost:3000/status || systemctl restart whatsapp-receiver
```

---

## 11. Backup Strategy

### Database Backup

Create `/usr/local/bin/backup-whatsapp-db.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/whatsapp-bot"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="whatsapp_bot_prod"
DB_USER="whatsapp_user"
DB_PASS="YOUR_DB_PASSWORD"

mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Keep only last 7 days
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +7 -delete

# Backup uploaded files
tar -czf $BACKUP_DIR/storage_$DATE.tar.gz /var/www/whatsapp-bot/backend/storage/app/public/uploads

# Keep only last 7 days
find $BACKUP_DIR -name "storage_*.tar.gz" -mtime +7 -delete
```

Make executable and add to cron:

```bash
chmod +x /usr/local/bin/backup-whatsapp-db.sh

# Add to crontab (daily at 2 AM)
0 2 * * * /usr/local/bin/backup-whatsapp-db.sh
```

---

## 12. Security Hardening Checklist

- [ ] All `.env` files configured with strong secrets
- [ ] `APP_DEBUG=false` in production
- [ ] `WEBHOOK_SECRET` and `RECEIVER_API_KEY` set and unique
- [ ] CORS configured with actual domain (not `*`)
- [ ] Database user has minimal privileges
- [ ] File permissions set correctly (644 for .env, 755 for directories)
- [ ] SSL/TLS certificate installed and auto-renewal configured
- [ ] Firewall configured (UFW or iptables)
- [ ] Rate limiting enabled in Nginx
- [ ] Security headers configured
- [ ] Debug/test endpoints removed or disabled in production
- [ ] Logs are rotated and monitored
- [ ] Backup strategy implemented
- [ ] PM2 and Supervisor configured for auto-restart
- [ ] Server software updated (apt update && apt upgrade)

---

## 13. Post-Deployment Testing

### Test Security

```bash
# Test rate limiting
for i in {1..10}; do curl -X POST https://yourdomain.com/api/login -d '{"password":"test"}'; done

# Test CORS
curl -H "Origin: https://evil.com" -I https://yourdomain.com/api/chats

# Test webhook authentication
curl -X POST https://yourdomain.com/api/whatsapp/webhook -d '{"test":"data"}'

# Should return 401 Unauthorized
```

### Test Functionality

1. **Login**: Try logging in with correct password
2. **Send Message**: Send a test WhatsApp message
3. **Receive Message**: Send a message to your WhatsApp number
4. **File Upload**: Upload an image
5. **Reactions**: Test emoji reactions

---

## 14. Maintenance Commands

```bash
# Clear all caches
cd /var/www/whatsapp-bot/backend
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Recache for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
pm2 restart whatsapp-receiver
sudo supervisorctl restart whatsapp-bot-worker:*
```

---

## 15. Troubleshooting

### Check Logs

```bash
# Backend logs
tail -f /var/www/whatsapp-bot/backend/storage/logs/laravel.log

# Receiver logs
pm2 logs whatsapp-receiver

# Nginx logs
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log
```

### Common Issues

**Issue**: 401 Unauthorized on webhook
- **Solution**: Check `WEBHOOK_SECRET` matches in backend and receiver

**Issue**: 503 Service Unavailable on /send-message
- **Solution**: Check `RECEIVER_API_KEY` is set in receiver .env

**Issue**: CORS errors
- **Solution**: Update `CORS_ALLOWED_ORIGINS` in backend .env

**Issue**: File upload fails
- **Solution**: Check storage permissions and nginx `client_max_body_size`

---

## 16. Security Incident Response

If you suspect a security breach:

1. **Immediately**:
   - Rotate all secrets (`WEBHOOK_SECRET`, `RECEIVER_API_KEY`, `APP_KEY`)
   - Check logs for suspicious activity
   - Temporarily disable public access if needed

2. **Investigate**:
   - Review access logs: `/var/log/nginx/access.log`
   - Check application logs for errors
   - Verify database integrity

3. **Remediate**:
   - Update all dependencies
   - Apply security patches
   - Strengthen firewall rules
   - Consider implementing fail2ban

---

## Support & Updates

- Keep all dependencies updated regularly
- Monitor security advisories for Laravel, Node.js, and dependencies
- Review logs weekly for anomalies
- Test backups monthly

**Remember**: Security is an ongoing process, not a one-time setup!
