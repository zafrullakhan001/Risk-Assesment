# PHP Security Configuration Guide

This file contains recommended PHP configuration settings for enhanced security.
These settings should be added to your php.ini file or configured in your hosting environment.

## Location of php.ini
Common locations:
- XAMPP: C:\xampp\php\php.ini
- Linux: /etc/php/8.x/apache2/php.ini or /etc/php/8.x/fpm/php.ini
- Use `php --ini` to find your php.ini location

## Critical Security Settings

### 1. Hide PHP Version Information
```ini
; Prevents PHP version from being exposed in HTTP headers
expose_php = Off
```

### 2. Error Reporting and Display

```ini
; Production Settings - Hide errors from users
display_errors = Off
display_startup_errors = Off

; Development Settings - Show errors (comment out in production)
; display_errors = On
; display_startup_errors = On

; Always log errors
log_errors = On
error_log = "C:/xampp/htdocs/RiskRegister/logs/php_errors.log"

; Report all errors
error_reporting = E_ALL
```

### 3. Session Security

```ini
; Force sessions to only use cookies (not URL parameters)
session.use_only_cookies = 1

; Prevent JavaScript from accessing session cookies
session.cookie_httponly = 1

; Enable strict session ID mode
session.use_strict_mode = 1

; Disable transparent session ID propagation
session.use_trans_sid = 0

; Use secure cookies when HTTPS is available
session.cookie_secure = 0
; NOTE: Set to 1 if your site uses HTTPS

; SameSite cookie attribute for CSRF protection
session.cookie_samesite = "Lax"

; Increase session ID length for better security
session.sid_length = 48
session.sid_bits_per_character = 6

; Session timeout (in seconds)
session.gc_maxlifetime = 1440

; Session name (customize per application)
session.name = PHPSESSID
```

### 4. File Upload Settings

```ini
; Enable file uploads
file_uploads = On

; Maximum file upload size
upload_max_filesize = 10M

; Maximum POST data size
post_max_size = 12M

; Maximum number of files per upload
max_file_uploads = 20

; Temporary upload directory
upload_tmp_dir = "C:/xampp/tmp"
```

### 5. Disable Dangerous Functions

```ini
; Disable functions that can execute system commands
; Adjust this list based on your application needs
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source,pcntl_exec
```

**WARNING**: Only disable functions your application doesn't need. Test thoroughly after enabling this!

### 6. Resource Limits

```ini
; Maximum memory per script
memory_limit = 256M

; Maximum execution time (seconds)
max_execution_time = 30

; Maximum input processing time (seconds)
max_input_time = 60

; Maximum input variables
max_input_vars = 1000
```

### 7. Disable Remote File Access

```ini
; Prevent opening remote files with fopen, file_get_contents, etc.
allow_url_fopen = Off

; Prevent including remote files
allow_url_include = Off
```

**WARNING**: If your application uses cURL or Guzzle for API calls, you may need `allow_url_fopen = On`. The application can still make HTTP requests safely with cURL.

### 8. Output Buffering

```ini
; Enable output buffering for better performance
output_buffering = 4096

; Implicit flush off
implicit_flush = Off
```

### 9. Magic Quotes (Deprecated in PHP 7.x+)

```ini
; These are deprecated and should be off
; magic_quotes_gpc = Off
; magic_quotes_runtime = Off
; magic_quotes_sybase = Off
```

### 10. Open Basedir Restriction (Optional - Advanced)

```ini
; Restrict file operations to specific directories
; IMPORTANT: Set the correct path for your installation
; open_basedir = "C:/xampp/htdocs/RiskRegister;C:/xampp/tmp"
```

**WARNING**: This can break functionality if not configured correctly. Test thoroughly!

### 11. Disable Potentially Dangerous Features

```ini
; Disable phpinfo exposure
; (Already handled in disable_functions if needed)

; Disable mail function header injection
mail.add_x_header = On

; Limit POST data size
post_max_size = 12M

; Disable XMLRPC errors display
xmlrpc_errors = 0
```

### 12. Date and Time Settings

```ini
; Set default timezone
date.timezone = "UTC"
; Or use your local timezone, e.g., "America/New_York"
```

### 13. CGI Settings

