# Security Quick Reference & Testing Guide

## Quick Security Test Checklist

### 1. Test Directory Browsing Protection ✓
```
# Try these URLs in your browser (should all return 403 Forbidden):
http://localhost/RiskRegister/config/
http://localhost/RiskRegister/src/
http://localhost/RiskRegister/database/
http://localhost/RiskRegister/uploads/
http://localhost/RiskRegister/vendor/
http://localhost/RiskRegister/logs/
http://localhost/RiskRegister/bin/
```

### 2. Test Sensitive File Access ✓
```
# Try these URLs (should all return 403 Forbidden):
http://localhost/RiskRegister/.env
http://localhost/RiskRegister/composer.json
http://localhost/RiskRegister/config/config.php
http://localhost/RiskRegister/database/schema.sqlite.sql
http://localhost/RiskRegister/.git/config
http://localhost/RiskRegister/.gitignore
```

### 3. Test Security Headers ✓
**Open browser DevTools → Network tab → Reload page → Check Response Headers**

Should see:
- ✓ X-Content-Type-Options: nosniff
- ✓ X-Frame-Options: SAMEORIGIN
- ✓ X-XSS-Protection: 1; mode=block
- ✓ Referrer-Policy: strict-origin-when-cross-origin
- ✓ Content-Security-Policy: [policy string]
- ✓ Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()
- ✓ X-Powered-By: [should NOT be present]

### 4. Test Rate Limiting ✓
```
1. Go to login page
2. Enter wrong password 6 times
3. Should see error: "Too many attempts. Please try again in XXX seconds."
4. Wait for cooldown
5. Should be able to try again
```

### 5. Test Error Handling ✓
```
# Try accessing non-existent page:
http://localhost/RiskRegister/public/nonexistent.php

Expected: Generic error page WITHOUT stack traces or file paths
```

### 6. Test File Upload Security ✓
```
1. Go to file upload page
2. Try uploading .php file
3. Try uploading .exe file
4. Try uploading file larger than 10MB
Expected: All should be rejected with appropriate error messages
```

### 7. Test Session Security ✓
**Open browser DevTools → Application/Storage → Cookies**

Session cookie should have:
- ✓ HttpOnly: true
- ✓ SameSite: Lax
- ✓ Secure: true (if using HTTPS)

### 8. Test CSRF Protection ✓
```
CSRF tokens are automatically added to all forms
Test by:
1. Submit form without token → Should fail
2. Submit form with invalid token → Should fail
3. Submit form with valid token → Should succeed
```

## Common Security Commands

### Check PHP Version
```bash
php -v
```

### Find php.ini Location
```bash
php --ini
```

### Test PHP Configuration
```bash
php -i | grep -i "display_errors"
php -i | grep -i "expose_php"
php -i | grep -i "session"
```

### Check File Permissions (Linux)
```bash
# Directories should be 755
find /path/to/RiskRegister -type d -exec ls -ld {} \;

# Files should be 644
find /path/to/RiskRegister -type f -exec ls -l {} \;
```

### Fix File Permissions (Linux)
```bash
cd /path/to/RiskRegister
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 database/.encryption_key
```

### View Error Logs
```bash
# Application error log
tail -f logs/error.log

# Security event log
tail -f logs/security.log

# PHP error log (XAMPP Windows)
tail -f C:/xampp/apache/logs/error.log

# PHP error log (Linux)
tail -f /var/log/apache2/error.log
```

## Security Maintenance Schedule

### Daily
- [ ] Check application error logs for unusual activity
- [ ] Monitor failed login attempts

### Weekly
- [ ] Review security event logs
- [ ] Check for suspicious file uploads
- [ ] Verify backup integrity

### Monthly
- [ ] Update PHP to latest stable version
- [ ] Update Composer dependencies
- [ ] Review and rotate logs
- [ ] Test disaster recovery procedures

### Quarterly
- [ ] Security audit of codebase
- [ ] Review user permissions
- [ ] Update security documentation
- [ ] Penetration testing (if applicable)

## Common Security Issues & Fixes

### Issue: Headers Not Showing
**Symptom**: Security headers missing in browser DevTools

**Solutions**:
1. Check if Apache mod_headers is enabled
   ```bash
   # Enable mod_headers (Linux)
   sudo a2enmod headers
   sudo systemctl restart apache2
   ```

2. Verify .htaccess is being read
   ```apache
   # Add to Apache config
   AllowOverride All
   ```

3. Clear browser cache and reload

### Issue: Directory Listing Still Visible
**Symptom**: Can see file listings in directories

**Solutions**:
1. Verify .htaccess file exists in directory
2. Check if mod_rewrite is enabled
   ```bash
   sudo a2enmod rewrite
   ```
3. Verify AllowOverride is set in Apache config

### Issue: Rate Limiting Not Working
**Symptom**: Can attempt login unlimited times

**Solutions**:
1. Check if sessions are working
2. Verify Security class is loaded in bootstrap
3. Check session storage is writable
4. Clear session data and try again

### Issue: Error Messages Still Showing Details
**Symptom**: Stack traces visible to users

**Solutions**:
1. Check php.ini: `display_errors = Off`
2. Verify ErrorHandler is registered in bootstrap
3. Restart web server after php.ini changes
4. Check if APP_DEBUG constant is set to false

### Issue: File Uploads Failing
**Symptom**: All file uploads rejected

**Solutions**:
1. Check upload_max_filesize in php.ini
2. Check post_max_size in php.ini
3. Verify uploads directory is writable
4. Check file type is in allowed list

## Emergency Response

### If Site Is Under Attack:

1. **Immediate Actions**:
   ```bash
   # Block suspicious IP in .htaccess
   echo "Require not ip 123.456.789.0" >> .htaccess
   
   # Disable registration temporarily
   # In database: UPDATE app_settings SET value='0' WHERE key='local_registration_enabled'
   ```

2. **Investigation**:
   ```bash
   # Check recent logins
   tail -100 logs/security.log
   
   # Check failed attempts
   grep "login_failed" logs/security.log
   
   # Check uploaded files
   ls -la uploads/
   ```

3. **Recovery**:
   - Change all admin passwords
   - Review recent user accounts
   - Check for unauthorized file modifications
   - Restore from backup if compromised

### If Configuration Breaks Site:

1. **Quick Rollback**:
   ```bash
   # Restore previous .htaccess
   cp .htaccess.backup .htaccess
   
   # Restore previous php.ini
   cp /path/to/backup/php.ini /path/to/php.ini
   
   # Restart server
   sudo systemctl restart apache2
   ```

2. **Safe Mode Testing**:
   ```bash
   # Temporarily disable custom error handler
   # Comment out in public/bootstrap.php:
   // ErrorHandler::register();
   
   # Temporarily enable PHP errors
   ini_set('display_errors', '1');
   ```

## Security Tools & Resources

### Recommended Tools:
- **OWASP ZAP**: Web application security scanner
- **Burp Suite**: Security testing toolkit
- **Security Headers**: https://securityheaders.com
- **SSL Labs**: https://www.ssllabs.com/ssltest/

### Resources:
- OWASP Top 10: https://owasp.org/www-project-top-ten/
- PHP Security Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html
- Mozilla Security Guidelines: https://infosec.mozilla.org/guidelines/web_security

## Contact & Support

For security issues:
1. Check logs first: `logs/error.log` and `logs/security.log`
2. Consult SECURITY.md for detailed information
3. Review PHP_SECURITY_CONFIG.md for configuration help
4. Never share sensitive logs or configuration publicly

---

**Remember**: Security is a continuous process, not a one-time setup. Stay vigilant and keep everything updated!
