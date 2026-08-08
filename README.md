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

## Projektstruktur

```
.
├── backend/               # Laravel-Backend
├── frontend/              # Vue-3-Frontend
├── receiver/              # Node.js-WhatsApp-Client
├── docs/                  # Dokumentation
│   ├── DEPLOYMENT.md      # Deployment-Anleitung
│   ├── ENVIRONMENT.md     # Umgebungsvariablen
│   ├── SECURITY.md        # Sicherheitsdetails
│   ├── WEBSOCKET.md       # WebSocket-Setup
│   ├── QUEUE.md           # Queue-/Worker-Setup
│   └── archive/           # Ältere Dokumentation
└── README.md
```

## Setup

Voraussetzungen: PHP 8.1+, Node.js 16+, Composer, MySQL 8.0+ (oder SQLite),
ein WhatsApp-Account auf einem dauerhaft verbundenen Smartphone.

```bash
# Backend
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve

# Frontend
cd ../frontend/vue-project
npm install
npm run dev

# Receiver
cd ../../receiver
npm install
npm start
```

Danach den QR-Code aus der Receiver-Konsole mit WhatsApp auf dem verbundenen
Smartphone scannen. Details zu Umgebungsvariablen: [docs/ENVIRONMENT.md](docs/ENVIRONMENT.md),
zu Produktions-Deployment (Prozessmanager, Reverse Proxy): [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Security

Vor einem Produktions-Deployment: Secrets generieren (`generate-secrets.sh` /
`.ps1`), `APP_DEBUG=false` setzen, `WEBHOOK_SECRET` und `RECEIVER_API_KEY`
zwischen Backend und Receiver abgleichen, `CORS_ALLOWED_ORIGINS` auf die
tatsächliche Domain einschränken. Details in [docs/SECURITY.md](docs/SECURITY.md)
und [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Umgesetzt sind u. a. Webhook- und API-Key-Authentifizierung, CORS-Konfiguration,
Rate-Limiting, Input-Validierung, parametrisierte Queries und HTTPS/TLS. Die
URL-Prüfung im Receiver ist eine Allowlist-Härtung, **keine vollständige
SSRF-Abwehr** — das gehört bei jeder Weiterentwicklung mitgedacht.

## Weitere Dokumentation

- [WebSocket-Setup](docs/WEBSOCKET.md)
- [Queue-/Worker-Setup](docs/QUEUE.md)
- [Sicherheitsdetails](docs/SECURITY.md)
