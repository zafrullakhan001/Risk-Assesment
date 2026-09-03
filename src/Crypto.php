<?php

declare(strict_types=1);

namespace RiskAssessment;

use RuntimeException;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly string $keyFile,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false || $ivLength < 1) {
            throw new RuntimeException('OpenSSL AES-256-GCM is not available.');
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new RuntimeException('Unable to encrypt the secret.');
        }

        return base64_encode($iv) . '.' . base64_encode($tag) . '.' . base64_encode($encrypted);
    }

    public function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }
        if (!$this->isEncrypted($ciphertext)) {
            return $ciphertext;
        }

        $parts = explode('.', $ciphertext, 3);
        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $encrypted = base64_decode($parts[2], true);
        if ($iv === false || $tag === false || $encrypted === false) {
            throw new RuntimeException('Stored secret is corrupted.');
        }

        $plain = openssl_decrypt($encrypted, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt the stored secret.');
        }

        return $plain;
    }

    public function isEncrypted(string $value): bool
    {
        if ($value === '' || substr_count($value, '.') !== 2) {
            return false;
        }

        $parts = explode('.', $value, 3);
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/\s/', $part) === 1) {
                return false;
            }
        }

        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $encrypted = base64_decode($parts[2], true);
        $expectedIv = openssl_cipher_iv_length(self::CIPHER);
        if ($iv === false || $tag === false || $encrypted === false || $expectedIv === false) {
            return false;
        }

        return strlen($iv) === $expectedIv && strlen($tag) === 16 && $encrypted !== '';
    }

    public function isMaskedPlaceholder(string $value): bool
    {
        return $value !== '' && (bool) preg_match('/^[\*\x{2022}\x{00B7}\x{25CF}\x{25E6}•·]+$/u', $value);
    }

    private function key(): string
    {
        $directory = dirname($this->keyFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the encryption key directory.');
        }

        if (!is_readable($this->keyFile)) {
            if (file_put_contents($this->keyFile, bin2hex(random_bytes(32)), LOCK_EX) === false) {
                throw new RuntimeException('Unable to create the encryption key file.');
            }
            @chmod($this->keyFile, 0600);
        }

        $material = trim((string) file_get_contents($this->keyFile));
        if ($material === '') {
            throw new RuntimeException('Encryption key file is empty.');
        }

        return hash('sha256', $material, true);
    }
}
