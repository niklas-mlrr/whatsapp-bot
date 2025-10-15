# Complete Secrets & Environment Variables Guide

## 🔑 All Required Secrets for Production

This guide covers **ALL** secrets and environment variables needed for secure production deployment.

---

## 1. Backend Secrets (`.env`)

### Critical Secrets

#### `APP_KEY` - **CRITICAL** 🔴
```bash
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Purpose**: 
- Encrypts session data
- Signs cookies
- Encrypts database values
- Core Laravel encryption

**How to generate**:
```bash
cd backend
php artisan key:generate
```

**⚠️ CRITICAL WARNINGS**:
- **NEVER change after production deployment** - encrypted data will be lost!
- **NEVER commit to Git** - already in `.gitignore`
- **Backup this key** - if lost, encrypted data is unrecoverable
- **Must be 32 characters** (base64 encoded)

---

#### `WEBHOOK_SECRET` - **CRITICAL** 🔴
```bash
WEBHOOK_SECRET=64_character_hex_string_here
```

**Purpose**:
- Authenticates webhook requests from receiver service
- Prevents unauthorized message injection
- Protects `/api/whatsapp/webhook` endpoint

**How to generate**:
```bash
# Included in generate-secrets.ps1 / generate-secrets.sh
openssl rand -hex 32
```

**Requirements**:
- ✅ Must be 64 characters (32 bytes hex)
- ✅ Must match in backend AND receiver
- ✅ Must be different from other secrets

---

#### `RECEIVER_API_KEY` - **CRITICAL** 🔴
```bash
RECEIVER_API_KEY=64_character_hex_string_here
```

**Purpose**:
- Authenticates backend requests to receiver service
- Protects receiver `/send-message` endpoint
- Prevents unauthorized WhatsApp message sending

**How to generate**:
```bash
# Included in generate-secrets.ps1 / generate-secrets.sh
openssl rand -hex 32
```

**Requirements**:
- ✅ Must be 64 characters (32 bytes hex)
- ✅ Must match in backend AND receiver
- ✅ Must be different from WEBHOOK_SECRET

---

#### `REVERB_APP_KEY` - **IMPORTANT** 🟡
```bash
REVERB_APP_KEY=32_character_hex_string_here
```

**Purpose**:
- Authenticates WebSocket connections (Laravel Reverb)
- Used for real-time message updates
- Shared between backend and frontend

**How to generate**:
```bash
# Included in generate-secrets.ps1 / generate-secrets.sh
openssl rand -hex 16
```

**Requirements**:
- ✅ Must be 32 characters (16 bytes hex)
- ✅ Must match in backend AND frontend
- ✅ Used in `VITE_REVERB_APP_KEY` on frontend

---

#### `REVERB_APP_SECRET` - **IMPORTANT** 🟡
```bash
REVERB_APP_SECRET=64_character_hex_string_here
```

**Purpose**:
- Server-side WebSocket authentication
- Signs WebSocket tokens
- Backend only (not shared with frontend)

**How to generate**:
```bash
# Included in generate-secrets.ps1 / generate-secrets.sh
openssl rand -hex 32
```

**Requirements**:
- ✅ Must be 64 characters (32 bytes hex)
- ✅ Backend only - NOT in frontend
- ✅ Keep secret from clients

---

#### `DB_PASSWORD` - **CRITICAL** 🔴
```bash
DB_PASSWORD=strong_random_password_here
```

**Purpose**:
- MySQL/database authentication
- Protects database access

**How to generate**:
```bash
# Included in generate-secrets.ps1 / generate-secrets.sh
openssl rand -base64 32 | tr -d "=+/" | cut -c1-32
```

**Requirements**:
- ✅ At least 32 characters
- ✅ Mix of letters, numbers, symbols
- ✅ Different for dev and production

---

### Other Important Backend Settings

```bash
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsapp_bot_prod
DB_USERNAME=whatsapp_user

# CORS (CRITICAL)
CORS_ALLOWED_ORIGINS=https://yourdomain.com

# Session Security
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

# Reverb Configuration
REVERB_APP_ID=whatsapp-bot
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Receiver
RECEIVER_URL=http://localhost:3000
```

---

## 2. Receiver Secrets (`.env`)

```bash
# Server
PORT=3000
NODE_ENV=production

# Security - MUST match backend
WEBHOOK_SECRET=same_as_backend_webhook_secret
RECEIVER_API_KEY=same_as_backend_receiver_api_key

# Backend API
BACKEND_API_URL=http://localhost:8000/api/whatsapp-webhook

# Logging
LOG_LEVEL=warning
LOG_TO_FILE=true
```

---

## 3. Frontend Secrets (`.env.production`)

```bash
# API Configuration
VITE_API_URL=https://yourdomain.com/api
VITE_WS_URL=wss://yourdomain.com

# Reverb WebSocket - MUST match backend
VITE_REVERB_APP_KEY=same_as_backend_reverb_app_key
VITE_REVERB_HOST=yourdomain.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# App Configuration
VITE_APP_NAME="WhatsApp Bot"
VITE_APP_ENV=production
VITE_APP_DEBUG=false
```

---

## 🚀 Quick Setup Guide

### Step 1: Generate All Secrets

Run the secret generation script:

```powershell
# Windows
.\generate-secrets.ps1

