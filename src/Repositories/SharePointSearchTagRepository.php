<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

/**
 * Admin-managed search tags for SharePoint project folders and files.
 * Assignments are keyed by source + project + relative path so they survive resync.
 */
final class SharePointSearchTagRepository
{
    public const LABEL_MAX_LENGTH = 40;
    public const MAX_TAGS_PER_TARGET = 12;
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_ITEM = 'item';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public static function normalizeLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $label = strip_tags($label);
        $label = preg_replace('/[\x00-\x1F\x7F]/', '', $label) ?? '';
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return trim(mb_substr($label, 0, self::LABEL_MAX_LENGTH));
        }

        return trim(substr($label, 0, self::LABEL_MAX_LENGTH));
    }

    public static function slugify(string $label): string
    {
        $label = self::normalizeLabel($label);
        if ($label === '') {
            return '';
        }
        $slug = function_exists('mb_strtolower')
            ? mb_strtolower($label)
            : strtolower($label);
        $slug = preg_replace('/\s+/u', '-', $slug) ?? '';
        $slug = preg_replace('/[^\p{L}\p{N}\-_]+/u', '', $slug) ?? '';
        $slug = trim($slug, '-_');

        return $slug;
    }

    /**
     * @return list<array{id: int, label: string, slug: string, created_by_username: string, created_at: string, updated_at: string}>
     */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, label, slug, created_by_username, created_at, updated_at
             FROM sharepoint_search_tags
             ORDER BY LOWER(label) ASC, id ASC'
        );
        $rows = $statement === false ? [] : ($statement->fetchAll() ?: []);

        return array_map([$this, 'mapTag'], $rows);
    }

    /**
     * @return array{id: int, label: string, slug: string, created_by_username: string, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, label, slug, created_by_username, created_at, updated_at
             FROM sharepoint_search_tags WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->mapTag($row) : null;
    }

    /**
     * @param array{id?: int, username?: string}|null $actor
     * @return array{id: int, label: string, slug: string, created_by_username: string, created_at: string, updated_at: string}
     */
    public function create(string $label, ?array $actor = null): array
    {
        $label = self::normalizeLabel($label);
        if ($label === '') {
            throw new \RuntimeException('Enter a tag name.');
        }
        $slug = self::slugify($label);
        if ($slug === '') {
            throw new \RuntimeException('Tag name must include letters or numbers.');
        }

        $username = trim((string) ($actor['username'] ?? ''));
        $userId = isset($actor['id']) ? (int) $actor['id'] : null;

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO sharepoint_search_tags (
                    label, slug, created_by_user_id, created_by_username, created_at, updated_at
                 ) VALUES (
                    :label, :slug, :created_by_user_id, :created_by_username, datetime(\'now\'), datetime(\'now\')
                 )'
            );
            $insert->execute([
                ':label' => $label,
                ':slug' => $slug,
                ':created_by_user_id' => $userId > 0 ? $userId : null,
                ':created_by_username' => $username,
            ]);
        } catch (\PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new \RuntimeException('A tag with that name already exists.');
            }
            throw $exception;
        }

        $id = (int) $this->pdo->lastInsertId();
        $tag = $this->findById($id);
        if ($tag === null) {
            throw new \RuntimeException('Tag was created but could not be loaded.');
        }

        return $tag;
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new \RuntimeException('Invalid tag.');
        }
        $this->pdo->beginTransaction();
        try {
            $delAssign = $this->pdo->prepare('DELETE FROM sharepoint_search_tag_assignments WHERE tag_id = :id');
            $delAssign->execute([':id' => $id]);
            $delTag = $this->pdo->prepare('DELETE FROM sharepoint_search_tags WHERE id = :id');
            $delTag->execute([':id' => $id]);
            if ($delTag->rowCount() < 1) {
                throw new \RuntimeException('Tag not found.');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Replace all project-level tags for a project folder.
     *
     * @param list<int|string> $tagIds
     * @return list<array{id: int, label: string, slug: string}>
     */
    public function setProjectTags(string $sourceKey, string $projectName, array $tagIds): array
    {
        return $this->setTags($sourceKey, self::SCOPE_PROJECT, $projectName, '', $tagIds);
    }

    /**
     * Replace all item-level tags for a file/folder path inside a project.
     *
     * @param list<int|string> $tagIds
     * @return list<array{id: int, label: string, slug: string}>
     */
    public function setItemTags(string $sourceKey, string $projectName, string $relativePath, array $tagIds): array
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '') {
            throw new \RuntimeException('Item path is required.');
        }

        return $this->setTags($sourceKey, self::SCOPE_ITEM, $projectName, $relativePath, $tagIds);
    }

    /**
     * Project-level tags keyed by "source_key\0project_name".
     *
     * @param list<string> $sourceKeys
     * @return array<string, list<array{id: int, label: string, slug: string}>>
     */
    public function mapProjectTagsForSources(array $sourceKeys): array
    {
        $sourceKeys = array_values(array_filter(array_map('trim', $sourceKeys)));
        if ($sourceKeys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT a.source_key, a.project_name, t.id, t.label, t.slug
             FROM sharepoint_search_tag_assignments a
             INNER JOIN sharepoint_search_tags t ON t.id = a.tag_id
             WHERE a.scope = 'project'
               AND a.source_key IN ($placeholders)
             ORDER BY LOWER(t.label) ASC, t.id ASC"
        );
        $statement->execute($sourceKeys);
        $rows = $statement->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['source_key'] ?? '') . "\0" . (string) ($row['project_name'] ?? '');
            $out[$key][] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Item-level tags keyed by "source_key\0project_name\0relative_path".
     *
     * @param list<string> $sourceKeys
     * @return array<string, list<array{id: int, label: string, slug: string}>>
     */
    public function mapItemTagsForSources(array $sourceKeys): array
    {
        $sourceKeys = array_values(array_filter(array_map('trim', $sourceKeys)));
        if ($sourceKeys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT a.source_key, a.project_name, a.relative_path, t.id, t.label, t.slug
             FROM sharepoint_search_tag_assignments a
             INNER JOIN sharepoint_search_tags t ON t.id = a.tag_id
             WHERE a.scope = 'item'
               AND a.source_key IN ($placeholders)
             ORDER BY LOWER(t.label) ASC, t.id ASC"
        );
        $statement->execute($sourceKeys);
        $rows = $statement->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['source_key'] ?? '') . "\0"
                . (string) ($row['project_name'] ?? '') . "\0"
                . (string) ($row['relative_path'] ?? '');
            $out[$key][] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    public function listProjectTags(string $sourceKey, string $projectName): array
    {
        $map = $this->mapProjectTagsForSources([$sourceKey]);
        $key = trim($sourceKey) . "\0" . trim($projectName);

        return $map[$key] ?? [];
    }

    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    public function listItemTags(string $sourceKey, string $projectName, string $relativePath): array
    {
        $map = $this->mapItemTagsForSources([$sourceKey]);
        $key = trim($sourceKey) . "\0" . trim($projectName) . "\0" . trim(str_replace('\\', '/', $relativePath));

        return $map[$key] ?? [];
    }

    /**
     * Delete tag assignments for the given sources. Optionally remove unused vocabulary.
     *
     * @param list<string> $sourceKeys
     * @return array{assignments_deleted: int, tags_deleted: int}
     */
    public function purgeForSources(array $sourceKeys, bool $deleteUnusedTags = false): array
    {
        $sourceKeys = array_values(array_filter(array_map('trim', $sourceKeys)));
        if ($sourceKeys === []) {
            return ['assignments_deleted' => 0, 'tags_deleted' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $delete = $this->pdo->prepare(
            "DELETE FROM sharepoint_search_tag_assignments WHERE source_key IN ($placeholders)"
        );
        $delete->execute($sourceKeys);
        $assignmentsDeleted = $delete->rowCount();

        $tagsDeleted = 0;
        if ($deleteUnusedTags) {
            $tagsDeleted = $this->deleteUnusedTags();
        }

        return [
            'assignments_deleted' => $assignmentsDeleted,
            'tags_deleted' => $tagsDeleted,
        ];
    }

    public function deleteUnusedTags(): int
    {
        $statement = $this->pdo->exec(
            'DELETE FROM sharepoint_search_tags
             WHERE id NOT IN (SELECT DISTINCT tag_id FROM sharepoint_search_tag_assignments)'
        );

        return $statement === false ? 0 : (int) $statement;
    }

    public function countTags(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM sharepoint_search_tags');

        return $value === false ? 0 : (int) $value->fetchColumn();
    }

    public function countAssignments(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM sharepoint_search_tag_assignments');

        return $value === false ? 0 : (int) $value->fetchColumn();
    }

    /**
     * Attach project- and item-level tags onto a search-index project list (mutates in place).
     *
     * @param list<array<string, mixed>> $projects
     * @param array<string, list<array{id: int, label: string, slug: string}>> $projectTagMap
     * @param array<string, list<array{id: int, label: string, slug: string}>> $itemTagMap
     * @return list<array<string, mixed>>
     */
    public function attachTagsToSearchIndex(array $projects, array $projectTagMap, array $itemTagMap): array
    {
        foreach ($projects as &$project) {
            $sourceKey = (string) ($project['source_key'] ?? '');
            $projectName = (string) ($project['project_name'] ?? '');
            $pKey = $sourceKey . "\0" . $projectName;
            $project['tags'] = $projectTagMap[$pKey] ?? [];

            if (isset($project['files']) && is_array($project['files'])) {
                foreach ($project['files'] as &$file) {
                    $path = (string) ($file['path'] ?? '');
                    $iKey = $sourceKey . "\0" . $projectName . "\0" . $path;
                    $file['tags'] = $itemTagMap[$iKey] ?? [];
                }
                unset($file);
            }
            if (isset($project['folders']) && is_array($project['folders'])) {
                foreach ($project['folders'] as &$folder) {
                    $path = (string) ($folder['path'] ?? '');
                    $iKey = $sourceKey . "\0" . $projectName . "\0" . $path;
                    $folder['tags'] = $itemTagMap[$iKey] ?? [];
                }
                unset($folder);
            }
        }
        unset($project);

        return $projects;
    }

    /**
     * @param array{project_name: string, folder_url: string, items: list<array<string, mixed>>} $detail
     * @param list<array{id: int, label: string, slug: string}> $projectTags
     * @param array<string, list<array{id: int, label: string, slug: string}>> $itemTagMap keyed by source\0project\0path
     * @return array{project_name: string, folder_url: string, items: list<array<string, mixed>>, tags: list<array{id: int, label: string, slug: string}>}
     */
    public function attachTagsToProjectDetail(
        array $detail,
        string $sourceKey,
        array $projectTags,
        array $itemTagMap
    ): array {
        $projectName = (string) ($detail['project_name'] ?? '');
        $detail['tags'] = $projectTags;
        if (isset($detail['items']) && is_array($detail['items'])) {
            foreach ($detail['items'] as &$item) {
                $path = trim((string) ($item['relative_path'] ?? ''));
                if ($path === '') {
                    $path = trim((string) ($item['name'] ?? ''));
                }
                $iKey = $sourceKey . "\0" . $projectName . "\0" . $path;
                $item['tags'] = $itemTagMap[$iKey] ?? [];
            }
            unset($item);
        }

        return $detail;
    }

    /**
     * @param list<int|string> $tagIds
     * @return list<array{id: int, label: string, slug: string}>
     */
    private function setTags(
        string $sourceKey,
        string $scope,
        string $projectName,
        string $relativePath,
        array $tagIds
    ): array {
        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        $scope = $scope === self::SCOPE_ITEM ? self::SCOPE_ITEM : self::SCOPE_PROJECT;

        if ($sourceKey === '' || $projectName === '') {
            throw new \RuntimeException('Source and project name are required.');
        }
        if ($scope === self::SCOPE_PROJECT) {
            $relativePath = '';
        } elseif ($relativePath === '') {
            throw new \RuntimeException('Item path is required.');
        }

        $ids = [];
        foreach ($tagIds as $raw) {
            $id = (int) $raw;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (count($ids) > self::MAX_TAGS_PER_TARGET) {
            throw new \RuntimeException(
                'A project or file can have at most ' . self::MAX_TAGS_PER_TARGET . ' tags.'
            );
        }

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $check = $this->pdo->prepare(
                "SELECT id FROM sharepoint_search_tags WHERE id IN ($placeholders)"
            );
            $check->execute($ids);
            $found = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN) ?: []);
            sort($ids);
            $foundSorted = $found;
            sort($foundSorted);
            if ($foundSorted !== $ids) {
                throw new \RuntimeException('One or more tags were not found.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM sharepoint_search_tag_assignments
                 WHERE source_key = :source_key
                   AND scope = :scope
                   AND project_name = :project_name
                   AND relative_path = :relative_path'
            );
            $delete->execute([
                ':source_key' => $sourceKey,
                ':scope' => $scope,
                ':project_name' => mb_substr($projectName, 0, 500),
                ':relative_path' => mb_substr($relativePath, 0, 2000),
            ]);

            if ($ids !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO sharepoint_search_tag_assignments (
                        tag_id, source_key, scope, project_name, relative_path, created_at
                     ) VALUES (
                        :tag_id, :source_key, :scope, :project_name, :relative_path, datetime(\'now\')
                     )'
                );
                foreach ($ids as $id) {
                    $insert->execute([
                        ':tag_id' => $id,
                        ':source_key' => $sourceKey,
                        ':scope' => $scope,
                        ':project_name' => mb_substr($projectName, 0, 500),
                        ':relative_path' => mb_substr($relativePath, 0, 2000),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        if ($scope === self::SCOPE_PROJECT) {
            return $this->listProjectTags($sourceKey, $projectName);
        }

        return $this->listItemTags($sourceKey, $projectName, $relativePath);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, label: string, slug: string, created_by_username: string, created_at: string, updated_at: string}
     */
    private function mapTag(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'created_by_username' => (string) ($row['created_by_username'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
