# SharePoint Catalog MCP Integration - Installation Script
# Run this in PowerShell from the mcp-server directory

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "SharePoint Catalog MCP Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check Node.js
Write-Host "1. Checking Node.js..." -ForegroundColor Yellow
$nodeVersion = node --version 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✓ Node.js found: $nodeVersion" -ForegroundColor Green
    
    # Extract version number and check if >= 18
    $versionNumber = [int]($nodeVersion -replace 'v(\d+)\..*', '$1')
    if ($versionNumber -lt 18) {
        Write-Host "   ✗ Node.js version 18 or higher required!" -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "   ✗ Node.js not found! Please install Node.js 18+" -ForegroundColor Red
    Write-Host "     Download from: https://nodejs.org/" -ForegroundColor Gray
    exit 1
}

# Check npm
Write-Host "2. Checking npm..." -ForegroundColor Yellow
$npmVersion = npm --version 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✓ npm found: $npmVersion" -ForegroundColor Green
} else {
    Write-Host "   ✗ npm not found!" -ForegroundColor Red
    exit 1
}

# Generate API Key
Write-Host ""
Write-Host "3. Generating API Key..." -ForegroundColor Yellow
$apiKey = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
Write-Host "   ✓ Generated API Key:" -ForegroundColor Green
Write-Host "     $apiKey" -ForegroundColor Cyan
Write-Host ""
Write-Host "   IMPORTANT: Save this key! You need to:" -ForegroundColor Yellow
Write-Host "   1. Add it to your database settings table" -ForegroundColor Gray
Write-Host "   2. Use it in your .env file" -ForegroundColor Gray
Write-Host "   3. Use it in Cursor MCP settings" -ForegroundColor Gray
Write-Host ""

# SQL to add to database
$sqlCommand = "INSERT INTO settings (key, value) VALUES ('mcp_api_key', '$apiKey') ON CONFLICT(key) DO UPDATE SET value = excluded.value;"
Write-Host "   Run this SQL in your database:" -ForegroundColor Yellow
Write-Host "   $sqlCommand" -ForegroundColor White
Write-Host ""
Read-Host "   Press Enter when you've added the API key to the database"

# Install dependencies
Write-Host ""
Write-Host "4. Installing dependencies..." -ForegroundColor Yellow
npm install
if ($LASTEXITCODE -ne 0) {
    Write-Host "   ✗ npm install failed!" -ForegroundColor Red
    exit 1
}
Write-Host "   ✓ Dependencies installed" -ForegroundColor Green

# Build TypeScript
Write-Host ""
Write-Host "5. Building TypeScript..." -ForegroundColor Yellow
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "   ✗ Build failed!" -ForegroundColor Red
    exit 1
}
Write-Host "   ✓ TypeScript compiled successfully" -ForegroundColor Green

# Create .env file
Write-Host ""
Write-Host "6. Creating .env file..." -ForegroundColor Yellow
if (Test-Path ".env") {
    Write-Host "   ! .env already exists, skipping..." -ForegroundColor Yellow
} else {
    $envContent = @"
# SharePoint Catalog MCP Server Configuration

# Required: API endpoint URL (adjust if your server runs on a different port/domain)
SHAREPOINT_CATALOG_API_URL=http://localhost/api/mcp-catalog-search.php

# Required: API key for authentication
SHAREPOINT_CATALOG_API_KEY=$apiKey
"@
    $envContent | Out-File -FilePath ".env" -Encoding UTF8
    Write-Host "   ✓ .env file created" -ForegroundColor Green
}

# Get absolute path
$absolutePath = (Get-Location).Path
$indexPath = Join-Path $absolutePath "dist\index.js"

# Create Cursor MCP settings
Write-Host ""
Write-Host "7. Generating Cursor MCP configuration..." -ForegroundColor Yellow
$mcpConfig = @"
{
  "mcpServers": {
    "sharepoint-catalog": {
      "command": "node",
      "args": [
        "$($indexPath -replace '\\', '\\\\')"
      ],
      "env": {
        "SHAREPOINT_CATALOG_API_URL": "http://localhost/api/mcp-catalog-search.php",
        "SHAREPOINT_CATALOG_API_KEY": "$apiKey"
      }
    }
  }
}
"@

Write-Host "   ✓ Configuration generated" -ForegroundColor Green
Write-Host ""
Write-Host "   Add this to your Cursor settings:" -ForegroundColor Yellow
Write-Host "   (.cursor/mcp_settings.json or global settings)" -ForegroundColor Gray
Write-Host ""
Write-Host $mcpConfig -ForegroundColor White
Write-Host ""

# Save to file
$mcpConfig | Out-File -FilePath "cursor-mcp-config.json" -Encoding UTF8
Write-Host "   ✓ Configuration saved to: cursor-mcp-config.json" -ForegroundColor Green

# Test API
Write-Host ""
Write-Host "8. Testing API connection..." -ForegroundColor Yellow
try {
    $headers = @{ "X-API-Key" = $apiKey }
    $response = Invoke-RestMethod -Uri "http://localhost/api/mcp-catalog-search.php?action=stats" -Headers $headers -ErrorAction Stop
    
    if ($response.success) {
        Write-Host "   ✓ API connection successful!" -ForegroundColor Green
        Write-Host "     Total sources: $($response.total_sources)" -ForegroundColor Gray
    } else {
        Write-Host "   ✗ API returned error: $($response.error)" -ForegroundColor Red
    }
} catch {
    Write-Host "   ✗ Could not connect to API" -ForegroundColor Red
    Write-Host "     Make sure XAMPP is running and Apache is started" -ForegroundColor Yellow
    Write-Host "     Error: $($_.Exception.Message)" -ForegroundColor Gray
}

# Summary
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Setup Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Copy the configuration from cursor-mcp-config.json" -ForegroundColor White
Write-Host "   to your Cursor MCP settings" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Restart Cursor completely" -ForegroundColor White
Write-Host ""
Write-Host "3. Test by asking Claude:" -ForegroundColor White
Write-Host "   'List all SharePoint catalog sources'" -ForegroundColor Gray
Write-Host ""
Write-Host "Documentation:" -ForegroundColor Yellow
Write-Host "- Full docs: README.md" -ForegroundColor White
Write-Host "- Setup guide: SETUP.md" -ForegroundColor White
Write-Host "- Testing: TESTING.md" -ForegroundColor White
Write-Host ""
Write-Host "Your API key: $apiKey" -ForegroundColor Cyan
Write-Host "(Also saved in .env file)" -ForegroundColor Gray
Write-Host ""
