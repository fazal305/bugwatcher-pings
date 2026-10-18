<?php

namespace App\Pings;

/**
 * Groups repeats of one error into one issue. Line numbers are left out on purpose, so an error keeps its issue when
 * code above it moves; the message is only used when there is no usable stack frame.
 */
class Fingerprinter
{
    /**
     * @param  list<array<string, mixed>>  $frames
     */
    public function topFrame(array $frames): ?array
    {
        foreach ($frames as $frame) {
            if ($frame['in_app'] ?? ! $this->looksLikeLibrary((string) ($frame['file'] ?? ''))) {
                return $frame;
            }
        }

        return $frames[0] ?? null;
    }

    public function fingerprint(int $projectId, ?string $type, ?array $topFrame, string $message): string
    {
        $parts = [(string) $projectId, (string) $type];

        if ($topFrame !== null && (! empty($topFrame['file']) || ! empty($topFrame['function']))) {
            $parts[] = $this->normalizeFile((string) ($topFrame['file'] ?? '')).':'.$this->normalizeFunction((string) ($topFrame['function'] ?? ''));
        } else {
            $parts[] = $this->normalizeMessage($message);
        }

        return hash('sha256', implode("\n", $parts));
    }

    public function displayFile(string $file): string
    {
        $file = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $file);

        return preg_replace('/[?#].*$/', '', str_replace('\\', '/', $file));
    }

    public function normalizeFile(string $file): string
    {
        // Build hashes in bundle names ("index-3fa9c1d2.js") change on every deploy.
        return strtolower(preg_replace('/[.-](?=[a-z0-9]*\d)[a-z0-9]{8,}(?=\.)/i', '', $this->displayFile($file)));
    }

    public function normalizeFunction(string $function): string
    {
        return preg_replace('/\{closure[^}]*\}/', '{closure}', trim($function));
    }

    public function normalizeMessage(string $message): string
    {
        $message = preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '/\b0x[0-9a-f]+\b/i',
            '/\b[0-9a-f]{8,}\b/i',
            '/\d+(\.\d+)?/',
            '/\s+/',
        ], ['<uuid>', '<hex>', '<hex>', '<n>', ' '], $message);

        return mb_substr(strtolower(trim($message)), 0, 500);
    }

    private function looksLikeLibrary(string $file): bool
    {
        return $file === '' || preg_match('#[/\\\\](vendor|node_modules)[/\\\\]|^(node|internal)[:/]#', $file) === 1;
    }
}
