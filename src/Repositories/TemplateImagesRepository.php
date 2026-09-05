<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class TemplateImagesRepository
{
    public const MAX_IMAGES = 5;

    private const MAX_TITLE_LENGTH = 200;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png'];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Metadata only — base64 stays in the database until an image is viewed.
     *
     * @return list<array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}>
     */
    public function listForTemplate(int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, title, mime_type, original_filename, sort_order
             FROM template_images
             WHERE template_id = :template_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([':template_id' => $templateId]);
        $rows = $statement->fetchAll();

        $images = [];
        foreach ($rows as $row) {
            $mime = strtolower(trim((string) ($row['mime_type'] ?? '')));
            if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
                $mime = 'image/jpeg';
            }
            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                continue;
            }
            $images[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'mime_type' => $mime,
                'original_filename' => (string) ($row['original_filename'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $images;
    }

    public function countForTemplate(int $templateId): int
    {
        if ($templateId <= 0) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM template_images WHERE template_id = :template_id'
        );
        $statement->execute([':template_id' => $templateId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Decode stored base64 for on-demand viewing.
     *
     * @return array{id: int, mime_type: string, bytes: string}|null
     */
    public function findForView(int $imageId): ?array
    {
        if ($imageId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, mime_type, image_base64
             FROM template_images
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $imageId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $mime = $this->normalizeMime((string) ($row['mime_type'] ?? ''));
        $base64 = preg_replace('/\s+/', '', (string) ($row['image_base64'] ?? '')) ?? '';
        if ($base64 === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64)) {
            return null;
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'mime_type' => $mime,
            'bytes' => $bytes,
        ];
    }

    /**
     * @param array{title?: string, mime_type: string, base64: string, original_filename?: string} $picture
     * @return array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}|null
     */
    public function addForTemplate(int $templateId, array $picture): ?array
    {
        if ($templateId <= 0 || !$this->templateExists($templateId)) {
            return null;
        }

        if ($this->countForTemplate($templateId) >= self::MAX_IMAGES) {
            throw new \InvalidArgumentException('A template can have at most ' . self::MAX_IMAGES . ' guide images.');
        }

        $mime = $this->normalizeMime((string) ($picture['mime_type'] ?? ''));
        $base64 = preg_replace('/\s+/', '', (string) ($picture['base64'] ?? '')) ?? '';
        if ($base64 === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64) || base64_decode($base64, true) === false) {
            throw new \InvalidArgumentException('Image data is not valid base64.');
        }

        $title = trim((string) ($picture['title'] ?? ''));
        if ($title === '') {
            $title = 'Guide image';
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
        }

        $filename = trim((string) ($picture['original_filename'] ?? ''));
        if (mb_strlen($filename) > 200) {
            $filename = mb_substr($filename, 0, 200);
        }

        $nextOrder = $this->nextSortOrder($templateId);

        $insert = $this->pdo->prepare(
            'INSERT INTO template_images (template_id, title, mime_type, image_base64, original_filename, sort_order, updated_at)
             VALUES (:template_id, :title, :mime_type, :image_base64, :original_filename, :sort_order, datetime(\'now\'))'
        );
        $insert->execute([
            ':template_id' => $templateId,
            ':title' => $title,
            ':mime_type' => $mime,
            ':image_base64' => $base64,
            ':original_filename' => $filename,
            ':sort_order' => $nextOrder,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
            'mime_type' => $mime,
            'original_filename' => $filename,
            'sort_order' => $nextOrder,
        ];
    }

    public function deleteOne(int $imageId, int $templateId): bool
    {
        if ($imageId <= 0 || $templateId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM template_images WHERE id = :id AND template_id = :template_id'
        );
        $statement->execute([
            ':id' => $imageId,
            ':template_id' => $templateId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function deleteAllForTemplate(int $templateId): void
    {
        if ($templateId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM template_images WHERE template_id = :id');
        $statement->execute([':id' => $templateId]);
    }

    public function viewUrl(int $imageId): string
    {
        return 'templates.php?view=image&id=' . $imageId;
    }

    private function templateExists(int $templateId): bool
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM template_workbooks WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $templateId]);

        return $exists->fetchColumn() !== false;
    }

    private function nextSortOrder(int $templateId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) FROM template_images WHERE template_id = :template_id'
        );
        $statement->execute([':template_id' => $templateId]);

        return ((int) $statement->fetchColumn()) + 1;
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
            $mime = 'image/jpeg';
        }
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Guide images must be JPG or PNG.');
        }

        return $mime;
    }
}
