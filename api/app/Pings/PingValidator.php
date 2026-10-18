<?php

namespace App\Pings;

/**
 * Hand-written checks instead of the validator component: the ingest route is the hot path, and this keeps it to plain
 * type checks. Returns a normalized payload that only carries known fields, with oversized parts trimmed.
 */
class PingValidator
{
    public const LEVELS = ['fatal', 'error', 'warning', 'info'];

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(private int $maxFrames, private int $maxContextLines) {}

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, list<string>>}
     */
    public function validate(array $input): array
    {
        $this->errors = [];
        $out = [];

        $message = $input['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            $this->fail('message', 'The message field is required and must be a string.');
        } elseif (mb_strlen($message) > 2000) {
            $this->fail('message', 'The message may not be longer than 2000 characters.');
        } else {
            $out['message'] = $message;
        }

        $level = $input['level'] ?? null;
        if (! is_string($level) || ! in_array($level, self::LEVELS, true)) {
            $this->fail('level', 'The level must be one of: '.implode(', ', self::LEVELS).'.');
        } else {
            $out['level'] = $level;
        }

        $environment = $input['environment'] ?? null;
        if (! is_string($environment) || ! in_array($environment, self::ENVIRONMENTS, true)) {
            $this->fail('environment', 'The environment must be one of: '.implode(', ', self::ENVIRONMENTS).'.');
        } else {
            $out['environment'] = $environment;
        }

        $occurredAt = $this->timestampMs($input['timestamp'] ?? null);
        if ($occurredAt === null) {
            $this->fail('timestamp', 'The timestamp must be milliseconds since the Unix epoch or an ISO 8601 date.');
        } else {
            $out['occurred_at'] = $occurredAt;
        }

        if (array_key_exists('exception', $input) && $input['exception'] !== null) {
            $out['exception'] = $this->exception($input['exception']);
        }
        if (array_key_exists('request', $input) && $input['request'] !== null) {
            $out['request'] = $this->request($input['request']);
        }
        if (array_key_exists('user', $input) && $input['user'] !== null) {
            $out['user'] = $this->user($input['user']);
        }
        if (array_key_exists('release', $input) && $input['release'] !== null) {
            $out['release'] = $this->string($input['release'], 'release', 200);
        }
        if (array_key_exists('sdk', $input) && $input['sdk'] !== null) {
            $out['sdk'] = $this->sdk($input['sdk']);
        }

        return $this->errors === [] ? [$out, []] : [null, $this->errors];
    }

    private function timestampMs(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $ms = (int) $value;
        } elseif (is_string($value) && $value !== '' && ! is_numeric($value)) {
            $time = date_create_immutable($value);
            if ($time === false) {
                return null;
            }
            $ms = (int) $time->format('Uv');
        } else {
            return null;
        }

        // 2000-01-01 to 2100-01-01: rejects seconds sent as milliseconds and other nonsense.
        return $ms >= 946684800000 && $ms < 4102444800000 ? $ms : null;
    }

    private function exception(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail('exception', 'The exception must be an object.');

            return null;
        }

        $out = [];
        if (isset($value['type'])) {
            $out['type'] = $this->string($value['type'], 'exception.type', 255);
        }

        $frames = $value['frames'] ?? null;
        if ($frames === null) {
            return $out;
        }
        if (! is_array($frames) || ! array_is_list($frames)) {
            $this->fail('exception.frames', 'The frames must be an array.');

            return $out;
        }

        $out['frames'] = [];
        foreach (array_slice($frames, 0, $this->maxFrames) as $i => $frame) {
            $out['frames'][] = $this->frame($frame, "exception.frames.{$i}");
        }

        return $out;
    }

    private function frame(mixed $frame, string $field): array
    {
        if (! is_array($frame) || array_is_list($frame)) {
            $this->fail($field, 'Each frame must be an object.');

            return [];
        }

        $out = [];
        foreach (['file' => 1024, 'function' => 255] as $key => $max) {
            if (isset($frame[$key])) {
                $out[$key] = $this->string($frame[$key], "{$field}.{$key}", $max);
            }
        }
        if (isset($frame['line'])) {
            if (is_int($frame['line']) && $frame['line'] >= 0) {
                $out['line'] = $frame['line'];
            } else {
                $this->fail("{$field}.line", 'The line must be a whole number.');
            }
        }
        if (isset($frame['in_app'])) {
            $out['in_app'] = (bool) $frame['in_app'];
        }
        if (isset($frame['context_line'])) {
            $out['context_line'] = $this->line($frame['context_line'], "{$field}.context_line");
        }
        foreach (['pre_context', 'post_context'] as $key) {
            if (! isset($frame[$key])) {
                continue;
            }
            if (! is_array($frame[$key]) || ! array_is_list($frame[$key])) {
                $this->fail("{$field}.{$key}", "The {$key} must be an array of strings.");

                continue;
            }
            // Keep the lines nearest the failing line.
            $lines = $key === 'pre_context'
                ? array_slice($frame[$key], -$this->maxContextLines)
                : array_slice($frame[$key], 0, $this->maxContextLines);
            $out[$key] = array_map(fn ($line) => $this->line($line, "{$field}.{$key}"), $lines);
        }

        return $out;
    }

    private function request(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail('request', 'The request must be an object.');

            return null;
        }

        $out = [];
        if (isset($value['method'])) {
            $out['method'] = $this->string($value['method'], 'request.method', 16);
        }
        if (isset($value['url'])) {
            $out['url'] = $this->string($value['url'], 'request.url', 2048);
        }
        foreach (['headers', 'query'] as $key) {
            if (! isset($value[$key])) {
                continue;
            }
            if (is_array($value[$key]) && ($value[$key] === [] || ! array_is_list($value[$key]))) {
                $out[$key] = $value[$key];
            } elseif ($key === 'query' && is_string($value[$key])) {
                $out[$key] = $value[$key];
            } else {
                $this->fail("request.{$key}", "The {$key} must be an object.");
            }
        }
        if (array_key_exists('body', $value)) {
            $out['body'] = $value['body'];
        }

        return $out;
    }

    private function user(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail('user', 'The user must be an object.');

            return null;
        }
        if (! isset($value['id'])) {
            return [];
        }
        if (is_int($value['id'])) {
            return ['id' => (string) $value['id']];
        }

        return ['id' => $this->string($value['id'], 'user.id', 255)];
    }

    private function sdk(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail('sdk', 'The sdk must be an object.');

            return null;
        }

        return [
            'name' => isset($value['name']) ? $this->string($value['name'], 'sdk.name', 64) : null,
            'version' => isset($value['version']) ? $this->string($value['version'], 'sdk.version', 32) : null,
        ];
    }

    private function string(mixed $value, string $field, int $max): ?string
    {
        if (! is_string($value)) {
            $this->fail($field, "The {$field} must be a string.");

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->fail($field, "The {$field} may not be longer than {$max} characters.");

            return null;
        }

        return $value;
    }

    // Source lines are trimmed rather than rejected; a minified bundle can have one enormous line.
    private function line(mixed $value, string $field): ?string
    {
        if (! is_string($value)) {
            $this->fail($field, 'Context lines must be strings.');

            return null;
        }

        return mb_substr($value, 0, 500);
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }
}
