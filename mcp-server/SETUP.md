# SharePoint Catalog MCP Integration - Setup Guide

## 🚀 Quick Setup Steps

Follow these steps to enable AI assistants to search your SharePoint catalog:

### Step 1: Generate API Key

**Windows PowerShell:**
```powershell
# Generate a secure 32-character API key
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | % {[char]$_})
```

**Linux/Mac/Git Bash:**
```bash
# Generate a secure base64 API key
openssl rand -base64 32
```

**Save this key** - you'll need it in Steps 2 and 5!

### Step 2: Add API Key to Database

Open your Risk Register database and run:

```sql
INSERT INTO settings (key, value) 
VALUES ('mcp_api_key', 'YOUR-GENERATED-KEY-HERE')
ON CONFLICT(key) DO UPDATE SET value = excluded.value;
```

**How to run this SQL:**

- **DB Browser for SQLite**: Open `database/risk_assessment.sqlite`, go to "Execute SQL" tab, paste and run
- **Command line**: `sqlite3 database/risk_assessment.sqlite "INSERT INTO settings..."`
- **phpLiteAdmin**: Use the SQL tab if you have it installed

### Step 3: Install Dependencies

```bash
cd C:\xampp\htdocs\RiskRegister\mcp-server
npm install
npm run build
```

### Step 4: Create Environment File

```bash
cd C:\xampp\htdocs\RiskRegister\mcp-server
copy .env.example .env
```

Edit `.env` and set your values:
```env
SHAREPOINT_CATALOG_API_URL=http://localhost/api/mcp-catalog-search.php
SHAREPOINT_CATALOG_API_KEY=YOUR-GENERATED-KEY-HERE
```

### Step 5: Configure Cursor

**Option A: Project-level (Recommended)**

Create or edit `.cursor/mcp_settings.json` in your workspace:

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
        "SHAREPOINT_CATALOG_API_KEY": "YOUR-GENERATED-KEY-HERE"
      }
    }
  }
}
```

**Option B: Global Cursor settings**

1. Open Cursor Settings (Ctrl+,)
2. Search for "MCP"
3. Click "Edit in settings.json"
4. Add the configuration above

**Important**: Update the path in `args` to match your actual installation directory!

### Step 6: Restart Cursor

Close and restart Cursor completely to load the MCP server.

### Step 7: Test It!

Ask Claude:
```
Search the SharePoint catalog for projects containing "test"
```

If it works, you'll see search results from your catalog!

## ✅ Verification Checklist

- [ ] API key generated
- [ ] API key added to database settings table
- [ ] Node modules installed (`npm install`)
- [ ] TypeScript compiled (`npm run build`)
- [ ] `.env` file created with correct values
- [ ] Cursor MCP settings configured
- [ ] Path in MCP settings is correct (absolute path with backslashes escaped)
- [ ] Cursor restarted
- [ ] XAMPP/Apache is running
- [ ] Test query works

## 🐛 Common Issues

### "MCP server not found"
- Check the path in MCP settings is absolute and correct
- Verify `dist/index.js` exists (run `npm run build`)
- Restart Cursor completely

### "Invalid or missing API key"
- Verify API key in database: `SELECT value FROM settings WHERE key = 'mcp_api_key';`
- Check `.env` file has correct API key
- Ensure keys match exactly (no extra spaces)

### "Connection refused" or "API request failed"
- Verify XAMPP is running
- Check Apache is running on port 80
- Test API directly: `curl -H "X-API-Key: YOUR-KEY" http://localhost/api/mcp-catalog-search.php?action=stats`
- Check PHP error logs in XAMPP

### "No tools available"
- Check Cursor MCP logs for errors
- Verify TypeScript compiled successfully
- Test server manually: `node dist/index.js` (should show "SharePoint Catalog MCP server running")

## 📚 Next Steps

Once working, try these commands:

- "List all SharePoint catalog sources"
- "Show me statistics about the catalog"
- "Find projects with PDF files"
- "Get details for project 'ProjectName'"
- "Search for projects modified by John Smith"
- "What tags are available?"

## 🔐 Security Notes

- Keep your API key secret
- Don't commit `.env` to version control (already in `.gitignore`)
- In production, use HTTPS for API URL
- Consider IP restrictions for the API endpoint

## 📖 Full Documentation

See `README.md` for complete documentation, advanced usage, and troubleshooting.

---

**Need Help?** Check the troubleshooting section in README.md or review Cursor's MCP logs.
