#!/bin/bash

# WhatsApp Bot - Security Secrets Generator
# This script generates secure secrets for production deployment

echo "=========================================="
echo "WhatsApp Bot - Security Secrets Generator"
echo "=========================================="
echo ""

# Check if openssl is available
if ! command -v openssl &> /dev/null; then
    echo "ERROR: openssl is not installed. Please install it first."
    exit 1
fi

echo "Generating secure secrets..."
echo ""

# Generate Webhook Secret
WEBHOOK_SECRET=$(openssl rand -hex 32)
echo "✓ Generated WEBHOOK_SECRET (64 characters)"

# Generate Receiver API Key
RECEIVER_API_KEY=$(openssl rand -hex 32)
echo "✓ Generated RECEIVER_API_KEY (64 characters)"

# Generate a random strong database password
DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32)
echo "✓ Generated DB_PASSWORD (32 characters)"

echo ""
echo "=========================================="
echo "IMPORTANT: Copy these secrets to your .env files"
echo "=========================================="
echo ""

echo "For Backend (.env):"
echo "-------------------"
echo "WEBHOOK_SECRET=${WEBHOOK_SECRET}"
echo "RECEIVER_API_KEY=${RECEIVER_API_KEY}"
echo "DB_PASSWORD=${DB_PASSWORD}"
echo ""

echo "For Receiver (.env):"
echo "--------------------"
echo "WEBHOOK_SECRET=${WEBHOOK_SECRET}"
echo "RECEIVER_API_KEY=${RECEIVER_API_KEY}"
echo ""

echo "=========================================="
echo "SECURITY NOTES:"
echo "=========================================="
echo "1. NEVER commit these secrets to version control"
echo "2. Store them securely in a password manager"
echo "3. Use DIFFERENT secrets for development and production"
echo "4. Rotate these secrets every 90 days"
echo "5. The WEBHOOK_SECRET and RECEIVER_API_KEY must match"
echo "   between backend and receiver"
echo ""

# Optionally save to a file (with warning)
read -p "Save secrets to secrets.txt? (NOT RECOMMENDED for production) [y/N]: " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    cat > secrets.txt << EOF
# WhatsApp Bot Secrets - Generated $(date)
# WARNING: Delete this file after copying secrets to .env files!

Backend .env:
WEBHOOK_SECRET=${WEBHOOK_SECRET}
RECEIVER_API_KEY=${RECEIVER_API_KEY}
DB_PASSWORD=${DB_PASSWORD}

Receiver .env:
WEBHOOK_SECRET=${WEBHOOK_SECRET}
RECEIVER_API_KEY=${RECEIVER_API_KEY}

# IMPORTANT: Delete this file immediately after use!
# Run: rm secrets.txt
EOF
    echo "✓ Secrets saved to secrets.txt"
    echo "⚠️  WARNING: Delete this file after copying secrets!"
    echo "   Run: rm secrets.txt"
else
    echo "✓ Secrets not saved to file (recommended)"
fi

echo ""
echo "Next steps:"
echo "1. Copy the secrets above to your .env files"
echo "2. Generate Laravel APP_KEY: cd backend && php artisan key:generate"
echo "3. Review PRODUCTION_DEPLOYMENT_GUIDE.md for complete setup"
echo ""
