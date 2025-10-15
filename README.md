# WhatsApp Bot Dashboard

A full-stack WhatsApp bot dashboard that allows receiving and sending WhatsApp messages (text, images, etc.) using a WhatsApp Web client (Baileys) running on a smartphone. Messages are sent via HTTP POST requests to a backend webhook and stored in a database.

## Project Structure

```
.
├── backend/               # Laravel backend
├── frontend/              # Vue 3 frontend
├── receiver/              # Node.js WhatsApp client
├── ENV_EXAMPLE.md         # Environment variables documentation
└── README.md              # This file
```

## Prerequisites

- PHP 8.1+
- Node.js 16+
- Composer
- MySQL 8.0+ or SQLite
- WhatsApp account with a smartphone

## Getting Started

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd whatsapp-bot
   ```

2. **Set up the backend**
   ```bash
   cd backend
   cp .env.example .env
   composer install
   php artisan key:generate
   php artisan migrate
   php artisan serve
   ```

3. **Set up the frontend**
   ```bash
   cd ../frontend/vue-project
   npm install
   npm run dev
   ```

4. **Set up the receiver**
   ```bash
   cd ../../receiver
   npm install
   npm start
   ```

5. **Configure WhatsApp**
   - Open the QR code shown in the receiver console with your WhatsApp mobile app
   - Scan the code to link your WhatsApp account

## Environment Configuration

See [ENV_EXAMPLE.md](ENV_EXAMPLE.md) for detailed environment variable configuration.

## Logging

### Backend (Laravel)
- Logs are stored in `storage/logs/laravel.log`
- Log level is controlled by `LOG_LEVEL` in `.env`

### Frontend (Vue 3)
- Logs are output to the browser console in development
- In production, logs are sent to the backend API

### Receiver (Node.js)
- Logs are output to the console by default
- Set `LOG_TO_FILE=true` to enable file logging
- Log files are stored in `logs/app.log`

## Deployment

### Production Deployment
1. Set up a production database
2. Configure environment variables in `.env` files
3. Run database migrations
4. Build the frontend assets
5. Set up a process manager (PM2, systemd, etc.) for the receiver
6. Configure a reverse proxy (Nginx, Apache) for the backend and frontend

### Containerization (Docker)
Docker support can be added by creating appropriate `Dockerfile` and `docker-compose.yml` files.

## Security

⚠️ **IMPORTANT**: This application has been security-hardened for production deployment.

### Before Deploying to Production

**REQUIRED STEPS**:

1. **Generate Secure Secrets**:
   ```powershell
   # Windows
   .\generate-secrets.ps1
   
   # Linux/Mac
   bash generate-secrets.sh
   ```

2. **Review Security Documentation**:
   - Read [SECURITY_FIXES_SUMMARY.md](SECURITY_FIXES_SUMMARY.md) for all security improvements
   - Follow [PRODUCTION_DEPLOYMENT_GUIDE.md](PRODUCTION_DEPLOYMENT_GUIDE.md) step-by-step

3. **Critical Configuration**:
   - Set `APP_DEBUG=false` in backend `.env`
   - Configure `WEBHOOK_SECRET` (must match in backend and receiver)
   - Configure `RECEIVER_API_KEY` (must match in backend and receiver)
   - Set `CORS_ALLOWED_ORIGINS` to your actual domain (not `*`)
   - Use strong database passwords

### Security Features Implemented

✅ **Webhook Authentication** - Prevents unauthorized message injection  
✅ **API Key Protection** - Secures receiver service endpoints  
✅ **Input Sanitization** - Prevents XSS and injection attacks  
✅ **CORS Configuration** - Restricts cross-origin requests  
✅ **Rate Limiting** - Protects against brute force and DDoS  
✅ **File Upload Validation** - Prevents malicious file uploads  
✅ **SQL Injection Prevention** - Uses parameterized queries  
✅ **HTTPS/TLS Support** - Encrypted communications  

### Security Checklist

Before going live, ensure:

- [ ] All secrets generated and configured
- [ ] `APP_DEBUG=false` in production
- [ ] CORS configured with actual domain
- [ ] SSL/TLS certificate installed
- [ ] Firewall configured (only ports 22, 80, 443 open)
- [ ] Database user has minimal privileges
- [ ] File permissions set correctly
- [ ] Rate limiting enabled
- [ ] Logs monitored
- [ ] Backups configured

See [PRODUCTION_DEPLOYMENT_GUIDE.md](PRODUCTION_DEPLOYMENT_GUIDE.md) for complete details.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

