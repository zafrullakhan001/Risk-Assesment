# MCP SharePoint Catalog Integration

## Testing the Integration

### 1. Test API Endpoint Directly

First, verify the PHP API is working:

```powershell
# Windows PowerShell - Get stats
$headers = @{
    "X-API-Key" = "your-api-key-here"
}
Invoke-RestMethod -Uri "http://localhost/api/mcp-catalog-search.php?action=stats" -Headers $headers
```

```bash
# Linux/Mac - Get stats
curl -H "X-API-Key: your-api-key-here" \
  "http://localhost/api/mcp-catalog-search.php?action=stats"
```

**Expected Output:**
```json
{
  "success": true,
  "total_sources": 1,
  "sources": [...]
}
```

### 2. Test MCP Server Standalone

Run the MCP server in standalone mode:

```bash
cd mcp-server
npm run build

# Set environment variables
export SHAREPOINT_CATALOG_API_URL="http://localhost/api/mcp-catalog-search.php"
export SHAREPOINT_CATALOG_API_KEY="your-api-key-here"

# Run the server
node dist/index.js
```

**Expected Output:**
```
SharePoint Catalog MCP server running on stdio
```

The server will wait for MCP protocol messages on stdin. Press Ctrl+C to exit.

### 3. Test All API Actions

Create a test script `test-api.ps1` (PowerShell):

```powershell
$apiKey = "your-api-key-here"
$baseUrl = "http://localhost/api/mcp-catalog-search.php"
$headers = @{ "X-API-Key" = $apiKey }

Write-Host "Testing MCP API Endpoints..." -ForegroundColor Cyan

# Test 1: Get stats
Write-Host "`n1. Testing stats..." -ForegroundColor Yellow
Invoke-RestMethod -Uri "$baseUrl?action=stats" -Headers $headers | ConvertTo-Json

# Test 2: List sources
Write-Host "`n2. Testing list_sources..." -ForegroundColor Yellow
Invoke-RestMethod -Uri "$baseUrl?action=list_sources" -Headers $headers | ConvertTo-Json

# Test 3: Search
Write-Host "`n3. Testing search..." -ForegroundColor Yellow
Invoke-RestMethod -Uri "$baseUrl?action=search&query=test&limit=5" -Headers $headers | ConvertTo-Json

# Test 4: List projects
Write-Host "`n4. Testing list_projects..." -ForegroundColor Yellow
Invoke-RestMethod -Uri "$baseUrl?action=list_projects&source=default&per_page=5" -Headers $headers | ConvertTo-Json

Write-Host "`nAll tests completed!" -ForegroundColor Green
```

Or for bash `test-api.sh`:

```bash
#!/bin/bash
API_KEY="your-api-key-here"
BASE_URL="http://localhost/api/mcp-catalog-search.php"

echo "Testing MCP API Endpoints..."

echo -e "\n1. Testing stats..."
curl -s -H "X-API-Key: $API_KEY" "$BASE_URL?action=stats" | jq

echo -e "\n2. Testing list_sources..."
curl -s -H "X-API-Key: $API_KEY" "$BASE_URL?action=list_sources" | jq

echo -e "\n3. Testing search..."
curl -s -H "X-API-Key: $API_KEY" "$BASE_URL?action=search&query=test&limit=5" | jq

echo -e "\n4. Testing list_projects..."
curl -s -H "X-API-Key: $API_KEY" "$BASE_URL?action=list_projects&source=default&per_page=5" | jq

