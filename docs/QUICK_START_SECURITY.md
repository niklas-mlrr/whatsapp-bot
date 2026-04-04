# Quick Start - Security Setup

## 🚀 5-Minute Security Setup

Follow these steps to secure your WhatsApp Bot before deployment:

---

## Step 1: Generate Secrets (2 minutes)

### Windows:
```powershell
.\generate-secrets.ps1
```

### Linux/Mac:
```bash
bash generate-secrets.sh
```

**Copy the generated secrets** - you'll need them in the next steps.

---

## Step 2: Configure Backend (1 minute)

Edit `backend/.env`:

```bash
# CRITICAL - Set to production
APP_ENV=production
APP_DEBUG=false

# Paste your generated secrets here
WEBHOOK_SECRET=your_generated_webhook_secret_here
RECEIVER_API_KEY=your_generated_receiver_api_key_here

# Replace with your actual domain
CORS_ALLOWED_ORIGINS=https://yourdomain.com

# Strong database password
DB_PASSWORD=your_generated_db_password_here
```

---

## Step 3: Configure Receiver (1 minute)

Edit `receiver/.env`:

```bash
NODE_ENV=production

# MUST match backend secrets
WEBHOOK_SECRET=your_generated_webhook_secret_here
RECEIVER_API_KEY=your_generated_receiver_api_key_here

# Backend webhook URL (usually localhost for internal communication)
BACKEND_API_URL=http://localhost:8000/api/whatsapp-webhook
```

---

## Step 4: Verify Routes Are Secured (10 seconds)

**Good news!** The routes file has been updated with environment-based security:

✅ Debug endpoints are automatically disabled when `APP_ENV=production`  
✅ Webhook endpoint requires authentication  
✅ Rate limiting is enabled on all routes  

**No action needed** - just ensure `APP_ENV=production` in your backend `.env`

---

## Step 5: Verify Configuration (30 seconds)

Run this checklist:

```bash
# Backend
cd backend
grep "APP_DEBUG=false" .env
grep "WEBHOOK_SECRET=" .env
grep "RECEIVER_API_KEY=" .env

# Receiver
cd ../receiver
grep "WEBHOOK_SECRET=" .env
grep "RECEIVER_API_KEY=" .env
```

All commands should return results (not empty).

---

## ✅ Done!

Your application is now secured with:
- ✅ Webhook authentication
- ✅ API key protection
- ✅ Input sanitization
- ✅ CORS restrictions
- ✅ Rate limiting
- ✅ Debug mode disabled

---

## 🔍 Quick Test

Test that security is working:

```bash
# This should return 401 Unauthorized
curl -X POST http://localhost:8000/api/whatsapp/webhook -d '{"test":"data"}'

# This should work (with correct secret)
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "X-Webhook-Secret: your_webhook_secret_here" \
  -H "Content-Type: application/json" \
  -d '{"type":"text","sender":"test","chat":"test","content":"test"}'
```

---

## 📚 Next Steps

For complete production deployment:

1. **Read**: [PRODUCTION_DEPLOYMENT_GUIDE.md](PRODUCTION_DEPLOYMENT_GUIDE.md)
2. **Review**: [SECURITY_FIXES_SUMMARY.md](SECURITY_FIXES_SUMMARY.md)
3. **Setup**: SSL/TLS, Firewall, Nginx, Database security

---

## ⚠️ CRITICAL REMINDERS

1. **NEVER** commit `.env` files to Git
2. **ALWAYS** use HTTPS in production
3. **ROTATE** secrets every 90 days
4. **BACKUP** your database regularly
5. **MONITOR** logs for suspicious activity

---

## 🆘 Troubleshooting

### "401 Unauthorized" on webhook
- Check `WEBHOOK_SECRET` matches in backend and receiver
- Verify receiver is sending the secret in `X-Webhook-Secret` header

### "503 Service Unavailable" on /send-message
- Check `RECEIVER_API_KEY` is set in receiver `.env`
- Verify backend is sending the API key

### CORS errors in browser
- Update `CORS_ALLOWED_ORIGINS` in backend `.env`
- Use your actual domain, not `*`

### Still seeing debug info
- Verify `APP_DEBUG=false` in backend `.env`
- Clear config cache: `php artisan config:clear && php artisan config:cache`

---

## 📞 Need Help?

- **Security Issues**: Review [SECURITY_FIXES_SUMMARY.md](SECURITY_FIXES_SUMMARY.md)
- **Deployment**: Follow [PRODUCTION_DEPLOYMENT_GUIDE.md](PRODUCTION_DEPLOYMENT_GUIDE.md)
- **Environment**: Check [ENV_EXAMPLE.md](ENV_EXAMPLE.md)

---

**Remember**: Security is not optional. Complete all steps before deploying!
