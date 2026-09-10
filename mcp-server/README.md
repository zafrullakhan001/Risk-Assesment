# SharePoint Catalog MCP Integration

This integration exposes your SharePoint catalog search functionality through the Model Context Protocol (MCP), allowing AI assistants like Claude to search and query your SharePoint projects programmatically.

## 🎯 Features

- **Full-text search** across projects, files, and folders
- **Advanced filtering** with operators (tag:, ext:, person:, etc.)
- **Project details** with nested file structures
- **Source management** to query multiple catalogs
- **Tag search** for organizational labels
- **Statistics** and overview data

## 📋 Architecture

```
┌─────────────────┐          ┌──────────────────┐          ┌─────────────────┐
│  AI Assistant   │  MCP     │   MCP Server     │   HTTP   │  PHP API        │
│  (Claude, etc.) │ <─────> │  (Node.js/TS)    │ <─────> │  (Risk Register)│
└─────────────────┘          └──────────────────┘          └─────────────────┘
                                                                      │
                                                            ┌─────────▼────────┐
                                                            │  SQLite Database │
                                                            │  (SharePoint     │
                                                            │   Catalog Data)  │
                                                            └──────────────────┘
```

## 🚀 Quick Start

### 1. Prerequisites

- Node.js 18+ installed
- XAMPP with PHP 8+ running
- Risk Register application installed
- SharePoint catalog data synced

### 2. Setup API Key

First, generate a secure API key:

```bash
# On Windows (PowerShell)
$apiKey = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
echo $apiKey

# On Linux/Mac
openssl rand -base64 32
```

Add the API key to your Risk Register settings:

```sql
-- Using SQLite command line or a GUI tool
INSERT INTO settings (key, value) 
VALUES ('mcp_api_key', 'your-generated-api-key-here')
ON CONFLICT(key) DO UPDATE SET value = excluded.value;
```

### 3. Install MCP Server

```bash
cd mcp-server
npm install
npm run build
```

### 4. Configure Environment

```bash
# Copy the example environment file
cp .env.example .env

# Edit .env and set your values:
# SHAREPOINT_CATALOG_API_URL=http://localhost/api/mcp-catalog-search.php
# SHAREPOINT_CATALOG_API_KEY=your-generated-api-key-here
```

### 5. Configure Cursor

Add to your Cursor settings (`.cursor/mcp_settings.json` or global settings):

```json
{
  "mcpServers": {
    "sharepoint-catalog": {
      "command": "node",
      "args": [
        "C:\\xampp\\htdocs\\RiskRegister\\mcp-server\\dist\\index.js"
      ],
      "env": {
        "SHAREPOINT_CATALOG_API_URL": "http://localhost/api/mcp-catalog-search.php",
        "SHAREPOINT_CATALOG_API_KEY": "your-api-key-here"
      }
    }
  }
}
```

**Note**: Adjust the path to match your installation directory.

### 6. Restart Cursor

Restart Cursor to load the new MCP server.

## 🛠️ Available Tools

### 1. `search_sharepoint_catalog`

Search the catalog for projects, files, and folders.

**Parameters:**
- `query` (required): Search query text
- `source` (optional): Specific catalog source key
- `limit` (optional): Max results (1-100, default: 25)

**Example:**
```
Search for "drawings" in the sharepoint catalog
```

**Operators:**
- `tag:priority` - Filter by tag
- `ext:pdf` - Filter by file extension
- `person:"Last, First"` - Filter by person
- `"exact phrase"` - Match exact phrase
- `-exclude` - Exclude term

### 2. `list_sharepoint_projects`

List projects with pagination and optional filtering.

**Parameters:**
- `source` (optional): Catalog source key (default: "default")
- `query` (optional): Filter query
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Results per page (1-100, default: 25)

### 3. `get_sharepoint_project`

Get detailed information about a specific project.

**Parameters:**
- `project` (required): Project name
- `source` (optional): Catalog source key (default: "default")

### 4. `list_sharepoint_sources`

List all available catalog sources.

### 5. `get_sharepoint_source`

Get details about a specific catalog source.

**Parameters:**
- `source` (required): Source key

### 6. `search_sharepoint_tags`

Search available project tags.

**Parameters:**
- `query` (optional): Filter tags by name
- `limit` (optional): Max results (1-100, default: 50)

