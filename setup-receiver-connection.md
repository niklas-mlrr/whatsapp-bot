# Quick Fix: Backend Cannot Send Messages to Receiver

## Problem
The backend times out when trying to send messages because `RECEIVER_URL` and `RECEIVER_API_KEY` are not configured.

## Solution

### 1. Add to Backend `.env` file

Add these lines to `backend/.env`:

```env
# Receiver Configuration
RECEIVER_URL=http://127.0.0.1:3000
RECEIVER_API_KEY=your-secret-key-here
```

### 2. Add to Receiver `.env` file

Add this line to `receiver/.env`:

```env
# Security - Must match backend
RECEIVER_API_KEY=your-secret-key-here
```

**Important:** The `RECEIVER_API_KEY` must be the **same** in both files!

### 3. Generate a Secure API Key

For development, you can use any string. For production, use a secure random string:

**PowerShell (Windows):**
```powershell
# Generate a random 64-character hex string
-join ((48..57) + (97..102) | Get-Random -Count 64 | ForEach-Object {[char]$_})
```

**Or use a simple string for development:**
```env
RECEIVER_API_KEY=dev-secret-key-12345
```

### 4. Restart Services

After updating the `.env` files:

1. **Restart the backend** (if using `php artisan serve`, stop and start it again)
2. **Restart the receiver** (stop with Ctrl+C and run `npm start` again)

### 5. Test

Try sending a message from the frontend. It should now work!

## Quick Setup Commands

```powershell
# 1. Navigate to backend directory
cd "d:\z - WhatsAppBot Abiplanung REROLL\backend"

# 2. Add to .env (replace YOUR_KEY with your generated key)
Add-Content .env "`nRECEIVER_URL=http://127.0.0.1:3000"
Add-Content .env "RECEIVER_API_KEY=dev-secret-key-12345"

# 3. Navigate to receiver directory
cd "..\receiver"

# 4. Add to .env
Add-Content .env "`nRECEIVER_API_KEY=dev-secret-key-12345"

# 5. Restart receiver
npm start
```

## Verification

Check if the configuration is correct:

```powershell
# Check backend .env
Get-Content "backend\.env" | Select-String "RECEIVER"

# Check receiver .env
Get-Content "receiver\.env" | Select-String "RECEIVER_API_KEY"
```

Both should show the same `RECEIVER_API_KEY` value.
