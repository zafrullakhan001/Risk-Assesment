<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Custom error handler to prevent information disclosure
 */
final class ErrorHandler
{
    private static bool $registered = false;
    
    /**
     * Register error and exception handlers
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        
        // Set error reporting level
        error_reporting(E_ALL);
        
        // In production, hide errors from output
        if (!self::isDebugMode()) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        }
        
        // Register custom error handler
        set_error_handler([self::class, 'handleError']);
        
        // Register custom exception handler
        set_exception_handler([self::class, 'handleException']);
        
        // Register shutdown function for fatal errors
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$registered = true;
    }
    
    /**
     * Handle PHP errors
     */
    public static function handleError(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0
    ): bool {
        // Don't handle errors suppressed with @
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        self::logError($errno, $errstr, $errfile, $errline);
        
        // In debug mode, show the error
        if (self::isDebugMode()) {
            return false;
        }
        
        // In production, show generic message for fatal errors
        if ($errno & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
            self::showErrorPage('An error occurred while processing your request.');
        }
        
        return true;
    }
    
    /**
     * Handle uncaught exceptions
     */
    public static function handleException(\Throwable $exception): void
    {
        self::logException($exception);
        
        if (self::isDebugMode()) {
            self::showDebugException($exception);
        } else {
            self::showErrorPage('An unexpected error occurred.');
        }
    }
    
    /**
     * Handle fatal errors during shutdown
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error === null) {
            return;
        }
        
        if ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            self::logError(
                $error['type'],
                $error['message'],
                $error['file'] ?? '',
                $error['line'] ?? 0
            );
            
            if (!self::isDebugMode() && !headers_sent()) {
                self::showErrorPage('A fatal error occurred.');
            }
        }
    }
    
    /**
     * Log error to file
     */
    private static function logError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    ): void {
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'error.log';
        
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED',
        ];
        
        $errorType = $errorTypes[$errno] ?? 'UNKNOWN';
        
        $message = sprintf(
            "[%s] %s: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $errorType,
            $errstr,
            $errfile,
            $errline
        );
        
        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log exception to file
     */
    private static function logException(\Throwable $exception): void
    {
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'error.log';
        
        $message = sprintf(
            "[%s] EXCEPTION: %s in %s:%d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        
        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Show generic error page (production)
     */
    private static function showErrorPage(string $message): void
    {
        if (headers_sent()) {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            return;
        }
        
        // Check if this is an AJAX request
        if (self::isAjaxRequest()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => $message
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .error-container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: white; 
            padding: 40px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        h1 { color: #d32f2f; margin-bottom: 20px; }
        p { color: #666; line-height: 1.6; }
        .back-link { 
            display: inline-block; 
            margin-top: 20px; 
            color: #1976d2; 
            text-decoration: none; 
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Error</h1>
        <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
        <p>If this problem persists, please contact the system administrator.</p>
        <a href="javascript:history.back()" class="back-link">← Go Back</a>
    </div>
</body>
</html>';
        exit;
    }
    
    /**
     * Show detailed exception (debug mode only)
     */
    private static function showDebugException(\Throwable $exception): void
    {
        if (headers_sent()) {
            echo '<pre>';
            echo htmlspecialchars((string) $exception, ENT_QUOTES, 'UTF-8');
            echo '</pre>';
            return;
        }
        
        if (self::isAjaxRequest()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exception</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        .exception { background: #252526; padding: 20px; border-left: 4px solid #d32f2f; }
        h1 { color: #d32f2f; margin: 0 0 10px 0; }
        .message { color: #ce9178; margin-bottom: 20px; }
        .location { color: #4ec9b0; margin-bottom: 20px; }
        .trace { background: #1e1e1e; padding: 15px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="exception">
        <h1>' . htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8') . '</h1>
        <div class="message">' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>
        <div class="location">
            File: ' . htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8') . '<br>
            Line: ' . $exception->getLine() . '
        </div>
        <div class="trace"><pre>' . htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre></div>
    </div>
</body>
</html>';
        exit;
    }
    
    /**
     * Check if running in debug mode
     */
    private static function isDebugMode(): bool
    {
        return defined('APP_DEBUG') && APP_DEBUG === true;
    }
    
    /**
     * Check if this is an AJAX request
     */
    private static function isAjaxRequest(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requested = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        
        return str_contains($accept, 'application/json')
            || strcasecmp($requested, 'XMLHttpRequest') === 0;
    }
}
