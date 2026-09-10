# GitHub Copilot + MCP Gateway Integration Guide

## 🏢 Enterprise On-Premise Setup

This guide covers setting up MCP integration for **GitHub Copilot** in enterprise environments where the Risk Register application runs on-premise behind a corporate network.

---

## 📋 Architecture Options

### Option 1: Direct Access (Simple)
```
GitHub Copilot → On-Premise Network → MCP API
```
**Requirements**: Copilot can reach your internal network

### Option 2: Reverse Proxy/Gateway (Recommended)
```
GitHub Copilot → Gateway (ngrok/cloudflare) → Firewall → MCP API
```
**Requirements**: Gateway provides secure tunnel to internal network

### Option 3: VPN Access
```
GitHub Copilot → Corporate VPN → Internal Network → MCP API
```
**Requirements**: VPN connection for Copilot access

---

## 🚀 Setup Methods

## Method 1: Using Ngrok (Quick Setup)

### Step 1: Install Ngrok
```bash
# Download from https://ngrok.com/download
# Or use package managers:

# Windows (Chocolatey)
choco install ngrok

# Mac (Homebrew)
brew install ngrok

# Linux (Snap)
snap install ngrok
```

### Step 2: Authenticate Ngrok
```bash
ngrok config add-authtoken YOUR_NGROK_TOKEN
```
Get your token from: https://dashboard.ngrok.com/get-started/your-authtoken

### Step 3: Create Ngrok Configuration
Create `ngrok.yml`:
```yaml
version: "2"
authtoken: YOUR_NGROK_TOKEN

tunnels:
  risk-register-mcp:
    proto: http
    addr: 80
    bind_tls: true
    inspect: false
    # Optional: Add authentication
    auth: "username:password"
    # Optional: Restrict to specific IPs
    # ip_restriction:
    #   allow_cidrs:
    #     - 192.30.252.0/22  # GitHub IP range
```

### Step 4: Start Ngrok Tunnel
```bash
# Start tunnel for Risk Register
ngrok http 80 --region us --log stdout

# Or use configuration file
ngrok start risk-register-mcp --config ngrok.yml
```

**Save the HTTPS URL** (e.g., `https://abc123.ngrok.io`)

### Step 5: Configure GitHub Copilot
In VS Code/Copilot settings, add MCP server:

**VS Code Settings** (`.vscode/settings.json`):
```json
{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "https://YOUR-NGROK-URL.ngrok.io/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_your_token_here"
      }
    }
  }
}
```

---

## Method 2: Using Cloudflare Tunnel (Production)

### Step 1: Install Cloudflare Tunnel
```bash
# Windows
winget install --id Cloudflare.cloudflared

# Mac
brew install cloudflare/cloudflare/cloudflared

# Linux
curl -L --output cloudflared.deb https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
sudo dpkg -i cloudflared.deb
```

### Step 2: Authenticate
```bash
cloudflared tunnel login
```
This opens a browser for Cloudflare authentication.

### Step 3: Create Tunnel
```bash
# Create tunnel
cloudflared tunnel create risk-register-mcp

# Note the tunnel ID from output
```

### Step 4: Configure Tunnel
Create `config.yml`:
```yaml
tunnel: YOUR_TUNNEL_ID
credentials-file: C:\Users\YourUser\.cloudflared\YOUR_TUNNEL_ID.json

ingress:
  - hostname: risk-register-mcp.yourdomain.com
    service: http://localhost:80
    originRequest:
      # Add extra headers for authentication
      httpHostHeader: localhost
  - service: http_status:404
```

### Step 5: Configure DNS
```bash
cloudflared tunnel route dns risk-register-mcp risk-register-mcp.yourdomain.com
```

### Step 6: Run Tunnel
```bash
# Run as service (Windows)
cloudflared service install
cloudflared service start

# Or run manually
cloudflared tunnel --config config.yml run risk-register-mcp
```

### Step 7: Configure GitHub Copilot
```json
{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "https://risk-register-mcp.yourdomain.com/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_your_token_here"
      }
    }
  }
}
```

---

## Method 3: Corporate Reverse Proxy (Enterprise)

### Using Nginx

**nginx.conf**:
```nginx
server {
    listen 443 ssl http2;
    server_name risk-register-external.company.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=mcp_limit:10m rate=10r/s;
    limit_req zone=mcp_limit burst=20;

    # GitHub Copilot IP allowlist (optional)
    # allow 192.30.252.0/22;
    # deny all;

    location /api/mcp {
        proxy_pass http://internal-server/api/mcp;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # WebSocket support
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # Timeouts for long-running queries
        proxy_read_timeout 300s;
        proxy_connect_timeout 300s;
    }

    # Health check endpoint
    location /health {
        access_log off;
        return 200 "OK";
    }
}
```

### Using Apache

**.htaccess** or **VirtualHost**:
```apache
<VirtualHost *:443>
    ServerName risk-register-external.company.com
    
    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem

    # Reverse proxy
    ProxyPreserveHost On
    ProxyPass /api/mcp http://internal-server/api/mcp
    ProxyPassReverse /api/mcp http://internal-server/api/mcp

    # Optional: IP restriction
    # <Location /api/mcp>
    #     Require ip 192.30.252.0/22
    # </Location>

    # Rate limiting
    <IfModule mod_ratelimit.c>
        <Location /api/mcp>
            SetOutputFilter RATE_LIMIT
            SetEnv rate-limit 400
        </Location>
    </IfModule>
</VirtualHost>
```

---

## 🔒 Security Best Practices

