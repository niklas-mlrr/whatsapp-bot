# Messenger-Weboberfläche für WhatsApp

Eine private Weboberfläche, über die ein Nutzer ohne eigenes Smartphone seine
WhatsApp-Chats nutzen kann. Nachrichten und Medien werden über ein dauerhaft
verbundenes Smartphone empfangen und gesendet; die Oberfläche macht sie im
Browser verfügbar.

## Kurzüberblick

Das Projekt entstand für einen Mitschüler, der ohne eigenes Smartphone Zugang
zu seinen Klassengruppen brauchte. Es läuft seit über einem Jahr auf einem
selbst administrierten Server mit eigener Domain und TLS.

**Was die Anwendung bietet:**

- Nachrichten und Medien in einer Weboberfläche lesen und senden
- Echtzeit-Updates für eingehende Nachrichten und Änderungen
- Chats, Kontakte, Antworten, Reaktionen und Umfragen verwalten
- Hintergrundverarbeitung für eingehende Nachrichten und Medien

**Architektur:** Eine Vue-3-Oberfläche kommuniziert mit einem Laravel-Backend.
Ein separater Node.js-Receiver bindet über Baileys ein WhatsApp-Smartphone an;
Laravel Reverb überträgt Änderungen in Echtzeit an den Browser. MySQL speichert
Chats und Nachrichten, Warteschlangen verarbeiten aufwändigere Aufgaben im
Hintergrund.

Das Projekt ist eine maßgeschneiderte Lösung für diesen Einsatz, kein
allgemeines Messenger-Produkt und keine öffentliche Demo.

## Project Structure

```
.
├── backend/               # Laravel backend
├── frontend/              # Vue 3 frontend
├── receiver/              # Node.js WhatsApp client
├── docs/                  # Documentation
│   ├── DEPLOYMENT.md      # Production deployment guide
│   ├── ENVIRONMENT.md     # Environment variables
│   ├── SECURITY.md        # Security implementation details
│   ├── WEBSOCKET.md       # WebSocket setup
│   ├── QUEUE.md           # Queue/worker setup
│   └── archive/           # Historical documentation
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

See [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md) for detailed environment variable configuration.

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

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for comprehensive production deployment instructions.

### Quick Start
1. Set up a production database
2. Configure environment variables in `.env` files
3. Run database migrations
4. Build the frontend assets
5. Set up a process manager (PM2, systemd, etc.) for the receiver
6. Configure a reverse proxy (Nginx, Apache) for the backend and frontend

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
   - Read [docs/SECURITY.md](docs/SECURITY.md) for security implementation details
   - Follow [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) step-by-step

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

## Additional Documentation

- [WebSocket Setup](docs/WEBSOCKET.md) - Real-time messaging configuration
- [Queue/Worker Setup](docs/QUEUE.md) - Background job processing
- [Security Details](docs/SECURITY.md) - Security implementation overview
