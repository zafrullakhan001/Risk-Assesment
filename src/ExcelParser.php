<?php

declare(strict_types=1);

namespace RiskAssessment;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RiskAssessment\Models\Assessment;

final class ExcelParser
{
    private const METADATA_ROW_START = 2;
    private const METADATA_ROW_END = 7;
    private const HEADER_ROW = 8;
    private const DATA_ROW_START = 9;

    private const METADATA_MAP = [
        'solutionname' => 'solution_name',
        'vendor' => 'vendor',
        'scope' => 'scope',
        'architecturemodel' => 'architecture_model',
        'reviewer' => 'reviewer',
        'date' => 'date',
    ];

    public function parse(string $filePath): Assessment
    {
        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);

        $metadata = $this->parseMetadata($sheet);
        $columnMap = $this->parseHeaderRow($sheet);
        $items = $this->parseDataRows($sheet, $columnMap);

        if ($items === []) {
            throw new \InvalidArgumentException('No risk assessment rows were found in the spreadsheet.');
        }

        return Assessment::fromParsedData($metadata, $items);
    }

    /** @return array<string, string> */
    private function parseMetadata(Worksheet $sheet): array
    {
        $metadata = [
            'solution_name' => '',
            'vendor' => '',
            'scope' => '',
            'architecture_model' => '',
            'reviewer' => '',
            'date' => '',
        ];

        for ($row = self::METADATA_ROW_START; $row <= self::METADATA_ROW_END; $row++) {
            $label = $this->cellValue($sheet, 'B', $row);
            $value = $this->cellValue($sheet, 'C', $row);

            if ($label === '' && $value === '') {
                continue;
            }

            $normalizedLabel = $this->normalizeKey($label);
            if (isset(self::METADATA_MAP[$normalizedLabel])) {
                $metadata[self::METADATA_MAP[$normalizedLabel]] = $value;
            }
        }

        return $metadata;
    }

    /** @return array<string, string> column letter => field name */
    private function parseHeaderRow(Worksheet $sheet): array
    {
        $highestColumn = $sheet->getHighestColumn(self::HEADER_ROW);
        $columnMap = [];
        $foundFields = [];

        foreach ($this->columnRange('A', $highestColumn) as $column) {
            $header = $this->cellValue($sheet, $column, self::HEADER_ROW);
            if ($header === '') {
                continue;
            }

            $normalized = $this->normalizeKey($header);

            $field = match (true) {
                $normalized === 'section' => 'section',
                $normalized === 'check' => 'check',
                str_starts_with($normalized, 'status') => 'status',
                str_starts_with($normalized, 'risklevel') => 'risk_level',
                $normalized === 'notes' => 'notes',
                str_starts_with($normalized, 'mitigation') => 'mitigation',
                $normalized === 'owner' => 'owner',
                str_starts_with($normalized, 'remediationtimeline') => 'remediation_timeline',
                default => null,
            };

            if ($field !== null) {
                $columnMap[$column] = $field;
                $foundFields[$field] = true;
            }
        }

        $requiredFields = ['section', 'check', 'status', 'risk_level'];
        $missingFields = array_diff($requiredFields, array_keys($foundFields));

        if ($missingFields !== []) {
            throw new \InvalidArgumentException('The spreadsheet header row is missing required columns. Expected the Architecture Risk Assessment Data Sheet format.');
        }

        return $columnMap;
    }

    /** @param array<string, string> $columnMap */
    /** @return list<array<string, string>> */
    private function parseDataRows(Worksheet $sheet, array $columnMap): array
    {
        $items = [];
        $currentSection = '';
        $highestRow = $sheet->getHighestRow();
        $sortOrder = 0;

        for ($row = self::DATA_ROW_START; $row <= $highestRow; $row++) {
            $rowValues = [];

            foreach ($columnMap as $column => $field) {
                $rowValues[$field] = $this->cellValue($sheet, $column, $row);
            }

            if ($this->isEmptyRow($rowValues)) {
                break;
            }

            $section = trim($rowValues['section'] ?? '');
            if ($section !== '') {
                $currentSection = $section;
            }

            $check = trim($rowValues['check'] ?? '');
            if ($check === '') {
                continue;
            }

            $items[] = [
                'section' => $currentSection,
                'check' => $check,
                'status' => Assessment::normalizeStatus($rowValues['status'] ?? ''),
                'risk_level' => Assessment::normalizeRiskLevel($rowValues['risk_level'] ?? ''),
                'notes' => trim($rowValues['notes'] ?? ''),
                'mitigation' => trim($rowValues['mitigation'] ?? ''),
                'owner' => trim($rowValues['owner'] ?? ''),
                'remediation_timeline' => trim($rowValues['remediation_timeline'] ?? ''),
                'sort_order' => (string) $sortOrder++,
            ];
        }

        return $items;
    }

    private function cellValue(Worksheet $sheet, string $column, int $row): string
    {
        $value = $sheet->getCell($column . $row)->getCalculatedValue();

        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /** @param array<string, string> $rowValues */
    private function isEmptyRow(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }

    /** @return list<string> */
    private function columnRange(string $start, string $end): array
    {
        $columns = [];
        $current = $start;

        while (true) {
            $columns[] = $current;
            if ($current === $end) {
                break;
            }
            $current = $this->nextColumn($current);
        }

        return $columns;
    }

    private function nextColumn(string $column): string
    {
        $length = strlen($column);
        $chars = str_split($column);
        $index = $length - 1;

        while ($index >= 0) {
            if ($chars[$index] !== 'Z') {
                $chars[$index] = chr(ord($chars[$index]) + 1);
                return implode('', $chars);
            }

            $chars[$index] = 'A';
            $index--;
        }

        return 'A' . implode('', $chars);
    }
}