```ini
; Fix PATH_INFO and PATH_TRANSLATED for CGI
cgi.fix_pathinfo = 0
```

## Complete Recommended php.ini Sections

### For Production Environment:

```ini
[PHP]
engine = On
short_open_tag = Off
precision = 14
output_buffering = 4096
zlib.output_compression = Off
implicit_flush = Off
unserialize_callback_func =
serialize_precision = -1
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
disable_classes =
zend.enable_gc = On
expose_php = Off

; Resource Limits
max_execution_time = 30
max_input_time = 60
memory_limit = 256M

; Error handling
error_reporting = E_ALL
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "C:/xampp/htdocs/RiskRegister/logs/php_errors.log"
log_errors_max_len = 1024
ignore_repeated_errors = Off
ignore_repeated_source = Off
report_memleaks = On

; Data Handling
post_max_size = 12M
auto_prepend_file =
auto_append_file =
default_mimetype = "text/html"
default_charset = "UTF-8"

; File Uploads
file_uploads = On
upload_tmp_dir = "C:/xampp/tmp"
upload_max_filesize = 10M
max_file_uploads = 20

; Security
allow_url_fopen = Off
allow_url_include = Off
cgi.fix_pathinfo = 0

; Session
session.save_handler = files
session.use_strict_mode = 1
session.use_cookies = 1
session.use_only_cookies = 1
session.name = PHPSESSID
session.auto_start = 0
session.cookie_lifetime = 0
session.cookie_path = /
session.cookie_domain =
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.serialize_handler = php
session.gc_probability = 1
session.gc_divisor = 1000
session.gc_maxlifetime = 1440
session.sid_length = 48
session.sid_bits_per_character = 6
session.use_trans_sid = 0

[Date]
date.timezone = "UTC"

[mail function]
mail.add_x_header = On

[opcache]
; Enable OPcache for performance
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
```

## After Modifying php.ini

### 1. Verify Syntax
Run: `php -l path/to/php.ini`

### 2. Restart Web Server
```bash
# XAMPP on Windows
# Stop and start Apache from XAMPP Control Panel

# Linux with Apache
sudo systemctl restart apache2

# Linux with PHP-FPM
sudo systemctl restart php8.x-fpm
```

### 3. Verify Settings
Create a test file: `phpinfo.php`
```php
<?php
phpinfo();
?>
```

Access it in browser and search for your settings. **Delete this file after checking!**

### 4. Check for Errors
- Check PHP error log: `logs/php_errors.log`
- Check Apache error log: `C:/xampp/apache/logs/error.log` (Windows) or `/var/log/apache2/error.log` (Linux)

## Platform-Specific Notes

### XAMPP on Windows
- php.ini location: `C:\xampp\php\php.ini`
- Restart Apache from XAMPP Control Panel
- Use forward slashes or double backslashes in paths

### Linux
- Configuration may be split between `php.ini` and `conf.d/` directory
- Check both Apache and CLI php.ini files
- Use `sudo` for system operations

### Shared Hosting
- You may not have access to php.ini
- Use `.user.ini` or `.htaccess` for some settings
- Contact hosting provider for critical security settings

## Testing Your Configuration

### 1. Test Error Display
Create a test file that triggers an error and verify it's logged, not displayed.

### 2. Test Session Security
Check session cookies in browser developer tools for HttpOnly and Secure flags.

### 3. Test File Uploads
Verify upload size limits work as expected.

### 4. Test Disabled Functions
Try calling a disabled function and verify it's blocked.

## Troubleshooting

### Application Stopped Working
1. Check error logs
2. Try commenting out `disable_functions` temporarily
3. Verify file paths in configuration
4. Check open_basedir if enabled

### Sessions Not Working
1. Check session.save_path is writable
2. Verify session.use_only_cookies is set
3. Check browser cookie settings

### File Uploads Failing
1. Check upload_tmp_dir is writable
2. Verify upload_max_filesize and post_max_size
3. Check file_uploads is On

## Security Monitoring

### Regular Tasks:
1. Monitor error logs weekly
2. Review failed login attempts
3. Check for unauthorized file access attempts
4. Keep PHP updated with security patches
5. Review and update disabled functions list

---

**Note**: Always backup your php.ini before making changes, and test thoroughly in a development environment first!
