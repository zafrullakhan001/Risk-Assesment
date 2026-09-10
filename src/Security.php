<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Security utilities and hardening measures
 */
final class Security
{
    /**
     * Initialize PHP security settings
     */
    public static function initialize(): void
    {
        // Disable PHP version exposure
        @ini_set('expose_php', '0');
        
        // Disable error display in production
        if (!self::isDebugMode()) {
            @ini_set('display_errors', '0');
            @ini_set('display_startup_errors', '0');
            @ini_set('log_errors', '1');
        }
        
        // Session security
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
        @ini_set('session.sid_length', '48');
        @ini_set('session.sid_bits_per_character', '6');
        
        // Prevent session fixation
        @ini_set('session.use_trans_sid', '0');
        
        // File upload security
        @ini_set('file_uploads', '1');
        @ini_set('upload_max_filesize', '10M');
        @ini_set('post_max_size', '10M');
        
        // Disable dangerous functions
        // Note: This should ideally be set in php.ini, not at runtime
        // @ini_set('disable_functions', 'exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source');
    }
    
    /**
     * Send security headers
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Remove server information
        header_remove('X-Powered-By');
        
        // Content Security Policy
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com",
            "img-src 'self' data: https:",
            "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "connect-src 'self'",
            "frame-ancestors 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);
        
        // Permissions Policy (formerly Feature Policy)
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        
        // HSTS - Only enable if using HTTPS
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    /**
     * Validate and sanitize input
     * 
     * @param mixed $input
     * @param string $type One of: string, int, float, email, url, bool, array
     * @param mixed $default
     * @return mixed
     */
    public static function sanitizeInput($input, string $type = 'string', $default = null)
    {
        if ($input === null || $input === '') {
            return $default;
        }
        
        switch ($type) {
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false 
                    ? (int) $input 
                    : $default;
                
            case 'float':
                return filter_var($input, FILTER_VALIDATE_FLOAT) !== false 
                    ? (float) $input 
                    : $default;
                
            case 'email':
                $email = filter_var($input, FILTER_SANITIZE_EMAIL);
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false 
                    ? $email 
                    : $default;
                
            case 'url':
                $url = filter_var($input, FILTER_SANITIZE_URL);
                return filter_var($url, FILTER_VALIDATE_URL) !== false 
                    ? $url 
                    : $default;
                
            case 'bool':
                return filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) 
                    ?? $default;
                
            case 'array':
                return is_array($input) ? $input : $default;
                
            case 'string':
            default:
                return is_string($input) 
                    ? trim($input) 
                    : (is_scalar($input) ? trim((string) $input) : $default);
        }
    }
    
    /**
     * Rate limiting check
     * 
     * @param string $action Unique identifier for the action
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $timeWindow Time window in seconds
     * @return bool True if action is allowed, false if rate limit exceeded
     */
    public static function checkRateLimit(string $action, int $maxAttempts = 5, int $timeWindow = 300): bool
    {
        $key = 'rate_limit_' . $action;
        $now = time();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 1, 'reset_at' => $now + $timeWindow];
            return true;
        }
        
        $data = $_SESSION[$key];
        
        // Reset if time window has passed
        if ($now >= $data['reset_at']) {
            $_SESSION[$key] = ['count' => 1, 'reset_at' => $now + $timeWindow];
            return true;
        }
        
        // Increment counter
        $data['count']++;
        $_SESSION[$key] = $data;
        
        return $data['count'] <= $maxAttempts;
    }
    
    /**
     * Get time until rate limit resets
     * 
     * @param string $action
     * @return int Seconds until reset, or 0 if no limit active
     */
    public static function getRateLimitReset(string $action): int
    {
        $key = 'rate_limit_' . $action;
        
        if (!isset($_SESSION[$key])) {
            return 0;
        }
        
        $resetAt = $_SESSION[$key]['reset_at'] ?? 0;
        $remaining = $resetAt - time();
        
        return max(0, $remaining);
    }
    
    /**
     * Validate file upload security
     * 
     * @param array $file $_FILES array element
     * @param array $allowedExtensions
     * @param array $allowedMimeTypes
     * @param int $maxSize Maximum file size in bytes
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateFileUpload(
        array $file,
        array $allowedExtensions = [],
        array $allowedMimeTypes = [],
        int $maxSize = 5242880
    ): array {
        // Check for upload errors
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['valid' => false, 'error' => 'Invalid file upload'];
        }
        
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['valid' => false, 'error' => 'File size exceeds limit'];
            case UPLOAD_ERR_PARTIAL:
                return ['valid' => false, 'error' => 'File upload was incomplete'];
            case UPLOAD_ERR_NO_FILE:
                return ['valid' => false, 'error' => 'No file was uploaded'];
            default:
                return ['valid' => false, 'error' => 'File upload error'];
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            return ['valid' => false, 'error' => 'File size exceeds maximum allowed size'];
        }
        
        // Check if file actually exists
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid file upload'];
        }
        
        // Get file extension
        $filename = $file['name'] ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Validate extension
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }
        
        // Validate MIME type
        if (!empty($allowedMimeTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                return ['valid' => false, 'error' => 'File type not allowed'];
            }
        }
        
        // Check for dangerous content in filename
        if (preg_match('/\.(php|phtml|php3|php4|php5|phps|pht|phar|exe|sh|bat|cmd)$/i', $filename)) {
            return ['valid' => false, 'error' => 'Dangerous file type detected'];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Generate secure random token
     * 
     * @param int $length
     * @return string
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Check if running in debug mode
     * 
     * @return bool
     */
    private static function isDebugMode(): bool
    {
        return defined('APP_DEBUG') && APP_DEBUG === true;
    }
    
    /**
     * Check if connection is HTTPS
     * 
     * @return bool
     */
    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    
    /**
     * Prevent path traversal attacks
     * 
     * @param string $path
     * @param string $baseDir
     * @return bool True if path is safe
     */
    public static function isPathSafe(string $path, string $baseDir): bool
    {
        $realBase = realpath($baseDir);
        $realPath = realpath($path);
        
        if ($realBase === false || $realPath === false) {
            return false;
        }
        
        return str_starts_with($realPath, $realBase);
    }
    
    /**
     * Sanitize filename to prevent directory traversal
     * 
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path information
        $filename = basename($filename);
        
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        return $filename;
    }
    
    /**
     * Log security event
     * 
     * @param string $event
     * @param array $context
     */
    public static function logSecurityEvent(string $event, array $context = []): void
    {
        $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'security.log';
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_id' => $_SESSION[Auth::SESSION_USER] ?? null,
            'context' => $context,
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
