<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;
use finfo;

final class Branding
{
    public const MAX_TITLE = 80;
    public const MAX_DOCUMENT_TITLE = 120;
    public const MAX_HERO_HEADING = 160;
    public const MAX_HERO_INTRO = 280;
    public const MAX_FOOTER = 280;
    public const LOGO_SIZE_MIN = 32;
    public const LOGO_SIZE_MAX = 120;
    public const LOGO_SIZE_DEFAULT = 56;

    /** @var array<string, string> */
    public const DEFAULTS = [
        'brand_title' => 'Architecture Risk',
        'brand_subtitle' => 'Assessment register',
        'brand_document_title' => 'Architecture Risk Assessment Dashboard',
        'brand_hero_eyebrow' => 'Architecture risk assessment',
        'brand_hero_heading' => 'Find any project by *name*',
        'brand_hero_intro' => 'Upload a multi-tab workbook or open a saved assessment.',
        'brand_footer_text' => '',
        'brand_logo_file' => '',
        'brand_favicon_file' => '',
        'brand_logo_size' => '56',
    ];

    /** @var list<string> */
    private const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];

    /** @var list<string> */
    private const FAVICON_EXTENSIONS = ['ico', 'png', 'svg', 'webp'];

    /** @var list<string> */
    private const IMAGE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'image/svg+xml',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/ico',
    ];

    private static ?self $instance = null;

    public function __construct(
        private readonly ?SettingsRepository $settings,
        private readonly string $brandingDir,
        private readonly int $maxBytes = 1048576,
    ) {
        self::$instance = $this;
    }

    public static function current(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        return new self(null, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'branding');
    }

    public function get(string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';
        $stored = $this->settings?->get($key, $default) ?? $default;
        $allowEmpty = in_array($key, ['brand_footer_text', 'brand_logo_file', 'brand_favicon_file'], true);
        $stored = $allowEmpty ? $stored : trim($stored);

        return $stored !== '' || $allowEmpty ? $stored : $default;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function documentTitle(): string
    {
        return $this->get('brand_document_title');
    }

    public function brandTitle(): string
    {
        return $this->get('brand_title');
    }

    public function brandSubtitle(): string
    {
        return $this->get('brand_subtitle');
    }

    public function heroEyebrow(): string
    {
        return $this->get('brand_hero_eyebrow');
    }

    public function heroHeadingHtml(): string
    {
        return $this->emphasisHtml($this->get('brand_hero_heading'));
    }

    public function heroHeadingPlain(): string
    {
        return trim(str_replace('*', '', $this->get('brand_hero_heading')));
    }

    public function heroIntro(): string
    {
        return $this->get('brand_hero_intro');
    }

    public function footerText(): string
    {
        return $this->get('brand_footer_text');
    }

    public function logoSize(): int
    {
        return $this->logoSizeFrom($this->get('brand_logo_size'));
    }

    public function emphasisHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function save(array $post, array $files): void
    {
        if ($this->settings === null) {
            throw new RuntimeException('Branding settings are not available.');
        }

        $this->settings->set('brand_title', $this->cleanText((string) ($post['brand_title'] ?? ''), self::MAX_TITLE, 'Brand name'));
        $this->settings->set('brand_subtitle', $this->cleanText((string) ($post['brand_subtitle'] ?? ''), self::MAX_TITLE, 'Brand tagline', true));
        $this->settings->set('brand_document_title', $this->cleanText((string) ($post['brand_document_title'] ?? ''), self::MAX_DOCUMENT_TITLE, 'Browser title'));
        $this->settings->set('brand_hero_eyebrow', $this->cleanText((string) ($post['brand_hero_eyebrow'] ?? ''), self::MAX_TITLE, 'Hero label', true));
        $this->settings->set('brand_hero_heading', $this->cleanText((string) ($post['brand_hero_heading'] ?? ''), self::MAX_HERO_HEADING, 'Hero headline'));
        $this->settings->set('brand_hero_intro', $this->cleanText((string) ($post['brand_hero_intro'] ?? ''), self::MAX_HERO_INTRO, 'Hero supporting text', true));
        $this->settings->set('brand_footer_text', $this->cleanText((string) ($post['brand_footer_text'] ?? ''), self::MAX_FOOTER, 'Footer text', true));
        $this->settings->set('brand_logo_size', (string) $this->logoSizeFrom((string) ($post['brand_logo_size'] ?? '')));

        $logo = $this->uploadedFile($files, 'logo');
        if ($logo !== null) {
            $stored = $this->storeImage($logo, self::LOGO_EXTENSIONS, 'logo');
            $this->deleteStored($this->get('brand_logo_file'));
            $this->settings->set('brand_logo_file', $stored);
        }

        $favicon = $this->uploadedFile($files, 'favicon');
        if ($favicon !== null) {
            $stored = $this->storeImage($favicon, self::FAVICON_EXTENSIONS, 'favicon');
            $this->deleteStored($this->get('brand_favicon_file'));
            $this->settings->set('brand_favicon_file', $stored);
        }
    }

    public function removeLogo(): void
    {
        if ($this->settings === null) {
            throw new RuntimeException('Branding settings are not available.');
        }
        $this->deleteStored($this->get('brand_logo_file'));
        $this->settings->set('brand_logo_file', '');
    }

    public function removeFavicon(): void
    {
        if ($this->settings === null) {
            throw new RuntimeException('Branding settings are not available.');
        }
        $this->deleteStored($this->get('brand_favicon_file'));
        $this->settings->set('brand_favicon_file', '');
    }

    public function hasCustomLogo(): bool
    {
        return $this->storedPath($this->get('brand_logo_file')) !== null;
    }

    public function hasCustomFavicon(): bool
    {
        return $this->storedPath($this->get('brand_favicon_file')) !== null;
    }

    public function logoUrl(): string
    {
        return $this->publicUrl($this->get('brand_logo_file'));
    }

    public function faviconUrl(): string
    {
        $favicon = $this->publicUrl($this->get('brand_favicon_file'));
        if ($favicon !== '') {
            return $favicon;
        }

        $logoFile = $this->get('brand_logo_file');
        $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        if (in_array($ext, self::FAVICON_EXTENSIONS, true)) {
            return $this->publicUrl($logoFile);
        }

        return '';
    }

    public function faviconType(): string
    {
        $file = $this->get('brand_favicon_file');
        if ($file === '' || $this->storedPath($file) === null) {
            $file = $this->get('brand_logo_file');
        }

        return $this->mimeForExtension(strtolower(pathinfo($file, PATHINFO_EXTENSION)));
    }

    public function appleTouchUrl(): string
    {
        foreach ([$this->get('brand_favicon_file'), $this->get('brand_logo_file')] as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'png' && $this->publicUrl($file) !== '') {
                return $this->publicUrl($file);
            }
        }

        return '';
    }

    public function renderMark(): string
    {
        $title = htmlspecialchars($this->brandTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $size = $this->logoSize();
        $logoUrl = $this->logoUrl();
        if ($logoUrl !== '') {
            return '<span class="brand-mark brand-mark-image">'
                . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . $title . '" width="' . $size . '" height="' . $size . '">'
                . '</span>';
        }

        return '<span class="brand-mark" aria-hidden="true">'
            . '<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . $title . '">'
            . '<rect width="40" height="40" rx="10" fill="var(--primary-soft, #cffafe)"/>'
            . '<rect x="1.25" y="1.25" width="37.5" height="37.5" rx="8.75" stroke="var(--primary, #0e7490)" stroke-width="1.5"/>'
            . '<path d="M12 26V14.5L20 10L28 14.5V26" stroke="var(--primary, #0e7490)" stroke-width="1.75" stroke-linejoin="round"/>'
            . '<path d="M16 26V18.5H24V26" stroke="var(--ink, #0c1524)" stroke-width="1.75" stroke-linejoin="round"/>'
            . '<circle cx="20" cy="15.5" r="1.6" fill="var(--primary, #0e7490)"/>'
            . '</svg></span>';
    }

    private function logoSizeFrom(string $value): int
    {
        $size = (int) $value;
        if ($size < 1) {
            $size = self::LOGO_SIZE_DEFAULT;
        }

        return max(self::LOGO_SIZE_MIN, min(self::LOGO_SIZE_MAX, $size));
    }

    private function cleanText(string $value, int $max, string $label, bool $allowEmpty = false): string
    {
        $value = trim(preg_replace("/\r\n|\r/", "\n", $value) ?? $value);
        if ($value === '' && !$allowEmpty) {
            throw new RuntimeException($label . ' is required.');
        }
        if (function_exists('mb_strlen') ? mb_strlen($value) > $max : strlen($value) > $max) {
            throw new RuntimeException($label . ' is too long.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $files
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    private function uploadedFile(array $files, string $field): ?array
    {
        $file = $files[$field] ?? null;
        if (!is_array($file)) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || (string) ($file['name'] ?? '') === '') {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => $error,
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @param list<string> $allowedExtensions
     */
    private function storeImage(array $file, array $allowedExtensions, string $label): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unable to upload the ' . $label . '. Try a smaller file.');
        }
        if ($file['size'] <= 0 || $file['size'] > $this->maxBytes) {
            throw new RuntimeException(ucfirst($label) . ' must be 1 MB or smaller.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('That ' . $label . ' type is not allowed.');
        }

        $tmp = $file['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid ' . $label . ' upload.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $icoByMagic = $this->isIcoFile($tmp);
        $mimeOk = in_array($mime, self::IMAGE_MIME_TYPES, true)
            || ($extension === 'ico' && ($icoByMagic || $mime === 'application/octet-stream'));
        if (!$mimeOk) {
            throw new RuntimeException('That ' . $label . ' file type is not allowed.');
        }
        if ($extension === 'ico' && !$icoByMagic && !in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon', 'image/ico'], true)) {
            throw new RuntimeException('That favicon is not a valid ICO file.');
        }
        if ($extension === 'svg' || $mime === 'image/svg+xml') {
            $this->assertSafeSvg((string) file_get_contents($tmp));
        }

        $this->ensureDirectory();
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->brandingDir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store the ' . $label . '.');
        }

        return $stored;
    }

    private function assertSafeSvg(string $contents): void
    {
        if ($contents === '' || preg_match('/<(script|foreignObject|iframe|object|embed)\b/i', $contents) === 1) {
            throw new RuntimeException('That SVG contains disallowed content.');
        }
        if (preg_match('/\bon[a-z]+\s*=|javascript\s*:|data\s*:/i', $contents) === 1) {
            throw new RuntimeException('That SVG contains disallowed content.');
        }
    }

    private function isIcoFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);

        return $header === "\x00\x00\x01\x00" || $header === "\x00\x00\x02\x00";
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->brandingDir)) {
            return;
        }
        if (!mkdir($this->brandingDir, 0755, true) && !is_dir($this->brandingDir)) {
            throw new RuntimeException('Unable to create the branding folder.');
        }
    }

    private function deleteStored(string $filename): void
    {
        $path = $this->storedPath($filename);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }

    private function storedPath(string $filename): ?string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || str_contains($filename, '..')) {
            return null;
        }
        $path = $this->brandingDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return null;
        }

        $realFile = realpath($path);
        $realDir = realpath($this->brandingDir);
        if ($realFile === false || $realDir === false || !str_starts_with($realFile, $realDir)) {
            return null;
        }

        return $path;
    }

    private function publicUrl(string $filename): string
    {
        if ($this->storedPath($filename) === null) {
            return '';
        }
        $path = $this->storedPath($filename);
        $version = $path !== null ? (string) filemtime($path) : '1';

        return $this->assetPrefix() . 'assets/branding/' . rawurlencode(basename($filename)) . '?v=' . $version;
    }

    private function assetPrefix(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return str_contains($script, '/admin/') ? '../' : '';
    }

    private function mimeForExtension(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => '',
        };
    }
}
