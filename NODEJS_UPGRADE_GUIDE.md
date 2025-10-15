# Node.js Upgrade Guide for VPS

## Problem
Your VPS is running Node.js v18.19.1, but the project requires Node.js 20+ due to dependencies:
- `@whiskeysockets/baileys@6.7.20` requires Node.js >=20.0.0
- `laravel-echo@2.1.7` requires Node.js >=20
- `file-type@21.0.0` requires Node.js >=20
- `qified@0.5.0` requires Node.js >=20

## Solution: Upgrade Node.js to v20 LTS

### Option 1: Using NodeSource Repository (Recommended)

```bash
# Remove old Node.js version
sudo apt-get remove nodejs npm

# Add NodeSource repository for Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -

# Install Node.js 20
sudo apt-get install -y nodejs

# Verify installation
node --version  # Should show v20.x.x
npm --version   # Should show v10.x.x
```

### Option 2: Using NVM (Node Version Manager)

```bash
# Install NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash

# Reload shell configuration
source ~/.bashrc

# Install Node.js 20 LTS
nvm install 20

# Set Node.js 20 as default
nvm alias default 20

# Verify installation
node --version  # Should show v20.x.x
```

### After Upgrading Node.js

1. Navigate to your project and reinstall dependencies:

```bash
cd /var/www/html/whatsapp-bot/backend
rm -rf node_modules package-lock.json
npm install

cd /var/www/html/whatsapp-bot/receiver
rm -rf node_modules package-lock.json
npm install
```

2. Restart your services:

```bash
# Restart the receiver service
sudo systemctl restart whatsapp-receiver

# Restart any other Node.js services you have running
```

## Verification

After upgrading, verify everything works:

```bash
# Check Node.js version
node --version

# Test backend
cd /var/www/html/whatsapp-bot/backend
npm install

# Test receiver
cd /var/www/html/whatsapp-bot/receiver
npm install
```

## Notes

- Node.js 20 is the current LTS (Long Term Support) version
- The upgrade should not break any existing functionality
- If you have other Node.js applications on the server, test them after upgrading
