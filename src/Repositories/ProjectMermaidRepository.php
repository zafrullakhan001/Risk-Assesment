<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class ProjectMermaidRepository
{
    public const MAX_DIAGRAMS = 10;

    private const MAX_TITLE_LENGTH = 200;
    private const MAX_SOURCE_LENGTH = 50000;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<array{id: int, title: string, source: string, sort_order: int}> */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, title, source, sort_order
             FROM project_mermaid_diagrams
             WHERE assessment_id = :assessment_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $rows = $statement->fetchAll();

        $diagrams = [];
        foreach ($rows as $row) {
            $diagrams[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $diagrams;
    }

    /**
     * Replace all diagrams for an assessment (max 10).
     *
     * @param list<array{title?: string, source?: string}> $diagrams
     */
    public function replaceForAssessment(int $assessmentId, array $diagrams): bool
    {
        if ($assessmentId <= 0) {
            return false;
        }

        if (count($diagrams) > self::MAX_DIAGRAMS) {
            throw new \InvalidArgumentException('A project can have at most ' . self::MAX_DIAGRAMS . ' diagrams.');
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $normalized = [];
        foreach ($diagrams as $diagram) {
            if (!is_array($diagram)) {
                continue;
            }
            $title = trim((string) ($diagram['title'] ?? ''));
            $source = trim((string) ($diagram['source'] ?? ''));
            if ($title === '' && $source === '') {
                continue;
            }
            if ($title === '' || $source === '') {
                throw new \InvalidArgumentException('Each diagram needs both a title and source code.');
            }
            if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
            }
            if (mb_strlen($source) > self::MAX_SOURCE_LENGTH) {
                throw new \InvalidArgumentException('Diagram source is too long.');
            }
            $normalized[] = ['title' => $title, 'source' => $source];
        }

        if (count($normalized) > self::MAX_DIAGRAMS) {
            throw new \InvalidArgumentException('A project can have at most ' . self::MAX_DIAGRAMS . ' diagrams.');
        }

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM project_mermaid_diagrams WHERE assessment_id = :assessment_id');
            $delete->execute([':assessment_id' => $assessmentId]);

            if ($normalized !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO project_mermaid_diagrams (assessment_id, title, source, sort_order, updated_at)
                     VALUES (:assessment_id, :title, :source, :sort_order, datetime(\'now\'))'
                );
                foreach ($normalized as $index => $diagram) {
                    $insert->execute([
                        ':assessment_id' => $assessmentId,
                        ':title' => $diagram['title'],
                        ':source' => $diagram['source'],
                        ':sort_order' => $index,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return true;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM project_mermaid_diagrams WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }
}
