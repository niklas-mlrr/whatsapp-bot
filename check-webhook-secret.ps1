# PowerShell script to check webhook secret configuration

Write-Host "`n=== Webhook Secret Configuration Check ===" -ForegroundColor Cyan

# Check backend .env
$backendEnv = "backend\.env"
if (Test-Path $backendEnv) {
    Write-Host "`nBackend .env found:" -ForegroundColor Green
    $webhookSecret = Select-String -Path $backendEnv -Pattern "^WEBHOOK_SECRET=" | Select-Object -First 1
    
    if ($webhookSecret) {
        $secret = $webhookSecret.Line -replace "^WEBHOOK_SECRET=", ""
        if ($secret -and $secret -ne "" -and $secret -ne "your-webhook-secret-here") {
            Write-Host "  ✓ WEBHOOK_SECRET is configured" -ForegroundColor Green
            Write-Host "  Secret: $secret" -ForegroundColor Yellow
            
            # Set environment variable
            Write-Host "`nTo run load test, use:" -ForegroundColor Cyan
            Write-Host "`$env:WEBHOOK_SECRET = `"$secret`"" -ForegroundColor White
            Write-Host "node load-test-queue.js --count 10 --type text" -ForegroundColor White
        } else {
            Write-Host "  ✗ WEBHOOK_SECRET is not set or using default value" -ForegroundColor Red
            Write-Host "  Please run: .\generate-secrets.ps1" -ForegroundColor Yellow
        }
    } else {
        Write-Host "  ✗ WEBHOOK_SECRET not found in .env" -ForegroundColor Red
        Write-Host "  Please add: WEBHOOK_SECRET=<your-secret>" -ForegroundColor Yellow
    }
} else {
    Write-Host "`n✗ Backend .env file not found!" -ForegroundColor Red
    Write-Host "  Expected at: $backendEnv" -ForegroundColor Yellow
}

# Check receiver .env
$receiverEnv = "receiver\.env"
if (Test-Path $receiverEnv) {
    Write-Host "`nReceiver .env found:" -ForegroundColor Green
    $webhookSecret = Select-String -Path $receiverEnv -Pattern "^WEBHOOK_SECRET=" | Select-Object -First 1
    
    if ($webhookSecret) {
        $secret = $webhookSecret.Line -replace "^WEBHOOK_SECRET=", ""
        if ($secret -and $secret -ne "" -and $secret -ne "your-webhook-secret-here") {
            Write-Host "  ✓ WEBHOOK_SECRET is configured" -ForegroundColor Green
        } else {
            Write-Host "  ✗ WEBHOOK_SECRET is not set or using default value" -ForegroundColor Red
        }
    }
}

Write-Host "`n========================================`n" -ForegroundColor Cyan