### 7. `get_sharepoint_catalog_stats`

Get overview statistics for all catalog sources.

## 💬 Usage Examples

Once configured, you can ask Claude questions like:

- "Search the SharePoint catalog for project folders containing 'encore'"
- "Find all projects with PDF files tagged as 'priority'"
- "What projects were modified by John Smith in the last 30 days?"
- "Show me the file structure for the 'Project Alpha' folder"
- "List all available catalog sources"
- "What are the most used tags in the catalog?"
- "Give me stats on the SharePoint catalog"

## 🔒 Security

### API Key Management

- Store API keys securely in the database settings table
- Never commit API keys to version control
- Use environment variables for MCP server configuration
- Rotate API keys periodically

### Access Control

The MCP API endpoint (`mcp-catalog-search.php`):
- Requires API key authentication via `X-API-Key` header
- Only exposes read-only operations
- Respects archive/visibility rules from the database
- Includes CORS headers for allowed origins

### Best Practices

1. Generate strong random API keys (32+ characters)
2. Use HTTPS in production environments
3. Restrict API URL to localhost or trusted networks
4. Monitor API access logs
5. Set appropriate CORS origins in production

## 🔧 Development

### Build and Watch

```bash
npm run build       # Compile TypeScript
npm run watch       # Watch for changes
npm run dev         # Build and run
```

### Testing the API Directly

```bash
# Test with curl (replace with your API key)
curl -H "X-API-Key: your-api-key" \
  "http://localhost/api/mcp-catalog-search.php?action=stats"
```

### Debugging

Add `console.error()` statements in `src/index.ts` - they will appear in Cursor's MCP logs.

## 📝 API Endpoints

### Base URL
`http://localhost/api/mcp-catalog-search.php`

### Actions

| Action | Method | Parameters | Description |
|--------|--------|------------|-------------|
| `search` | GET | query, source?, limit? | Search catalog |
| `list_projects` | GET | source?, query?, page?, per_page? | List projects |
| `get_project` | GET | project, source? | Get project details |
| `list_sources` | GET | - | List all sources |
| `get_source` | GET | source | Get source details |
| `search_tags` | GET | query?, limit? | Search tags |
| `stats` | GET | - | Get statistics |

### Response Format

All responses follow this format:

```json
{
  "success": true,
  "...": "data specific to the action"
}
```

Error responses:

```json
{
  "success": false,
  "error": "Error message"
}
```

## 🐛 Troubleshooting

### MCP Server Not Starting

1. Check Node.js version: `node --version` (must be 18+)
2. Verify build completed: `npm run build`
3. Check for errors in Cursor's MCP logs
4. Ensure paths in Cursor settings are absolute

### API Authentication Errors

1. Verify API key is set in database settings
2. Check environment variable is correct in Cursor MCP config
3. Test API directly with curl (see Development section)
4. Ensure XAMPP/Apache is running

### No Results Returned

1. Verify SharePoint data is synced in the database
2. Check if projects are archived (use admin "Show archived")
3. Test queries in the web UI first
4. Check PHP error logs in XAMPP

### CORS Errors

If accessing from a different origin:
1. Review CORS headers in `mcp-catalog-search.php`
2. Add your origin to allowed origins
3. Check browser console for specific CORS errors

## 📦 Files Structure

```
mcp-server/
├── src/
│   └── index.ts              # Main MCP server implementation
├── dist/                     # Compiled JavaScript (generated)
├── package.json              # Node.js dependencies
├── tsconfig.json             # TypeScript configuration
├── .env.example              # Environment variables template
├── .gitignore                # Git ignore rules
└── README.md                 # This file

public/api/
└── mcp-catalog-search.php    # PHP API endpoint
```

## 🔄 Updates

When updating the MCP server:

1. Pull latest changes
2. Run `npm install` to update dependencies
3. Run `npm run build` to recompile
4. Restart Cursor to reload the MCP server

## 📄 License

This integration is part of the Risk Register application.

## 🤝 Support

For issues or questions:
1. Check this README's troubleshooting section
2. Review Cursor's MCP documentation
3. Check PHP error logs in XAMPP
4. Verify API endpoint is accessible

---

**Note**: This integration provides read-only access to your SharePoint catalog. Write operations (adding projects, modifying tags, etc.) should be done through the main Risk Register web interface.
