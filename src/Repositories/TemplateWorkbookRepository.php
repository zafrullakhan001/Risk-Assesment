<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class TemplateWorkbookRepository
{
    public const MAX_TEMPLATES = 10;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM template_workbooks');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * @return list<array{
     *   id: int,
     *   name: string,
     *   workbook_path: string,
     *   workbook_filename: string,
     *   workbook_size: int,
     *   prompt_path: string,
     *   prompt_filename: string,
     *   prompt_size: int,
     *   uploaded_by_user_id: int|null,
     *   uploaded_by_username: string,
     *   uploaded_by_display_name: string,
     *   uploaded_at: string
     * }>
     */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, workbook_path, workbook_filename, workbook_size,
                    prompt_path, prompt_filename, prompt_size,
                    uploaded_by_user_id, uploaded_by_username, uploaded_by_display_name, uploaded_at
             FROM template_workbooks
             ORDER BY uploaded_at DESC, id DESC'
        );
        if ($statement === false) {
            return [];
        }

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = $this->normalizeRow($row);
        }

        return $rows;
    }

    /**
     * @return array{
     *   id: int,
     *   name: string,
     *   workbook_path: string,
     *   workbook_filename: string,
     *   workbook_size: int,
     *   prompt_path: string,
     *   prompt_filename: string,
     *   prompt_size: int,
     *   uploaded_by_user_id: int|null,
     *   uploaded_by_username: string,
     *   uploaded_by_display_name: string,
     *   uploaded_at: string
     * }|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, workbook_path, workbook_filename, workbook_size,
                    prompt_path, prompt_filename, prompt_size,
                    uploaded_by_user_id, uploaded_by_username, uploaded_by_display_name, uploaded_at
             FROM template_workbooks
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * @param array{
     *   name: string,
     *   workbook_path: string,
     *   workbook_filename: string,
     *   workbook_size: int,
     *   prompt_path?: string,
     *   prompt_filename?: string,
     *   prompt_size?: int,
     *   uploaded_by_user_id?: int|null,
     *   uploaded_by_username?: string,
     *   uploaded_by_display_name?: string
     * } $data
     */
    public function create(array $data): int
    {
        if ($this->count() >= self::MAX_TEMPLATES) {
            throw new \RuntimeException('You can store up to ' . self::MAX_TEMPLATES . ' template workbooks. Delete one before uploading another.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Enter a template name.');
        }
        if (mb_strlen($name) > 160) {
            throw new \RuntimeException('Template name must be 160 characters or fewer.');
        }

        $workbookPath = trim((string) ($data['workbook_path'] ?? ''));
        $workbookFilename = trim((string) ($data['workbook_filename'] ?? ''));
        $workbookSize = max(0, (int) ($data['workbook_size'] ?? 0));
        if ($workbookPath === '' || $workbookFilename === '') {
            throw new \RuntimeException('A workbook file is required.');
        }

        $userId = (int) ($data['uploaded_by_user_id'] ?? 0);

        $statement = $this->pdo->prepare(
            'INSERT INTO template_workbooks (
                name, workbook_path, workbook_filename, workbook_size,
                prompt_path, prompt_filename, prompt_size,
                uploaded_by_user_id, uploaded_by_username, uploaded_by_display_name, uploaded_at
            ) VALUES (
                :name, :workbook_path, :workbook_filename, :workbook_size,
                :prompt_path, :prompt_filename, :prompt_size,
                :uploaded_by_user_id, :uploaded_by_username, :uploaded_by_display_name, datetime(\'now\')
            )'
        );
        $statement->execute([
            ':name' => $name,
            ':workbook_path' => $workbookPath,
            ':workbook_filename' => $workbookFilename,
            ':workbook_size' => $workbookSize,
            ':prompt_path' => trim((string) ($data['prompt_path'] ?? '')),
            ':prompt_filename' => trim((string) ($data['prompt_filename'] ?? '')),
            ':prompt_size' => max(0, (int) ($data['prompt_size'] ?? 0)),
            ':uploaded_by_user_id' => $userId > 0 ? $userId : null,
            ':uploaded_by_username' => trim((string) ($data['uploaded_by_username'] ?? '')),
            ':uploaded_by_display_name' => trim((string) ($data['uploaded_by_display_name'] ?? '')),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $name): bool
    {
        return $this->update($id, ['name' => $name]);
    }

    /**
     * @param array{
     *   name?: string,
     *   workbook_path?: string,
     *   workbook_filename?: string,
     *   workbook_size?: int,
     *   prompt_path?: string,
     *   prompt_filename?: string,
     *   prompt_size?: int,
     *   clear_prompt?: bool
     * } $data
     */
    public function update(int $id, array $data): bool
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \RuntimeException('Template not found.');
        }

        $name = array_key_exists('name', $data)
            ? trim((string) $data['name'])
            : $existing['name'];
        if ($name === '') {
            throw new \RuntimeException('Enter a template name.');
        }
        if (mb_strlen($name) > 160) {
            throw new \RuntimeException('Template name must be 160 characters or fewer.');
        }

        $workbookPath = $existing['workbook_path'];
        $workbookFilename = $existing['workbook_filename'];
        $workbookSize = $existing['workbook_size'];
        if (isset($data['workbook_path']) && trim((string) $data['workbook_path']) !== '') {
            $workbookPath = trim((string) $data['workbook_path']);
            $workbookFilename = trim((string) ($data['workbook_filename'] ?? ''));
            $workbookSize = max(0, (int) ($data['workbook_size'] ?? 0));
            if ($workbookFilename === '') {
                throw new \RuntimeException('A workbook file is required.');
            }
        }

        $promptPath = $existing['prompt_path'];
        $promptFilename = $existing['prompt_filename'];
        $promptSize = $existing['prompt_size'];
        if (!empty($data['clear_prompt'])) {
            $promptPath = '';
            $promptFilename = '';
            $promptSize = 0;
        } elseif (isset($data['prompt_path'])) {
            $promptPath = trim((string) $data['prompt_path']);
            $promptFilename = trim((string) ($data['prompt_filename'] ?? ''));
            $promptSize = max(0, (int) ($data['prompt_size'] ?? 0));
        }

        $statement = $this->pdo->prepare(
            'UPDATE template_workbooks
             SET name = :name,
                 workbook_path = :workbook_path,
                 workbook_filename = :workbook_filename,
                 workbook_size = :workbook_size,
                 prompt_path = :prompt_path,
                 prompt_filename = :prompt_filename,
                 prompt_size = :prompt_size
             WHERE id = :id'
        );
        $statement->execute([
            ':name' => $name,
            ':workbook_path' => $workbookPath,
            ':workbook_filename' => $workbookFilename,
            ':workbook_size' => $workbookSize,
            ':prompt_path' => $promptPath,
            ':prompt_filename' => $promptFilename,
            ':prompt_size' => $promptSize,
            ':id' => $id,
        ]);

        return true;
    }

    public function delete(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }

        $statement = $this->pdo->prepare('DELETE FROM template_workbooks WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: int,
     *   name: string,
     *   workbook_path: string,
     *   workbook_filename: string,
     *   workbook_size: int,
     *   prompt_path: string,
     *   prompt_filename: string,
     *   prompt_size: int,
     *   uploaded_by_user_id: int|null,
     *   uploaded_by_username: string,
     *   uploaded_by_display_name: string,
     *   uploaded_at: string
     * }
     */
    private function normalizeRow(array $row): array
    {
        $userId = isset($row['uploaded_by_user_id']) && $row['uploaded_by_user_id'] !== null
            ? (int) $row['uploaded_by_user_id']
            : null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'workbook_path' => (string) ($row['workbook_path'] ?? ''),
            'workbook_filename' => (string) ($row['workbook_filename'] ?? ''),
            'workbook_size' => (int) ($row['workbook_size'] ?? 0),
            'prompt_path' => (string) ($row['prompt_path'] ?? ''),
            'prompt_filename' => (string) ($row['prompt_filename'] ?? ''),
            'prompt_size' => (int) ($row['prompt_size'] ?? 0),
            'uploaded_by_user_id' => $userId,
            'uploaded_by_username' => (string) ($row['uploaded_by_username'] ?? ''),
            'uploaded_by_display_name' => (string) ($row['uploaded_by_display_name'] ?? ''),
            'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
        ];
    }
}