echo -e "\nAll tests completed!"
```

### 4. Test in Cursor

Once configured and Cursor is restarted, test with these prompts:

#### Basic Tests:
```
1. "List all SharePoint catalog sources"
2. "Get statistics about the SharePoint catalog"
3. "Search the catalog for 'test'"
```

#### Advanced Tests:
```
4. "Find all projects with PDF files"
5. "Search for projects tagged 'priority'"
6. "Show me all available tags"
7. "Get details for the first project in the catalog"
```

### 5. Verify MCP Tools Are Available

Ask Claude:
```
What MCP tools do you have access to for SharePoint catalog?
```

You should see 7 tools listed:
- search_sharepoint_catalog
- list_sharepoint_projects
- get_sharepoint_project
- list_sharepoint_sources
- get_sharepoint_source
- search_sharepoint_tags
- get_sharepoint_catalog_stats

### 6. Check Cursor MCP Logs

**Windows:**
```
%APPDATA%\Cursor\logs\
```

**Mac:**
```
~/Library/Application Support/Cursor/logs/
```

**Linux:**
```
~/.config/Cursor/logs/
```

Look for files like `mcp-*.log` or `window*.log` for MCP-related messages.

## Expected Behavior

### Successful API Call
```json
{
  "success": true,
  "query": "test",
  "results": [...],
  "result_count": 5
}
```

### Authentication Error
```json
{
  "success": false,
  "error": "Invalid or missing API key"
}
```

### Not Found Error
```json
{
  "success": false,
  "error": "Source not found: invalid-source"
}
```

## Debugging Tips

### Enable Verbose Logging

Add logging to the MCP server (`src/index.ts`):

```typescript
console.error(`[MCP] Tool called: ${name}`);
console.error(`[MCP] Arguments:`, JSON.stringify(args, null, 2));
console.error(`[MCP] API URL: ${config.apiUrl}`);
```

Rebuild and restart:
```bash
npm run build
# Restart Cursor
```

### Check PHP Errors

Enable error reporting in `public/api/mcp-catalog-search.php`:

```php
// Add at the top after <?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

View errors in Apache error log:
```
C:\xampp\apache\logs\error.log
```

### Test Database Connection

```sql
-- Check if API key exists
SELECT * FROM settings WHERE key = 'mcp_api_key';

-- Check catalog data
SELECT COUNT(*) FROM sharepoint_items;
SELECT COUNT(DISTINCT source_key) FROM sharepoint_items;
SELECT COUNT(DISTINCT project_name) FROM sharepoint_items;
```

### Network Troubleshooting

```bash
# Test if API is reachable
curl -v http://localhost/api/mcp-catalog-search.php?action=stats

# Check if Apache is listening
netstat -an | findstr :80

# Test DNS resolution
ping localhost
```

## Performance Testing

Test with larger datasets:

```bash
# Search with many results
curl -H "X-API-Key: $API_KEY" \
  "$BASE_URL?action=search&query=test&limit=100"

# List many projects
curl -H "X-API-Key: $API_KEY" \
  "$BASE_URL?action=list_projects&per_page=100"
```

Monitor response times and adjust limits if needed.

## Security Testing

### Test Invalid API Keys
```bash
# Should return 401
curl -H "X-API-Key: wrong-key" \
  "http://localhost/api/mcp-catalog-search.php?action=stats"
```

### Test Missing API Key
```bash
# Should return 401
curl "http://localhost/api/mcp-catalog-search.php?action=stats"
```

### Test Invalid Actions
```bash
# Should return error
curl -H "X-API-Key: $API_KEY" \
  "$BASE_URL?action=invalid_action"
```

## Continuous Monitoring

Create a health check script `health-check.ps1`:

```powershell
$apiKey = "your-api-key-here"
$baseUrl = "http://localhost/api/mcp-catalog-search.php"

try {
    $response = Invoke-RestMethod -Uri "$baseUrl?action=stats" -Headers @{"X-API-Key"=$apiKey}
    if ($response.success) {
        Write-Host "✓ MCP API is healthy" -ForegroundColor Green
        Write-Host "  Sources: $($response.total_sources)" -ForegroundColor Gray
    } else {
        Write-Host "✗ MCP API returned error: $($response.error)" -ForegroundColor Red
    }
} catch {
    Write-Host "✗ MCP API is unreachable: $($_.Exception.Message)" -ForegroundColor Red
}
```

Run periodically to ensure the service is available.

---

**All tests passing?** Your MCP integration is ready to use! 🎉
