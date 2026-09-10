# MCP Catalog Search API

This API endpoint provides programmatic access to the SharePoint catalog for MCP (Model Context Protocol) servers and AI assistants.

## Endpoint

```
GET /api/mcp-catalog-search.php
```

## Authentication

All requests require an API key via the `X-API-Key` header:

```
X-API-Key: your-api-key-here
```

## Setup (Administrator Only)

### 1. Generate API Key

**PowerShell:**
```powershell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
```

**Linux/Mac:**
```bash
openssl rand -base64 32
```

### 2. Add to Database

```sql
INSERT INTO settings (key, value) 
VALUES ('mcp_api_key', 'your-generated-key-here')
ON CONFLICT(key) DO UPDATE SET value = excluded.value;
```

## Available Actions

### 1. Search Catalog

```
GET /api/mcp-catalog-search.php?action=search&query=test&limit=25&source=default
```

**Parameters:**
- `query` (required): Search query string
- `source` (optional): Specific catalog source key
- `limit` (optional): Max results (1-100, default: 25)

### 2. List Projects

```
GET /api/mcp-catalog-search.php?action=list_projects&source=default&page=1&per_page=25
```

**Parameters:**
- `source` (optional): Catalog source key (default: "default")
- `query` (optional): Filter query
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Results per page (1-100, default: 25)

### 3. Get Project

```
GET /api/mcp-catalog-search.php?action=get_project&project=ProjectName&source=default
```

**Parameters:**
- `project` (required): Project name
- `source` (optional): Catalog source key (default: "default")

### 4. List Sources

```
GET /api/mcp-catalog-search.php?action=list_sources
```

Returns all available catalog sources.

### 5. Get Source

```
GET /api/mcp-catalog-search.php?action=get_source&source=default
```

**Parameters:**
- `source` (required): Source key

### 6. Search Tags

```
GET /api/mcp-catalog-search.php?action=search_tags&query=priority&limit=50
```

**Parameters:**
- `query` (optional): Filter tags by name
- `limit` (optional): Max results (1-100, default: 50)

### 7. Get Statistics

```
GET /api/mcp-catalog-search.php?action=stats
```

Returns overview statistics for all catalog sources.

## Response Format

### Success Response

```json
{
  "success": true,
  "...": "action-specific data"
}
```

### Error Response

```json
{
  "success": false,
  "error": "Error message"
}
```

## Testing

### Using curl (Command Line)

```bash
curl -H "X-API-Key: your-api-key" \
  "http://localhost/api/mcp-catalog-search.php?action=stats"
```

### Using PowerShell

```powershell
$headers = @{ "X-API-Key" = "your-api-key" }
Invoke-RestMethod -Uri "http://localhost/api/mcp-catalog-search.php?action=stats" -Headers $headers
```

## Security

- API key is required for all requests
- Only read operations are allowed (no write/delete)
- Keys are stored encrypted in the database
- Respects archive/visibility rules
- CORS headers included for MCP server access
- Use HTTPS in production

## CORS

The endpoint includes CORS headers to allow access from MCP servers:
- `Access-Control-Allow-Origin`: Reflects the origin header
- `Access-Control-Allow-Methods`: GET, POST, OPTIONS
- `Access-Control-Allow-Headers`: Content-Type, X-API-Key

## Error Codes

- `401` - Invalid or missing API key
- `404` - Resource not found (project, source, etc.)
- `400` - Bad request (missing required parameters)
- `405` - Method not allowed (only GET/POST accepted)
- `500` - Server error

## Rate Limiting

No rate limiting is currently implemented. Consider adding rate limiting in production environments with high traffic.

## MCP Server Integration

See `../mcp-server/README.md` for:
- MCP server setup
- Cursor configuration
- Usage examples
- Troubleshooting

## Related Files

- **API Endpoint**: `public/api/mcp-catalog-search.php`
- **MCP Server**: `mcp-server/src/index.ts`
- **Setup Guide**: `mcp-server/SETUP.md`
- **Testing Guide**: `mcp-server/TESTING.md`
- **Help Topic**: Available at Help & About → SharePoint → MCP integration

## Support

For issues:
1. Check the troubleshooting section in `../mcp-server/SETUP.md`
2. Verify API key is set in database: `SELECT value FROM settings WHERE key = 'mcp_api_key';`
3. Check Apache error logs: `C:\xampp\apache\logs\error.log`
4. Test endpoint directly with curl/PowerShell
5. Review the help topic in the application

---

**Last Updated**: September 2026  
**API Version**: 1.0.0
