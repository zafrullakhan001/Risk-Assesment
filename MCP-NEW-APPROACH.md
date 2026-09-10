# MCP Integration - New Approach (LinkNest-Style)

## 🎯 Summary

After analyzing LinkNest's implementation, I'm adopting their simpler approach:

**❌ OLD APPROACH (Complex):**
- External Node.js/TypeScript MCP server
- Manual script installation
- Environment variable configuration
- Separate API key in database settings
- Complex troubleshooting

**✅ NEW APPROACH (Simple - LinkNest-style):**
- **Pure PHP** - No Node.js required!
- **Admin UI** for token management
- **JSON-RPC** MCP endpoint with Bearer authentication
- **Database tokens** with user binding and scopes
- **No external dependencies**

## 📊 Progress

### ✅ Completed
1. **Database Schema** - Added `mcp_tokens` table to schema.sqlite.sql
2. **Admin API** - Created `/api/mcp-tokens.php` for token management (GET, POST, DELETE)

### 🚧 In Progress
3. **JSON-RPC MCP Endpoint** - Creating `/api/mcp.php` with Bearer authentication
4. **Admin UI Page** - Creating admin interface for managing tokens
5. **Documentation Update** - Updating help with simpler instructions

## 🏗️ Architecture

```
┌─────────────────┐          ┌──────────────────┐          ┌─────────────────┐
│  AI Assistant   │  JSON-   │   PHP MCP        │   PDO    │  SQLite         │
│  (Claude, etc.) │  RPC     │   Endpoint       │          │  Database       │
│                 │ ───────> │  /api/mcp.php    │ ──────> │  mcp_tokens     │
│  Bearer ramcp_… │          │                  │          │  sharepoint_*   │
└─────────────────┘          └──────────────────┘          └─────────────────┘
                                       ▲
                                       │
                             ┌─────────┴─────────┐
                             │   Admin UI        │
                             │   /admin/mcp.php  │
                             │   Token Mgmt      │
                             └───────────────────┘
```

## 🔑 Key Differences

### LinkNest Pattern (Adopted)
- **Token Format**: `ramcp_` prefix (Risk Assessment MCP)
- **Scopes**: `read`, `write` (read always included)
- **Bearer Auth**: `Authorization: Bearer ramcp_…`
- **JSON-RPC 2.0**: Standard MCP protocol
- **Admin Management**: Web UI for creating/revoking tokens
- **User Binding**: Tokens bound to specific users with permissions

### Database Table: `mcp_tokens`
```sql
- id: Primary key
- user_id: User this token belongs to
- name: Descriptive name (e.g., "Claude Desktop", "Cursor IDE")
- token_hash: SHA-256 hash of full token
- token_prefix: Display prefix (ramcp_abc123…)
- scopes: Comma-separated (read,write)
- last_used_at: Timestamp of last use
- expires_at: Optional expiration timestamp
- created_at: Creation timestamp
- revoked_at: Revocation timestamp (NULL if active)
```

## 🛠️ MCP Tools Available

1. **search_sharepoint_catalog** - Full-text search with operators
2. **list_sharepoint_projects** - Paginated project listing
3. **get_sharepoint_project** - Detailed project with files
4. **list_sharepoint_sources** - All catalog sources
5. **get_sharepoint_source** - Source details
6. **search_sharepoint_tags** - Tag search
7. **get_sharepoint_catalog_stats** - Overview statistics

## 📝 Admin UI Features

The admin page (`/admin/mcp.php`) will provide:

- **List Tokens**: View all active and revoked tokens
- **Create Token**: Generate new MCP token
  - Name (description)
  - User binding (defaults to current admin)
  - Scopes (read, write)
  - Expiration (optional, in days)
- **Copy Token**: One-time display after creation
- **Revoke Token**: Disable a token
- **Last Used**: See when tokens were last accessed
- **Audit Log**: Track token creation/revocation

## 🚀 User Setup (Much Simpler!)

### For Administrators:
1. Go to **Admin → MCP / AI**
2. Click **Create Token**
3. Set name (e.g., "Cursor IDE")
4. Choose scopes (read for search, write if needed)
5. Optional: Set expiration
6. **Copy token** (shown once!)
7. Add to Cursor settings:

```json
{
  "mcpServers": {
    "risk-register": {
      "command": "curl",
      "args": [
        "-X", "POST",
        "-H", "Authorization: Bearer YOUR_TOKEN_HERE",
        "-H", "Content-Type: application/json",
        "-d", "@-",
        "http://localhost/api/mcp"
      ]
    }
  }
}
```

### For Users:
Just ask Claude/Cursor:
- "Search the SharePoint catalog for 'encore'"
- "List all catalog sources"
- "Find projects with PDF files"

## 🔒 Security

- ✅ **SHA-256 hashing** of tokens at rest
- ✅ **User binding** - tokens tied to specific users
- ✅ **Scopes** - read-only by default
- ✅ **Expiration** - optional automatic expiry
- ✅ **Revocation** - instant token disable
- ✅ **Audit logging** - all token operations logged
- ✅ **Admin-only** - token management requires admin access

## 📚 Help Documentation

Updated help topic at:
**Help & About → SharePoint → MCP integration for AI assistants**

New content will explain:
- What MCP integration does
- How to create tokens in Admin → MCP / AI
- How to configure Cursor
- How to use AI assistants with catalog

## 🎉 Benefits of New Approach

1. **No external dependencies** - Pure PHP, no Node.js
2. **Native admin UI** - Manage tokens in the app
3. **Simpler setup** - No scripts to run
4. **Better security** - User-bound tokens with scopes
5. **Easier maintenance** - All code in one place
6. **Audit trail** - Track all MCP activity
7. **User-friendly** - Web UI instead of CLI commands

## 📁 Files

### New Files:
- `database/schema.sqlite.sql` - Added mcp_tokens table
- `public/api/mcp-tokens.php` - Token management API
- `public/api/mcp.php` - JSON-RPC MCP endpoint (in progress)
- `public/admin/mcp.php` - Admin UI for tokens (in progress)

### Modified Files:
- `public/includes/help-topics.php` - Will update with new instructions

### Obsolete Files (from old approach):
- `mcp-server/` folder - No longer needed!
- All Node.js/TypeScript files - Not required anymore!

## ✨ Next Steps

1. Complete `/api/mcp.php` JSON-RPC endpoint
2. Create `/admin/mcp.php` admin UI page
3. Update help documentation
4. Test with Cursor
5. Remove old mcp-server folder

---

**This is a MUCH better approach!** ✅ Simple, secure, maintainable.
