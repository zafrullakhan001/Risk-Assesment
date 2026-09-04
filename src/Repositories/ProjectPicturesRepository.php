<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class ProjectPicturesRepository
{
    public const MAX_PICTURES = 10;

    private const MAX_TITLE_LENGTH = 200;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png'];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Metadata only — base64 stays in the database until a picture is viewed.
     *
     * @return list<array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, title, mime_type, original_filename, sort_order
             FROM project_pictures
             WHERE assessment_id = :assessment_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $rows = $statement->fetchAll();

        $pictures = [];
        foreach ($rows as $row) {
            $mime = strtolower(trim((string) ($row['mime_type'] ?? '')));
            if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
                $mime = 'image/jpeg';
            }
            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                continue;
            }
            $pictures[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'mime_type' => $mime,
                'original_filename' => (string) ($row['original_filename'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $pictures;
    }

    public function countForAssessment(int $assessmentId): int
    {
        if ($assessmentId <= 0) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_pictures WHERE assessment_id = :assessment_id'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Decode stored base64 for on-demand viewing.
     *
     * @return array{id: int, mime_type: string, bytes: string}|null
     */
    public function findForView(int $pictureId, int $assessmentId): ?array
    {
        if ($pictureId <= 0 || $assessmentId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, mime_type, image_base64
             FROM project_pictures
             WHERE id = :id AND assessment_id = :assessment_id
             LIMIT 1'
        );
        $statement->execute([
            ':id' => $pictureId,
            ':assessment_id' => $assessmentId,
        ]);
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
    public function addForAssessment(int $assessmentId, array $picture): ?array
    {
        if ($assessmentId <= 0 || !$this->assessmentExists($assessmentId)) {
            return null;
        }

        if ($this->countForAssessment($assessmentId) >= self::MAX_PICTURES) {
            throw new \InvalidArgumentException('A project can have at most ' . self::MAX_PICTURES . ' pictures.');
        }

        $mime = $this->normalizeMime((string) ($picture['mime_type'] ?? ''));
        $base64 = preg_replace('/\s+/', '', (string) ($picture['base64'] ?? '')) ?? '';
        if ($base64 === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64) || base64_decode($base64, true) === false) {
            throw new \InvalidArgumentException('Picture data is not valid base64.');
        }

        $title = trim((string) ($picture['title'] ?? ''));
        if ($title === '') {
            $title = 'Picture';
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
        }

        $filename = trim((string) ($picture['original_filename'] ?? ''));
        if (mb_strlen($filename) > 200) {
            $filename = mb_substr($filename, 0, 200);
        }

        $nextOrder = $this->nextSortOrder($assessmentId);

        $insert = $this->pdo->prepare(
            'INSERT INTO project_pictures (assessment_id, title, mime_type, image_base64, original_filename, sort_order, updated_at)
             VALUES (:assessment_id, :title, :mime_type, :image_base64, :original_filename, :sort_order, datetime(\'now\'))'
        );
        $insert->execute([
            ':assessment_id' => $assessmentId,
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

    /**
     * @param list<array{id?: int, title?: string}> $titles
     */
    public function updateTitlesForAssessment(int $assessmentId, array $titles): bool
    {
        if ($assessmentId <= 0 || !$this->assessmentExists($assessmentId)) {
            return false;
        }

        $update = $this->pdo->prepare(
            'UPDATE project_pictures
             SET title = :title, updated_at = datetime(\'now\')
             WHERE id = :id AND assessment_id = :assessment_id'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($titles as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $title = 'Picture';
                }
                if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                    $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
                }
                $update->execute([
                    ':title' => $title,
                    ':id' => $id,
                    ':assessment_id' => $assessmentId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return true;
    }

    public function deleteOne(int $pictureId, int $assessmentId): bool
    {
        if ($pictureId <= 0 || $assessmentId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM project_pictures WHERE id = :id AND assessment_id = :assessment_id'
        );
        $statement->execute([
            ':id' => $pictureId,
            ':assessment_id' => $assessmentId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM project_pictures WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    public function viewUrl(int $assessmentId, int $pictureId): string
    {
        return 'index.php?action=view_project_picture&assessment_id=' . $assessmentId . '&picture_id=' . $pictureId;
    }

    private function assessmentExists(int $assessmentId): bool
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);

        return $exists->fetchColumn() !== false;
    }

    private function nextSortOrder(int $assessmentId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) FROM project_pictures WHERE assessment_id = :assessment_id'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        return ((int) $statement->fetchColumn()) + 1;
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
            $mime = 'image/jpeg';
        }
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Stored pictures must be JPG or PNG.');
        }

        return $mime;
    }
}
