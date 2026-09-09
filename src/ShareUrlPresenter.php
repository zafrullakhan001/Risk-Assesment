<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Compact display helpers for long public share URLs.
 * Copy / Open always use the full URL; the UI shows a middle-truncated preview.
 */
final class ShareUrlPresenter
{
    /**
     * Shorten a share URL for on-screen display, e.g.
     * localhost/…/catalog-share.php?t=ad3016ad…f76b3bd
     */
    public static function truncate(string $url, int $tokenHead = 8, int $tokenTail = 7): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return self::ellipsisMiddle($url, 28, 10);
        }

        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $file = $path !== '' ? basename($path) : '';

        $token = '';
        if ($query !== '') {
            parse_str($query, $params);
            $token = trim((string) ($params['t'] ?? $params['token'] ?? ''));
        }

        if ($host === '' && $file === '') {
            return self::ellipsisMiddle($url, 28, 10);
        }

        $preview = $host !== '' ? $host : '';
        if ($file !== '') {
            $preview .= ($preview !== '' ? '/…/' : '') . $file;
        }

        if ($token !== '') {
            $tokenHead = max(4, $tokenHead);
            $tokenTail = max(4, $tokenTail);
            if (strlen($token) <= $tokenHead + $tokenTail + 1) {
                $preview .= '?t=' . $token;
            } else {
                $preview .= '?t=' . substr($token, 0, $tokenHead) . '…' . substr($token, -$tokenTail);
            }
        } elseif ($query !== '') {
            $preview .= '?' . self::ellipsisMiddle($query, 12, 6);
        }

        return $preview !== '' ? $preview : self::ellipsisMiddle($url, 28, 10);
    }

    private static function ellipsisMiddle(string $value, int $head, int $tail): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) <= $head + $tail + 1) {
            return $value;
        }

        return substr($value, 0, $head) . '…' . substr($value, -$tail);
    }
}
