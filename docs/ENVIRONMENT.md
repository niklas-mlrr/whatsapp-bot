# Environment Variables Configuration

This document describes the environment variables required for the WhatsApp Bot project.

## Backend (Laravel)

Create a `.env` file in the `backend` directory with the following variables:

```
APP_NAME="WhatsApp Bot"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsapp_bot
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DRIVER=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1

MIX_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
MIX_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

# WhatsApp Webhook Secret (for securing webhook endpoints)
WHATSAPP_WEBHOOK_SECRET=

# API Rate Limiting
API_RATE_LIMIT=60
```

## Frontend (Vue 3)

Create a `.env` file in the `frontend/vue-project` directory with the following variables:

```
# API Base URL (should match your backend URL)
VITE_API_URL=http://localhost:8000/api

# WebSocket URL (if using real-time features)
VITE_WS_URL=ws://localhost:8000

# App Name (displayed in the UI)
VITE_APP_NAME="WhatsApp Bot"

# App Environment (development, staging, production)
VITE_APP_ENV=development

# Enable/disable debug mode
VITE_APP_DEBUG=true

# Pusher (if using for real-time features)
VITE_PUSHER_APP_KEY=
VITE_PUSHER_APP_CLUSTER=
```

## Receiver (Node.js)

Create a `.env` file in the `receiver` directory with the following variables:

```
# Server Configuration
PORT=3000
NODE_ENV=development

# WhatsApp Configuration
WHATSAPP_CLIENT_NAME="WhatsApp Bot"
WHATSAPP_AUTH_DIR="./baileys_auth_info"

# Backend API Configuration
BACKEND_API_URL="http://localhost:8000/api/whatsapp-webhook"
BACKEND_API_KEY="" # Optional: if your API requires authentication

# Logging
LOG_LEVEL="info" # error, warn, info, debug
LOG_TO_FILE=true # Set to false to log only to console
LOG_FILE_PATH="./logs/app.log"

# Media Handling
MAX_MEDIA_SIZE_MB=16 # Maximum media file size in MB
MEDIA_DOWNLOAD_TIMEOUT_MS=30000 # 30 seconds

# Security
RATE_LIMIT_WINDOW_MS=900000 # 15 minutes
RATE_LIMIT_MAX_REQUESTS=100 # Max requests per window

# Webhook Configuration (if receiver exposes webhooks)
WEBHOOK_SECRET="" # Secret for webhook verification
```

## Setup Instructions

1. Copy the respective `.env.example` files to `.env` in each directory.
2. Update the values according to your environment.
3. For security, never commit `.env` files to version control.
4. For production, ensure all sensitive values are properly secured and environment-specific configurations are used.

## Security Notes

- Keep all secrets (API keys, database credentials, etc.) secure and never commit them to version control.
- Use different credentials for development, staging, and production environments.
- Consider using a secret management service for production deployments.
- Regularly rotate sensitive credentials.
- Set appropriate file permissions for `.env` files (e.g., `chmod 600 .env` on Unix-like systems).
