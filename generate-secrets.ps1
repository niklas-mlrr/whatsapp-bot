# WhatsApp Bot - Security Secrets Generator (PowerShell)
# This script generates secure secrets for production deployment

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "WhatsApp Bot - Security Secrets Generator" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Generating secure secrets..." -ForegroundColor Yellow
Write-Host ""

# Function to generate random hex string
function Get-RandomHex {
    param([int]$Length = 32)
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RNGCryptoServiceProvider]::Create()
    $rng.GetBytes($bytes)
    return [System.BitConverter]::ToString($bytes).Replace("-", "").ToLower()
}

# Function to generate random base64 string
function Get-RandomBase64 {
    param([int]$Length = 32)
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RNGCryptoServiceProvider]::Create()
    $rng.GetBytes($bytes)
    $base64 = [Convert]::ToBase64String($bytes)
    # Remove special characters and trim to length
    return $base64 -replace '[=+/]', '' | Select-Object -First $Length
}

# Generate secrets
$WEBHOOK_SECRET = Get-RandomHex -Length 32
Write-Host "[OK] Generated WEBHOOK_SECRET (64 characters)" -ForegroundColor Green

$RECEIVER_API_KEY = Get-RandomHex -Length 32
Write-Host "[OK] Generated RECEIVER_API_KEY (64 characters)" -ForegroundColor Green

$DB_PASSWORD = (Get-RandomBase64 -Length 32).Substring(0, 32)
Write-Host "[OK] Generated DB_PASSWORD (32 characters)" -ForegroundColor Green

Write-Host ""
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "IMPORTANT: Copy these secrets to your .env files" -ForegroundColor Yellow
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "For Backend (.env):" -ForegroundColor White
Write-Host "-------------------" -ForegroundColor White
Write-Host "WEBHOOK_SECRET=$WEBHOOK_SECRET" -ForegroundColor Green
Write-Host "RECEIVER_API_KEY=$RECEIVER_API_KEY" -ForegroundColor Green
Write-Host "DB_PASSWORD=$DB_PASSWORD" -ForegroundColor Green
Write-Host ""

Write-Host "For Receiver (.env):" -ForegroundColor White
Write-Host "--------------------" -ForegroundColor White
Write-Host "WEBHOOK_SECRET=$WEBHOOK_SECRET" -ForegroundColor Green
Write-Host "RECEIVER_API_KEY=$RECEIVER_API_KEY" -ForegroundColor Green
Write-Host ""

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "SECURITY NOTES:" -ForegroundColor Yellow
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "1. NEVER commit these secrets to version control"
Write-Host "2. Store them securely in a password manager"
Write-Host "3. Use DIFFERENT secrets for development and production"
Write-Host "4. Rotate these secrets every 90 days"
Write-Host "5. The WEBHOOK_SECRET and RECEIVER_API_KEY must match"
Write-Host "   between backend and receiver"
Write-Host ""

# Ask to save to file
$response = Read-Host "Save secrets to secrets.txt? (NOT RECOMMENDED for production) [y/N]"
if ($response -eq 'y' -or $response -eq 'Y') {
    $content = @"
# WhatsApp Bot Secrets - Generated $(Get-Date)
# WARNING: Delete this file after copying secrets to .env files!

Backend .env:
WEBHOOK_SECRET=$WEBHOOK_SECRET
RECEIVER_API_KEY=$RECEIVER_API_KEY
DB_PASSWORD=$DB_PASSWORD

Receiver .env:
WEBHOOK_SECRET=$WEBHOOK_SECRET
RECEIVER_API_KEY=$RECEIVER_API_KEY

# IMPORTANT: Delete this file immediately after use!
# Run: Remove-Item secrets.txt
"@
    
    $content | Out-File -FilePath "secrets.txt" -Encoding UTF8
    Write-Host "[OK] Secrets saved to secrets.txt" -ForegroundColor Green
    Write-Host "[WARNING] Delete this file after copying secrets!" -ForegroundColor Red
    Write-Host "   Run: Remove-Item secrets.txt" -ForegroundColor Red
} else {
    Write-Host "[OK] Secrets not saved to file (recommended)" -ForegroundColor Green
}

Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Copy the secrets above to your .env files"
Write-Host "2. Generate Laravel APP_KEY: cd backend; php artisan key:generate"
Write-Host "3. Review PRODUCTION_DEPLOYMENT_GUIDE.md for complete setup"
Write-Host ""

# Keep window open
Write-Host "Press any key to exit..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