### 1. Token Security
```bash
# Generate strong tokens (done automatically in Admin → MCP / AI)
# Tokens are 64+ characters starting with ramcp_
```

### 2. Network Security
- **HTTPS Only**: Always use TLS/SSL for external access
- **IP Allowlisting**: Restrict to GitHub Copilot IPs if possible
- **Rate Limiting**: Prevent abuse with request limits
- **WAF**: Consider Web Application Firewall

### 3. Authentication Layers
```
Layer 1: HTTPS/TLS encryption
Layer 2: Gateway authentication (ngrok/cloudflare)
Layer 3: Bearer token (MCP token)
Layer 4: User permissions (token-bound)
```

### 4. Monitoring
```sql
-- Check token usage
SELECT 
    name,
    token_prefix,
    last_used_at,
    datetime(last_used_at, 'unixepoch') as last_used,
    expires_at,
    datetime(expires_at, 'unixepoch') as expires
FROM mcp_tokens 
WHERE revoked_at IS NULL 
ORDER BY last_used_at DESC;
```

---

## 📝 GitHub Copilot Configuration Examples

### Example 1: Basic Setup
```json
{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "https://your-gateway.ngrok.io/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_abc123..."
      },
      "timeout": 30000
    }
  }
}
```

### Example 2: Multiple Environments
```json
{
  "github.copilot.mcp.servers": {
    "risk-register-prod": {
      "url": "https://mcp.company.com/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_prod_token..."
      }
    },
    "risk-register-staging": {
      "url": "https://staging-mcp.company.com/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_staging_token..."
      }
    }
  }
}
```

### Example 3: With Proxy
```json
{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "https://mcp.company.com/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_token..."
      },
      "proxy": {
        "host": "proxy.company.com",
        "port": 8080,
        "auth": "username:password"
      }
    }
  }
}
```

---

## 🧪 Testing the Setup

### Test 1: Check Gateway Reachability
```bash
# Test the tunnel/gateway
curl -I https://your-gateway-url/api/mcp

# Should return: 405 Method Not Allowed (only POST allowed)
```

### Test 2: Test Authentication
```bash
# Test with valid token
curl -X POST https://your-gateway-url/api/mcp \
  -H "Authorization: Bearer ramcp_your_token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'

# Should return: {"jsonrpc":"2.0","id":1,"result":{}}
```

### Test 3: Test from GitHub Copilot
In VS Code with GitHub Copilot, ask:
```
"Search the Risk Register SharePoint catalog for test projects"
```

---

## 🔧 Troubleshooting

### Issue 1: "Connection Refused"
**Solutions:**
- Verify tunnel/gateway is running
- Check firewall allows traffic
- Confirm URL is correct
- Test with curl first

### Issue 2: "401 Unauthorized"
**Solutions:**
- Verify token is active in Admin → MCP / AI
- Check token hasn't expired
- Ensure Bearer prefix in header
- Copy token carefully (no extra spaces)

### Issue 3: "Timeout"
**Solutions:**
- Increase timeout in Copilot config
- Check proxy/gateway timeout settings
- Verify server is responsive
- Check network latency

### Issue 4: "SSL Certificate Error"
**Solutions:**
- Use valid SSL certificate
- For testing: use ngrok (auto-SSL)
- Add corporate CA to trust store
- Check certificate expiration

---

## 📊 Gateway Comparison

| Feature | Ngrok | Cloudflare Tunnel | Corporate Proxy |
|---------|-------|-------------------|-----------------|
| **Setup Time** | < 5 minutes | ~15 minutes | Hours/Days |
| **Cost** | Free/Paid | Free | Infrastructure |
| **SSL** | Automatic | Automatic | Manual setup |
| **Custom Domain** | Paid plan | Free | Yes |
| **IP Restriction** | Paid plan | Yes | Yes |
| **Performance** | Good | Excellent | Varies |
| **Reliability** | Good | Excellent | Varies |
| **Best For** | Quick testing | Production | Enterprise |

---

## 🚀 Production Deployment Checklist

- [ ] Choose gateway solution (recommend Cloudflare Tunnel)
- [ ] Set up custom domain with SSL
- [ ] Configure IP allowlisting (GitHub Copilot IPs)
- [ ] Enable rate limiting
- [ ] Set up monitoring/alerting
- [ ] Create MCP tokens with expiration
- [ ] Document URLs for team
- [ ] Test from external network
- [ ] Configure backup gateway
- [ ] Set up log aggregation

---

## 📚 Additional Resources

### GitHub Copilot IPs (for allowlisting)
```
192.30.252.0/22
185.199.108.0/22
140.82.112.0/20
```

### Rate Limit Recommendations
```
Normal users: 10 requests/second
Burst: 20 requests
Daily limit: 10,000 requests
```

### Monitoring Queries
```sql
-- Token usage stats
SELECT 
    COUNT(*) as active_tokens,
    COUNT(CASE WHEN last_used_at > unixepoch() - 86400 THEN 1 END) as used_today
FROM mcp_tokens 
WHERE revoked_at IS NULL;
```

---

## 💡 Tips

1. **Start with ngrok** for quick testing
2. **Move to Cloudflare** for production
3. **Monitor token usage** regularly
4. **Rotate tokens** every 90 days
5. **Use separate tokens** per user/service
6. **Test failover** scenarios
7. **Document setup** for team

---

**Need Help?**
- Admin Interface: `/admin/mcp.php`
- Help Documentation: Help & About → SharePoint → MCP integration
- Test API: Check token status and test connectivity

---

**Status**: Enterprise-ready with gateway support! 🏢✨
