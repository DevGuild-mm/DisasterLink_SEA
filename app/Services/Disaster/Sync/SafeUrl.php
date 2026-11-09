<?php

namespace App\Services\Disaster\Sync;

/**
 * Validates URLs that come from upstream data before they are stored (HLD §20.3).
 */
final class SafeUrl
{
    /**
     * Return an https URL on an allowed host, or null. Plain http is upgraded to https.
     *
     * @param  array<int, string>  $allowedHosts
     */
    public static function validate(?string $url, array $allowedHosts): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || strlen($url) > 500) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return 'https://'.$host
                    .(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '')
                    .($parts['path'] ?? '')
                    .(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return null;
    }
}
