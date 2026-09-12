<?php

declare(strict_types=1);

namespace RiskAssessment\Mail;

/**
 * Library-free SMTP client (ported from LinkNest mailer.php).
 * Supports STARTTLS + AUTH LOGIN for Office 365 (smtp.office365.com:587).
 */
final class SmtpMailer
{
    /**
     * @param string|list<string> $to
     * @param array{
     *   host: string,
     *   port?: int,
     *   encryption?: string,
     *   username?: string,
     *   password?: string,
     *   from_email: string,
     *   from_name?: string
     * } $config
     * @param list<string> $cc
     * @param array{html?: string, text?: string, is_html?: bool, bcc?: list<string>|string} $options
     * @return bool|string True on success, error message on failure
     */
    public function send(string|array $to, string $subject, string $body, array $config, array $cc = [], array $options = []): bool|string
    {
        $toList = SmtpSettings::normalizeRecipients($to);
        $ccList = SmtpSettings::normalizeRecipients($cc);
        $bccList = SmtpSettings::normalizeRecipients($options['bcc'] ?? []);

        return $this->sendMulti($toList, $ccList, $subject, $body, $config, $options, $bccList);
    }

    /**
     * @param list<string> $toList
     * @param list<string> $ccList
     * @param array{
     *   host: string,
     *   port?: int,
     *   encryption?: string,
     *   username?: string,
     *   password?: string,
     *   from_email: string,
     *   from_name?: string
     * } $config
     * @param array{html?: string, text?: string, is_html?: bool, bcc?: list<string>|string} $options
     * @param list<string> $bccList
     * @return bool|string
     */
    public function sendMulti(
        array $toList,
        array $ccList,
        string $subject,
        string $body,
        array $config,
        array $options = [],
        array $bccList = []
    ): bool|string {
        $toHeader = implode(', ', $toList);
        $ccHeader = implode(', ', $ccList);
        if ($bccList === [] && isset($options['bcc'])) {
            $bccList = SmtpSettings::normalizeRecipients($options['bcc']);
        }
        $allRecipients = array_values(array_unique(array_merge($toList, $ccList, $bccList)));

        if ($allRecipients === []) {
            return 'No valid recipients (To/CC/BCC)';
        }
        if ($toList === [] && $ccList === [] && $bccList !== []) {
            // Keep a visible To header when only BCC was provided.
            $toHeader = 'undisclosed-recipients:;';
        }

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 587);
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $fromName = trim((string) ($config['from_name'] ?? 'Risk Register'));
        $encryption = SmtpSettings::normalizeEncryption((string) ($config['encryption'] ?? 'tls'));

        if ($host === '' || $fromEmail === '') {
            return 'Missing SMTP configuration (host or from_email)';
        }
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }

        $timeout = 30;
        $socketContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $protocol = $encryption === SmtpSettings::ENCRYPTION_SSL ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $protocol . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $socketContext
        );

        if ($socket === false) {
            return "Connection failed: {$errno} {$errstr}";
        }

        $read = static function () use ($socket): string {
            $response = '';
            while (($str = fgets($socket, 515)) !== false) {
                $response .= $str;
                if (isset($str[3]) && $str[3] === ' ') {
                    break;
                }
            }

            return $response;
        };

        $write = static function (string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        $read(); // greeting

        $ehloHost = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        if ($ehloHost === '') {
            $ehloHost = 'localhost';
        }
        $write('EHLO ' . $ehloHost);
        $read();

        if ($encryption === SmtpSettings::ENCRYPTION_TLS) {
            $write('STARTTLS');
            $response = $read();
            if (!str_starts_with($response, '220')) {
                fclose($socket);

                return 'STARTTLS failed: ' . trim($response);
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);

                return 'Unable to enable TLS encryption.';
            }
            $write('EHLO ' . $ehloHost);
            $read();
        }

        if ($username !== '' && $password !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($username));
            $read();
            $write(base64_encode($password));
            $response = $read();
            if (!str_starts_with($response, '235')) {
                fclose($socket);

                return 'Auth failed: ' . trim($response);
            }
        }

        $write("MAIL FROM: <{$fromEmail}>");
        $response = $read();
        if (!str_starts_with($response, '250')) {
            fclose($socket);

            return 'MAIL FROM failed: ' . trim($response);
        }

        foreach ($allRecipients as $rcpt) {
            $write("RCPT TO: <{$rcpt}>");
            $response = $read();
            $code = substr($response, 0, 3);
            if ($code !== '250' && $code !== '251') {
                fclose($socket);

                return "RCPT TO failed for {$rcpt}: " . trim($response);
            }
        }

        $write('DATA');
        $response = $read();
        if (!str_starts_with($response, '354')) {
            fclose($socket);

            return 'DATA failed: ' . trim($response);
        }

        [$contentType, $smtpBody] = $this->buildMultipartBody($body, $options);
        $smtpBody = (string) preg_replace("/(^|\r\n)\./", '$1..', $smtpBody);

        $safeSubject = $this->encodeHeader($subject);
        $safeFromName = $this->encodeHeader($fromName);

        $message = 'Date: ' . date('r') . "\r\n";
        $message .= "To: {$toHeader}\r\n";
        if ($ccHeader !== '') {
            $message .= "Cc: {$ccHeader}\r\n";
        }
        $message .= "From: {$safeFromName} <{$fromEmail}>\r\n";
        $message .= "Subject: {$safeSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: {$contentType}\r\n";
        $message .= "\r\n";
        $message .= $smtpBody;
        $message .= "\r\n.";

        $write($message);
        $response = $read();
        if (!str_starts_with($response, '250')) {
            fclose($socket);

            return 'Message sending failed: ' . trim($response);
        }

        $write('QUIT');
        fclose($socket);

        return true;
    }

    /**
     * @param array{html?: string, text?: string, is_html?: bool} $options
     * @return array{0: string, 1: string}
     */
    private function buildMultipartBody(string $plainBody, array $options): array
    {
        if (empty($options['html'])) {
            if (!empty($options['is_html'])) {
                return ['text/html; charset=UTF-8', $plainBody];
            }

            return ['text/plain; charset=UTF-8', $plainBody];
        }

        $textPart = (string) ($options['text'] ?? $plainBody);
        $htmlPart = (string) $options['html'];
        $altBoundary = 'rr_alt_' . bin2hex(random_bytes(8));
        $altBody =
            "--{$altBoundary}\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $textPart . "\r\n" .
            "--{$altBoundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $htmlPart . "\r\n" .
            "--{$altBoundary}--\r\n";

        return ['multipart/alternative; boundary="' . $altBoundary . '"', $altBody];
    }

    private function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], '', $value));
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
