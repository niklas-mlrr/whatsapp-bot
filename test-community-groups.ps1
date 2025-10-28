# Test script for community group message decryption
# This script checks if the receiver is properly handling community group messages

Write-Host "=== WhatsApp Community Group Decryption Test ===" -ForegroundColor Cyan
Write-Host ""

# Check if receiver is running
Write-Host "1. Checking if receiver is running..." -ForegroundColor Yellow
$receiverStatus = pm2 list | Select-String "node-server"
if ($receiverStatus) {
    Write-Host "   ✓ Receiver is running" -ForegroundColor Green
} else {
    Write-Host "   ✗ Receiver is NOT running" -ForegroundColor Red
    Write-Host "   Run: pm2 start receiver/index.js --name node-server" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "2. Checking recent logs for decryption issues..." -ForegroundColor Yellow
Write-Host ""

# Show last 50 lines of logs, filtering for decryption-related messages
pm2 logs node-server --lines 50 --nostream | Select-String -Pattern "decrypt|SessionError|PreKeyError|retry receipt|community|senderLid" -Context 0,2

Write-Host ""
Write-Host "=== Test Instructions ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "To test the fix:" -ForegroundColor White
Write-Host "1. Send a message in a WhatsApp community sub-group" -ForegroundColor White
Write-Host "2. Watch the logs: pm2 logs node-server --lines 100" -ForegroundColor White
Write-Host "3. Look for these log messages:" -ForegroundColor White
Write-Host "   - 'Skipping group message with no content - decryption failed (requesting retry)'" -ForegroundColor Gray
Write-Host "   - 'Sent retry receipt for failed decryption'" -ForegroundColor Gray
Write-Host "   - 'New message received' (after retry succeeds)" -ForegroundColor Gray
Write-Host ""
Write-Host "Expected behavior:" -ForegroundColor White
Write-Host "   - First message may show decryption error" -ForegroundColor Gray
Write-Host "   - Retry receipt is sent automatically" -ForegroundColor Gray
Write-Host "   - Message arrives successfully within 1-5 seconds" -ForegroundColor Gray
Write-Host "   - Subsequent messages decrypt immediately" -ForegroundColor Gray
Write-Host ""
Write-Host "If messages still fail after 10 seconds, check:" -ForegroundColor Yellow
Write-Host "   - Receiver auth state is valid (not logged out)" -ForegroundColor Gray
Write-Host "   - No other WhatsApp Web sessions are active" -ForegroundColor Gray
Write-Host "   - Network connection is stable" -ForegroundColor Gray
Write-Host ""
