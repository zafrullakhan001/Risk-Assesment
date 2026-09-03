<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class ProjectLinksRepository
{
    public const MAX_LINKS = 10;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<array{id: int, label: string, url: string, sort_order: int}> */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, label, url, sort_order
             FROM project_links
             WHERE assessment_id = :assessment_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $rows = $statement->fetchAll();

        $links = [];
        foreach ($rows as $row) {
            $links[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $links;
    }

    /**
     * Replace all links for an assessment (max 10).
     *
     * @param list<array{label?: string, url?: string}> $links
     */
    public function replaceForAssessment(int $assessmentId, array $links): bool
    {
        if ($assessmentId <= 0) {
            return false;
        }

        if (count($links) > self::MAX_LINKS) {
            throw new \InvalidArgumentException('A project can have at most ' . self::MAX_LINKS . ' links.');
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $normalized = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string) ($link['label'] ?? ''));
            $url = trim((string) ($link['url'] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            if ($label === '' || $url === '') {
                throw new \InvalidArgumentException('Each link needs both a label and a URL.');
            }
            if (mb_strlen($label) > 200) {
                $label = mb_substr($label, 0, 200);
            }
            if (mb_strlen($url) > 2000) {
                throw new \InvalidArgumentException('Link URL is too long.');
            }
            if (!$this->isAllowedUrl($url)) {
                throw new \InvalidArgumentException('Links must use http:// or https:// URLs.');
            }
            $normalized[] = ['label' => $label, 'url' => $url];
        }

        if (count($normalized) > self::MAX_LINKS) {
            throw new \InvalidArgumentException('A project can have at most ' . self::MAX_LINKS . ' links.');
        }

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM project_links WHERE assessment_id = :assessment_id');
            $delete->execute([':assessment_id' => $assessmentId]);

            if ($normalized !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO project_links (assessment_id, label, url, sort_order, updated_at)
                     VALUES (:assessment_id, :label, :url, :sort_order, datetime(\'now\'))'
                );
                foreach ($normalized as $index => $link) {
                    $insert->execute([
                        ':assessment_id' => $assessmentId,
                        ':label' => $link['label'],
                        ':url' => $link['url'],
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

        $statement = $this->pdo->prepare('DELETE FROM project_links WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    private function isAllowedUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
