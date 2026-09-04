<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Normalizes assessment dates stored as ISO strings or Excel serial day numbers.
 */
final class AssessmentDate
{
    /**
     * Convert workbook / DB date values to Y-m-d when possible.
     * Leaves unrecognized values unchanged so search still works.
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^\d{4,5}(?:\.\d+)?$/', $value) === 1) {
            $serial = (float) $value;
            // Excel day serials covering roughly 1900–2173.
            if ($serial >= 1.0 && $serial < 100000.0) {
                $unix = (int) round(($serial - 25569.0) * 86400.0);
                if ($unix > 0) {
                    return gmdate('Y-m-d', $unix);
                }
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return $value;
    }

    public static function display(string $value, string $emptyLabel = 'No date'): string
    {
        $normalized = self::normalize($value);

        return $normalized !== '' ? $normalized : $emptyLabel;
    }
}
