<?php

declare(strict_types=1);

namespace RiskAssessment\Mail;

/**
 * Library-free SMTP client (ported from LinkNest mailer.php).
 * Supports STARTTLS, AUTH LOGIN, and AUTH PLAIN (Office 365 + local/custom relays).
 */
final class SmtpMailer
{
    private const TIMEOUT_SECONDS = 30;

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
        $username = trim((string) ($config['username'] ?? ''));
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

        $socketContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $protocol = $encryption === SmtpSettings::ENCRYPTION_SSL ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $protocol . $host . ':' . $port,
            $errno,
            $errstr,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $socketContext
        );

        if ($socket === false) {
            return "Connection failed: {$errno} {$errstr}";
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        $read = function () use ($socket): string {
            $response = '';
            while (($str = fgets($socket, 515)) !== false) {
                $response .= $str;
                if (isset($str[3]) && $str[3] === ' ') {
                    break;
                }
            }

            return $response;
        };

        $write = static function (string $cmd) use ($socket): bool {
            $written = @fwrite($socket, $cmd . "\r\n");

            return $written !== false;
        };

        $greeting = $read();
        $greetingError = $this->explainTransportFailure($greeting, $host, $port, 'greeting');
        if ($greetingError !== null) {
            fclose($socket);

            return $greetingError;
        }
        if (!str_starts_with($greeting, '220')) {
            fclose($socket);

            return 'SMTP greeting failed: ' . $this->safeResponse($greeting);
        }

        $ehloHost = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        if ($ehloHost === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $ehloHost)) {
            $ehloHost = 'localhost';
        }

        if (!$write('EHLO ' . $ehloHost)) {
            fclose($socket);

            return 'Failed to send EHLO (connection closed). If antivirus Mail Shield is on, disable outbound SMTP scanning or exclude ' . $host . '.';
        }
        $ehlo = $read();
        if (!str_starts_with($ehlo, '250')) {
            // Some old relays only speak HELO.
            if (!$write('HELO ' . $ehloHost)) {
                fclose($socket);

                return 'Failed to send HELO (connection closed).';
            }
            $ehlo = $read();
            if (!str_starts_with($ehlo, '250')) {
                fclose($socket);

                return 'EHLO/HELO failed: ' . $this->safeResponse($ehlo);
            }
        }

        if ($encryption === SmtpSettings::ENCRYPTION_TLS) {
            if (!$write('STARTTLS')) {
                fclose($socket);

                return 'Failed to send STARTTLS (connection closed). For port 25 internal relays, set Encryption to None.';
            }
            $response = $read();
            if (!str_starts_with($response, '220')) {
                fclose($socket);

                return 'STARTTLS failed: ' . $this->safeResponse($response)
                    . ' For internal servers on port 25, set Encryption to None.';
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);

                return 'Unable to enable TLS encryption. For internal servers on port 25, set Encryption to None.';
            }
            if (!$write('EHLO ' . $ehloHost)) {
                fclose($socket);

                return 'Failed to re-EHLO after STARTTLS.';
            }
            $ehlo = $read();
            if (!str_starts_with($ehlo, '250')) {
                fclose($socket);

                return 'EHLO after STARTTLS failed: ' . $this->safeResponse($ehlo);
            }
        }

        if ($username !== '' && $password !== '') {
            $authResult = $this->authenticate($write, $read, $ehlo, $username, $password);
            if ($authResult !== true) {
                fclose($socket);

                return $authResult;
            }
        }

        if (!$write("MAIL FROM: <{$fromEmail}>")) {
            fclose($socket);

            return 'Failed to send MAIL FROM (connection closed).';
        }
        $response = $read();
        if (!str_starts_with($response, '250')) {
            fclose($socket);

            return 'MAIL FROM failed: ' . $this->safeResponse($response);
        }

        foreach ($allRecipients as $rcpt) {
            if (!$write("RCPT TO: <{$rcpt}>")) {
                fclose($socket);

                return "Failed to send RCPT TO for {$rcpt} (connection closed).";
            }
            $response = $read();
            $code = substr($response, 0, 3);
            if ($code !== '250' && $code !== '251') {
                fclose($socket);

                return "RCPT TO failed for {$rcpt}: " . $this->safeResponse($response);
            }
        }

        if (!$write('DATA')) {
            fclose($socket);

            return 'Failed to send DATA (connection closed).';
        }
        $response = $read();
        if (!str_starts_with($response, '354')) {
            fclose($socket);

            return 'DATA failed: ' . $this->safeResponse($response);
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

        if (!$write($message)) {
            fclose($socket);

            return 'Failed to send message body (connection closed).';
        }
        $response = $read();
        if (!str_starts_with($response, '250')) {
            fclose($socket);

            return 'Message sending failed: ' . $this->safeResponse($response);
        }

        $write('QUIT');
        fclose($socket);

        return true;
    }

    /**
     * @param callable(string): bool $write
     * @param callable(): string $read
     * @return bool|string
     */
    private function authenticate(callable $write, callable $read, string $ehlo, string $username, string $password): bool|string
    {
        $mechanisms = $this->parseAuthMechanisms($ehlo);
        $preferPlain = $mechanisms === [] || in_array('PLAIN', $mechanisms, true);
        $preferLogin = $mechanisms === [] || in_array('LOGIN', $mechanisms, true);

        $errors = [];

        if ($preferPlain) {
            $plain = base64_encode("\0{$username}\0{$password}");
            if (!$write('AUTH PLAIN ' . $plain)) {
                return 'Failed to send AUTH PLAIN (connection closed). Check antivirus Mail Shield / outbound SMTP scanning.';
            }
            $response = $read();
            if (str_starts_with($response, '235')) {
                return true;
            }
            // 334 = server wants credentials on next line (rare for PLAIN with inline payload)
            if (str_starts_with($response, '334')) {
                if (!$write($plain)) {
                    return 'Failed to send AUTH PLAIN credentials (connection closed).';
                }
                $response = $read();
                if (str_starts_with($response, '235')) {
                    return true;
                }
            }
            $errors[] = 'PLAIN: ' . $this->safeResponse($response);
            if ($this->isHardAuthReject($response) && !$preferLogin) {
                return 'Auth failed (PLAIN): ' . $this->safeResponse($response);
            }
        }

        if ($preferLogin) {
            if (!$write('AUTH LOGIN')) {
                return 'Failed to send AUTH LOGIN (connection closed). Check antivirus Mail Shield / outbound SMTP scanning.';
            }
            $response = $read();
            if (!str_starts_with($response, '334')) {
                $errors[] = 'LOGIN start: ' . $this->safeResponse($response);

                return 'Auth failed: ' . implode(' | ', $errors)
                    . $this->authHint($mechanisms, $username);
            }
            if (!$write(base64_encode($username))) {
                return 'Failed to send AUTH username (connection closed).';
            }
            $response = $read();
            if (!str_starts_with($response, '334')) {
                $errors[] = 'LOGIN user: ' . $this->safeResponse($response);

                return 'Auth failed: ' . implode(' | ', $errors)
                    . $this->authHint($mechanisms, $username);
            }
            if (!$write(base64_encode($password))) {
                return 'Failed to send AUTH password (connection closed).';
            }
            $response = $read();
            if (str_starts_with($response, '235')) {
                return true;
            }
            $errors[] = 'LOGIN: ' . $this->safeResponse($response);
        }

        if ($errors === []) {
            return 'Auth failed: server did not advertise AUTH PLAIN/LOGIN.'
                . $this->authHint($mechanisms, $username);
        }

        return 'Auth failed: ' . implode(' | ', $errors)
            . $this->authHint($mechanisms, $username);
    }

    /**
     * @return list<string>
     */
    private function parseAuthMechanisms(string $ehlo): array
    {
        $found = [];
        foreach (preg_split("/\r\n|\n|\r/", $ehlo) ?: [] as $line) {
            if (preg_match('/^250[\s-]AUTH(?:\s+|=)(.+)$/i', trim($line), $m) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $mech) {
                $mech = strtoupper(trim($mech));
                if ($mech !== '') {
                    $found[] = $mech;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function isHardAuthReject(string $response): bool
    {
        $code = substr($response, 0, 3);

        return $code === '535' || $code === '534' || $code === '454';
    }

    /**
     * @param list<string> $mechanisms
     */
    private function authHint(array $mechanisms, string $username): string
    {
        $hints = [];
        if ($mechanisms !== []) {
            $hints[] = 'Server AUTH: ' . implode(', ', $mechanisms);
        }
        if ($username !== '' && !str_contains($username, '@')) {
            $hints[] = 'If auth still fails, try the full mailbox as username (e.g. ' . $username . '@localhost).';
        }
        $hints[] = 'For internal port 25 relays, set Encryption to None. If you see empty replies or 421 connect errors, disable antivirus Mail Shield / outbound SMTP scanning.';

        return ' — ' . implode(' ', $hints);
    }

    private function explainTransportFailure(string $response, string $host, int $port, string $stage): ?string
    {
        $trimmed = trim($response);
        if ($trimmed === '') {
            return "SMTP {$stage} timed out or returned empty (no banner from {$host}:{$port}). "
                . 'Usually antivirus Mail Shield is intercepting outbound SMTP, or the mail server is unreachable. '
                . 'Disable Mail Shield / Email Guard outbound scanning (or exclude this host), confirm Encryption is None for port 25, then retry.';
        }
        if (preg_match('/421\s+Cannot connect to SMTP server/i', $trimmed) === 1
            || preg_match('/connect error\s+10060/i', $trimmed) === 1
        ) {
            return 'Outbound SMTP is being blocked by antivirus Mail Shield (421/10060), not by your mail server. '
                . "Disable Mail Shield outbound scanning or add an exception for {$host}:{$port}, then retry.";
        }

        return null;
    }

    private function safeResponse(string $response): string
    {
        $trimmed = trim($response);

        return $trimmed !== '' ? $trimmed : '(empty response — connection dropped; check antivirus Mail Shield)';
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
