<?php

/**
 * BugWatcher Pings PHP SDK: a single framework-free file. Reporting never throws and gives up after one second, so a
 * slow or unreachable BugWatcher API can't take the application down with it. Masking happens on the server; the SDK
 * only leaves the request body out unless sendRequestBody is switched on.
 */
final class BugWatcher
{
    public const VERSION = '0.1.0';

    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private const MAX_FRAMES = 50;

    private const CONTEXT_LINES = 10;

    /** @var callable|null */
    private $previousExceptionHandler;

    private ?string $userId = null;

    /** @var array<string, list<string>|false> */
    private array $sourceCache = [];

    public function __construct(
        private string $key,
        private string $endpoint,
        private string $environment = 'production',
        private ?string $release = null,
        private bool $sendRequestBody = false,
        private float $timeoutSeconds = 1.0,
    ) {}

    public function register(): self
    {
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        return $this;
    }

    public function setUser(string|int|null $id): void
    {
        $this->userId = $id === null ? null : (string) $id;
    }

    /**
     * Reports an exception and returns the event id, or null if it could not be sent.
     *
     * @param  array<string, mixed>  $request  Overrides the request context, for code that runs outside a web request.
     */
    public function captureException(Throwable $exception, string $level = 'error', array $request = []): ?string
    {
        try {
            return $this->send([
                'message' => $this->truncate($exception->getMessage() !== '' ? $exception->getMessage() : get_class($exception), 2000),
                'level' => $level,
                'exception' => ['type' => get_class($exception), 'frames' => $this->framesFor($exception)],
                'request' => $request !== [] ? $request : $this->currentRequest(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function handleException(Throwable $exception): void
    {
        $this->captureException($exception, 'fatal');

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);

            return;
        }

        error_log('PHP Fatal error:  Uncaught '.$exception);
        if (PHP_SAPI !== 'cli' && ! headers_sent()) {
            http_response_code(500);
        }
        exit(255);
    }

    public function handleShutdown(): void
    {
        try {
            $error = error_get_last();
            if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0) {
                return;
            }

            $this->send([
                'message' => $this->truncate($error['message'], 2000),
                'level' => 'fatal',
                'exception' => [
                    'type' => 'FatalError',
                    'frames' => [$this->frame($error['file'], $error['line'], null)],
                ],
                'request' => $this->currentRequest(),
            ]);
        } catch (Throwable) {
            // Never fail during shutdown.
        }
    }

    /**
     * Innermost frame first: where the exception was thrown, then each caller.
     *
     * @return list<array<string, mixed>>
     */
    private function framesFor(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $frames = [$this->frame($exception->getFile(), $exception->getLine(), $this->functionName($trace[0] ?? null))];

        foreach ($trace as $i => $call) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
            if (! isset($call['file'])) {
                continue;
            }
            $frames[] = $this->frame($call['file'], (int) ($call['line'] ?? 0), $this->functionName($trace[$i + 1] ?? null));
        }

        return $frames;
    }

    private function functionName(?array $call): ?string
    {
        if ($call === null) {
            return null;
        }

        return ($call['class'] ?? '').($call['type'] ?? '').$call['function'];
    }

    private function frame(string $file, int $line, ?string $function): array
    {
        $frame = [
            'file' => $file,
            'line' => $line,
            'function' => $function ?? '{main}',
            'in_app' => ! preg_match('#[/\\\\]vendor[/\\\\]#', $file),
        ];

        $lines = $this->sourceLines($file);
        if ($lines !== false && $line > 0 && isset($lines[$line - 1])) {
            $frame['context_line'] = $this->truncate(rtrim($lines[$line - 1], "\r\n"), 500);
            $frame['pre_context'] = $this->contextSlice($lines, max(0, $line - 1 - self::CONTEXT_LINES), min(self::CONTEXT_LINES, $line - 1));
            $frame['post_context'] = $this->contextSlice($lines, $line, self::CONTEXT_LINES);
        }

        return $frame;
    }

    /** @return list<string>|false */
    private function sourceLines(string $file): array|false
    {
        if (! array_key_exists($file, $this->sourceCache)) {
            $readable = is_file($file) && is_readable($file) && filesize($file) <= 1_000_000;
            $this->sourceCache[$file] = $readable ? (@file($file) ?: false) : false;
        }

        return $this->sourceCache[$file];
    }

    private function contextSlice(array $lines, int $offset, int $length): array
    {
        return array_map(fn ($line) => $this->truncate(rtrim($line, "\r\n"), 500), array_slice($lines, $offset, $length));
    }

    private function currentRequest(): ?array
    {
        if (PHP_SAPI === 'cli' || ! isset($_SERVER['REQUEST_METHOD'])) {
            return null;
        }

        $https = ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        $request = [
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').($_SERVER['REQUEST_URI'] ?? '/'),
            'headers' => $headers,
            'query' => $_GET,
        ];
        if ($this->sendRequestBody) {
            $request['body'] = $_POST;
        }

        return $request;
    }

    private function send(array $ping): ?string
    {
        if (! function_exists('curl_init')) {
            return null;
        }

        $ping += [
            'environment' => $this->environment,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'release' => $this->release,
            'user' => $this->userId === null ? null : ['id' => $this->userId],
            'sdk' => ['name' => 'bugwatcher-php', 'version' => self::VERSION],
        ];

        $body = json_encode(array_filter($ping, fn ($value) => $value !== null), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            return null;
        }

        $curl = curl_init($this->endpoint);
        $timeoutMs = (int) ($this->timeoutSeconds * 1000);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-BugWatcher-Key: '.$this->key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (! is_string($response) || $status !== 202) {
            return null;
        }
        $decoded = json_decode($response, true);

        return is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : null;
    }

    private function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