# Linux/Mac
bash generate-secrets.sh
```

This generates:
- ✅ WEBHOOK_SECRET (64 chars)
- ✅ RECEIVER_API_KEY (64 chars)
- ✅ DB_PASSWORD (32 chars)
- ✅ REVERB_APP_KEY (32 chars)
- ✅ REVERB_APP_SECRET (64 chars)

### Step 2: Generate Laravel APP_KEY

```bash
cd backend
php artisan key:generate
```

### Step 3: Copy Secrets to .env Files

**Backend `.env`**:
```bash
APP_KEY=<generated_by_artisan>
WEBHOOK_SECRET=<from_script>
RECEIVER_API_KEY=<from_script>
REVERB_APP_KEY=<from_script>
REVERB_APP_SECRET=<from_script>
DB_PASSWORD=<from_script>
```

**Receiver `.env`**:
```bash
WEBHOOK_SECRET=<same_as_backend>
RECEIVER_API_KEY=<same_as_backend>
```

**Frontend `.env.production`**:
```bash
VITE_REVERB_APP_KEY=<same_as_backend_reverb_app_key>
```

---

## 🔒 Security Best Practices

### Secret Management

1. **Never Commit Secrets**
   - ✅ All `.env` files are in `.gitignore`
   - ✅ Never commit `secrets.txt` if generated
   - ✅ Use environment variables in CI/CD

2. **Use Different Secrets for Each Environment**
   - ✅ Development secrets ≠ Production secrets
   - ✅ Staging secrets ≠ Production secrets
   - ✅ Never reuse secrets across environments

3. **Store Secrets Securely**
   - ✅ Use a password manager (1Password, LastPass, Bitwarden)
   - ✅ Encrypt backups
   - ✅ Limit access to production secrets

4. **Rotate Secrets Regularly**
   - ✅ Every 90 days minimum
   - ✅ Immediately if compromised
   - ✅ After team member departure

5. **Monitor for Leaks**
   - ✅ Use GitHub secret scanning
   - ✅ Monitor logs for unauthorized access
   - ✅ Set up alerts for failed authentication

---

## 🔄 Secret Matching Requirements

### Must Match Between Services

| Secret | Backend | Receiver | Frontend |
|--------|---------|----------|----------|
| `WEBHOOK_SECRET` | ✅ | ✅ | ❌ |
| `RECEIVER_API_KEY` | ✅ | ✅ | ❌ |
| `REVERB_APP_KEY` | ✅ | ❌ | ✅ |
| `REVERB_APP_SECRET` | ✅ | ❌ | ❌ |
| `APP_KEY` | ✅ | ❌ | ❌ |
| `DB_PASSWORD` | ✅ | ❌ | ❌ |

---

## ⚠️ What Happens If...

### If `APP_KEY` is lost:
- ❌ All encrypted data is unrecoverable
- ❌ All sessions are invalidated
- ❌ Users must re-authenticate
- ❌ Encrypted database values are lost

**Solution**: **BACKUP THIS KEY!**

### If `APP_KEY` is changed:
- ❌ Same as if lost
- ❌ Cannot decrypt existing data

**Solution**: **NEVER change in production!**

### If `WEBHOOK_SECRET` is compromised:
- ⚠️ Attackers can inject fake messages
- ⚠️ Data integrity at risk

**Solution**: 
1. Generate new secret
2. Update backend and receiver
3. Restart both services

### If `RECEIVER_API_KEY` is compromised:
- ⚠️ Attackers can send WhatsApp messages
- ⚠️ Unauthorized access to WhatsApp

**Solution**:
1. Generate new API key
2. Update backend and receiver
3. Restart both services

### If `REVERB_APP_KEY` is compromised:
- ⚠️ Attackers can connect to WebSocket
- ⚠️ May see real-time message updates

**Solution**:
1. Generate new key
2. Update backend and frontend
3. Rebuild frontend
4. Restart backend

---

## 🧪 Testing Secrets

### Test Webhook Authentication

```bash
# Should return 401 Unauthorized
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "Content-Type: application/json" \
  -d '{"test":"data"}'

# Should work
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your_webhook_secret" \
  -d '{"type":"text","sender":"test","chat":"test","content":"test"}'
```

### Test Receiver API Key

```bash
# Should return 401 Unauthorized
curl -X POST http://localhost:3000/send-message \
  -H "Content-Type: application/json" \
  -d '{"chat":"test","type":"text","content":"test"}'

# Should work
curl -X POST http://localhost:3000/send-message \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_receiver_api_key" \
  -d '{"chat":"test","type":"text","content":"test"}'
```

---

## 📋 Pre-Deployment Checklist

- [ ] Generated all secrets with script
- [ ] Generated `APP_KEY` with `php artisan key:generate`
- [ ] Copied secrets to all `.env` files
- [ ] Verified `WEBHOOK_SECRET` matches in backend and receiver
- [ ] Verified `RECEIVER_API_KEY` matches in backend and receiver
- [ ] Verified `REVERB_APP_KEY` matches in backend and frontend
- [ ] Set `APP_ENV=production` in backend
- [ ] Set `APP_DEBUG=false` in backend
- [ ] Set `NODE_ENV=production` in receiver
- [ ] Updated `CORS_ALLOWED_ORIGINS` with actual domain
- [ ] Backed up all secrets in password manager
- [ ] Tested webhook authentication
- [ ] Tested receiver API key authentication
- [ ] Never committed `.env` files to Git

---

## 📚 Related Documentation

- [QUICK_START_SECURITY.md](QUICK_START_SECURITY.md) - 5-minute setup
- [PRODUCTION_DEPLOYMENT_GUIDE.md](PRODUCTION_DEPLOYMENT_GUIDE.md) - Complete deployment
- [SECURITY_FIXES_SUMMARY.md](SECURITY_FIXES_SUMMARY.md) - Security audit
- [SECURITY_IMPLEMENTATION_COMPLETE.md](SECURITY_IMPLEMENTATION_COMPLETE.md) - Implementation summary

---

**Last Updated**: 2025-01-15  
**Version**: 1.0  
**Status**: Production Ready ✅
