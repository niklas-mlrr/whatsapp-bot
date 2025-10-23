# 1) Setup
$BASE = 'http://127.0.0.1:8000'
$HEAD = @{
  Accept = 'application/json'
  Authorization = 'Bearer 15|SHuBLN5WW7Ff76tZAWgartKq6NdnXSNtDT8AhRg8b7177723'
}

# 2) List chats (to pick a group id)
irm "$BASE/api/chats" -Headers $HEAD | ConvertTo-Json -Depth 6

# 3) Pick a group chat id (first group)
$gid = ((irm "$BASE/api/chats" -Headers $HEAD).data | Where-Object { $_.is_group -eq 1 -or $_.is_group -eq $true } | Select-Object -First 1).id
Write-Host "Selected group chat id: $gid"

# 4) Fetch latest messages for that group
irm "$BASE/api/chats/$gid/messages/latest?limit=10" -Headers $HEAD | ConvertTo-Json -Depth 12