#!/bin/bash

# SharePoint Catalog MCP Integration - Installation Script
# Run this from the mcp-server directory

set -e

echo "========================================"
echo "SharePoint Catalog MCP Setup"
echo "========================================"
echo ""

# Check Node.js
echo "1. Checking Node.js..."
if command -v node &> /dev/null; then
    NODE_VERSION=$(node --version)
    echo "   ✓ Node.js found: $NODE_VERSION"
    
    # Check version >= 18
    MAJOR_VERSION=$(echo $NODE_VERSION | sed 's/v\([0-9]*\).*/\1/')
    if [ "$MAJOR_VERSION" -lt 18 ]; then
        echo "   ✗ Node.js version 18 or higher required!"
        exit 1
    fi
else
    echo "   ✗ Node.js not found! Please install Node.js 18+"
    echo "     Download from: https://nodejs.org/"
    exit 1
fi

# Check npm
echo "2. Checking npm..."
if command -v npm &> /dev/null; then
    NPM_VERSION=$(npm --version)
    echo "   ✓ npm found: $NPM_VERSION"
else
    echo "   ✗ npm not found!"
    exit 1
fi

# Generate API Key
echo ""
echo "3. Generating API Key..."
API_KEY=$(openssl rand -base64 32 | tr -d '/+=')
echo "   ✓ Generated API Key:"
echo "     $API_KEY"
echo ""
echo "   IMPORTANT: Save this key! You need to:"
echo "   1. Add it to your database settings table"
echo "   2. Use it in your .env file"
echo "   3. Use it in Cursor MCP settings"
echo ""

# SQL to add to database
SQL_COMMAND="INSERT INTO settings (key, value) VALUES ('mcp_api_key', '$API_KEY') ON CONFLICT(key) DO UPDATE SET value = excluded.value;"
echo "   Run this SQL in your database:"
echo "   $SQL_COMMAND"
echo ""
read -p "   Press Enter when you've added the API key to the database"

# Install dependencies
echo ""
echo "4. Installing dependencies..."
npm install
echo "   ✓ Dependencies installed"

# Build TypeScript
echo ""
echo "5. Building TypeScript..."
npm run build
echo "   ✓ TypeScript compiled successfully"

# Create .env file
echo ""
echo "6. Creating .env file..."
if [ -f ".env" ]; then
    echo "   ! .env already exists, skipping..."
else
    cat > .env << EOF
# SharePoint Catalog MCP Server Configuration

# Required: API endpoint URL (adjust if your server runs on a different port/domain)
SHAREPOINT_CATALOG_API_URL=http://localhost/api/mcp-catalog-search.php

# Required: API key for authentication
SHAREPOINT_CATALOG_API_KEY=$API_KEY
EOF
    echo "   ✓ .env file created"
fi

# Get absolute path
ABSOLUTE_PATH=$(pwd)
INDEX_PATH="$ABSOLUTE_PATH/dist/index.js"

# Create Cursor MCP settings
echo ""
echo "7. Generating Cursor MCP configuration..."
cat > cursor-mcp-config.json << EOF
{
  "mcpServers": {
    "sharepoint-catalog": {
      "command": "node",
      "args": [
        "$INDEX_PATH"
      ],
      "env": {
        "SHAREPOINT_CATALOG_API_URL": "http://localhost/api/mcp-catalog-search.php",
        "SHAREPOINT_CATALOG_API_KEY": "$API_KEY"
      }
    }
  }
}
EOF

echo "   ✓ Configuration generated"
echo ""
echo "   Add this to your Cursor settings:"
echo "   (.cursor/mcp_settings.json or global settings)"
echo ""
cat cursor-mcp-config.json
echo ""
echo "   ✓ Configuration saved to: cursor-mcp-config.json"

# Test API
echo ""
echo "8. Testing API connection..."
if command -v curl &> /dev/null; then
    RESPONSE=$(curl -s -H "X-API-Key: $API_KEY" "http://localhost/api/mcp-catalog-search.php?action=stats" || echo '{"success":false}')
    
    if echo "$RESPONSE" | grep -q '"success":true'; then
        echo "   ✓ API connection successful!"
        SOURCES=$(echo "$RESPONSE" | grep -o '"total_sources":[0-9]*' | cut -d: -f2)
        echo "     Total sources: $SOURCES"
    else
        echo "   ✗ API returned error"
    fi
else
    echo "   ! curl not found, skipping API test"
    echo "     Make sure XAMPP is running and Apache is started"
fi

# Summary
echo ""
echo "========================================"
echo "Setup Complete!"
echo "========================================"
echo ""
echo "Next steps:"
echo "1. Copy the configuration from cursor-mcp-config.json"
echo "   to your Cursor MCP settings"
echo ""
echo "2. Restart Cursor completely"
echo ""
echo "3. Test by asking Claude:"
echo "   'List all SharePoint catalog sources'"
echo ""
echo "Documentation:"
echo "- Full docs: README.md"
echo "- Setup guide: SETUP.md"
echo "- Testing: TESTING.md"
echo ""
echo "Your API key: $API_KEY"
echo "(Also saved in .env file)"
echo ""
