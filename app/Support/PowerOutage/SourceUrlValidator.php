<?php

namespace App\Support\PowerOutage;

class SourceUrlValidator
{
    /** @param list<string> $allowedHosts */
    public function __construct(private array $allowedHosts = ['elektrodoboj.net', 'www.elektrodoboj.net']) {}

    public function validate(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, $this->allowedHosts, true)) {
            return null;
        }

        return $url;
    }
}
