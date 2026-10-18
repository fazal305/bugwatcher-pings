<?php

namespace App\Pings;

class Masker
{
    public const FILTERED = '[Filtered]';

    /** @var list<string> */
    private array $substrings = [];

    /** @var list<string> */
    private array $words = [];

    /**
     * Keys are compared without separators or case, so "user_password", "X-Api-Key" and "accessToken" all match.
     * Very short names (ssn, cvv) only match a whole word, or "className" would be masked for containing "ssn".
     *
     * @param  list<string>  $sensitiveKeys
     */
    public function __construct(array $sensitiveKeys)
    {
        foreach ($sensitiveKeys as $key) {
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key));
            if (strlen($normalized) <= 3) {
                $this->words[] = $normalized;
            } else {
                $this->substrings[] = $normalized;
            }
        }
        $this->substrings = array_values(array_unique($this->substrings));
    }

    public function isSensitive(string|int $key): bool
    {
        $key = (string) $key;
        $words = array_map('strtolower', preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY));
        $joined = implode('', $words);

        foreach ($this->substrings as $needle) {
            if (str_contains($joined, $needle)) {
                return true;
            }
        }

        return array_intersect($words, $this->words) !== [];
    }

    public function mask(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_string($key) && $this->isSensitive($key) ? self::FILTERED : $this->mask($item);
        }

        return $value;
    }

    public function maskUrl(string $url): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $query = $fragmentStart === false ? substr($url, $queryStart + 1) : substr($url, $queryStart + 1, $fragmentStart - $queryStart - 1);
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);

        return substr($url, 0, $queryStart + 1).$this->maskQueryString($query).$fragment;
    }

    public function maskQueryString(string $query): string
    {
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            [$name] = explode('=', $pair, 2);
            // Strip array brackets so "card[number]" is checked as "card number".
            if ($name !== '' && $this->isSensitive(str_replace(['[', ']'], ' ', rawurldecode(str_replace('+', ' ', $name))))) {
                $pairs[$i] = $name.'='.self::FILTERED;
            }
        }

        return implode('&', $pairs);
    }
}
