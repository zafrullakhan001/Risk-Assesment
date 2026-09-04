<?php

declare(strict_types=1);

namespace RiskAssessment;

use RuntimeException;

final class ProjectImageConverter
{
    public const MAX_INPUT_BYTES = 2 * 1024 * 1024;
    public const MAX_EDGE = 2000;
    public const MAX_PIXELS = 16_000_000;
    public const MAX_BASE64_LENGTH = 5_500_000;

    /** @var list<string> */
    private const JPEG_MIMES = ['image/jpeg', 'image/jpg', 'image/pjpeg'];

    /** @var list<string> */
    private const BLOCKED_MIMES = [
        'image/svg+xml',
        'image/svg',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/ico',
        'text/html',
        'application/octet-stream',
    ];

    /**
     * Re-encode an uploaded picture as JPG or PNG and return base64 for storage.
     *
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int|string, size?: int|string} $file
     * @return array{mime_type: string, base64: string, original_filename: string}
     */
    public function fromUploadedFile(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('The uploaded picture could not be verified.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('The image file is empty.');
        }
        if ($size > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Pictures must be 2 MB or smaller.');
        }

        $binary = file_get_contents($tmpName);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Unable to read the uploaded picture.');
        }

        $filename = $this->safeFilename((string) ($file['name'] ?? 'picture'));

        return $this->convertToJpegOrPng($binary, $filename);
    }

    /**
     * Convert any readable raster image to JPG (for JPEG sources) or PNG (everything else).
     *
     * @return array{mime_type: string, base64: string, original_filename: string}
     */
    public function convertToJpegOrPng(string $binary, string $originalFilename = 'picture'): array
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Image processing (GD) is not available on this server.');
        }
        if ($binary === '') {
            throw new RuntimeException('The image file is empty.');
        }
        if (strlen($binary) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Pictures must be 2 MB or smaller.');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new RuntimeException('That file is not a readable picture.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $detectedMime = strtolower((string) ($info['mime'] ?? ''));

        if ($width < 1 || $height < 1) {
            throw new RuntimeException('That picture has invalid dimensions.');
        }
        if ($width > 8000 || $height > 8000 || ($width * $height) > self::MAX_PIXELS) {
            throw new RuntimeException('That picture is too large. Use an image under 4000×4000.');
        }
        if ($detectedMime === '' || str_starts_with($detectedMime, 'image/svg') || in_array($detectedMime, self::BLOCKED_MIMES, true)) {
            throw new RuntimeException('Only raster pictures are allowed. JPG and PNG are stored as-is; other formats are converted.');
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new RuntimeException('Unable to read that picture. Try JPG, PNG, GIF, WEBP, or BMP.');
        }

        try {
            $targetW = $width;
            $targetH = $height;
            $longest = max($width, $height);
            if ($longest > self::MAX_EDGE) {
                $scale = self::MAX_EDGE / $longest;
                $targetW = max(1, (int) round($width * $scale));
                $targetH = max(1, (int) round($height * $scale));
            }

            $canvas = imagecreatetruecolor($targetW, $targetH);
            if ($canvas === false) {
                throw new RuntimeException('Unable to process that picture.');
            }

            $keepJpeg = in_array($detectedMime, self::JPEG_MIMES, true);
            $outputMime = $keepJpeg ? 'image/jpeg' : 'image/png';

            if ($outputMime === 'image/png') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                if ($transparent !== false) {
                    imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
                }
            } else {
                $white = imagecolorallocate($canvas, 255, 255, 255);
                if ($white !== false) {
                    imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
                }
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

            ob_start();
            $ok = $outputMime === 'image/jpeg'
                ? imagejpeg($canvas, null, 85)
                : imagepng($canvas, null, 6);
            $encoded = (string) ob_get_clean();
            imagedestroy($canvas);

            if ($ok === false || $encoded === '') {
                throw new RuntimeException('Unable to convert that picture to JPG or PNG.');
            }

            $base64 = base64_encode($encoded);
            if ($base64 === '' || strlen($base64) > self::MAX_BASE64_LENGTH) {
                throw new RuntimeException('Converted picture is too large to store.');
            }

            return [
                'mime_type' => $outputMime,
                'base64' => $base64,
                'original_filename' => $this->safeFilename($originalFilename),
            ];
        } finally {
            imagedestroy($source);
        }
    }

    public function titleFromFilename(string $filename): string
    {
        $base = pathinfo($this->safeFilename($filename), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[_-]+/', ' ', $base) ?? '');

        return $base !== '' ? $base : 'Picture';
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace(["\0", '\\'], '', $filename));
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'picture';
        }

        return mb_substr($name, 0, 200);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The picture is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The picture upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'No picture was uploaded.',
            default => 'The picture could not be uploaded.',
        };
    }
}
