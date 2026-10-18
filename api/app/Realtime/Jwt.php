<?php

namespace App\Realtime;

use InvalidArgumentException;
use JsonException;

/**
 * Minimal HS256 JSON Web Tokens: only the one algorithm this app issues is accepted, whatever the header says.
 */
final class Jwt
{
    private const HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('JWT_SECRET must be at least 32 characters.');
        }
    }

    /** @param array<string, mixed> $claims */
    public function encode(array $claims): string
    {
        $signingInput = self::base64UrlEncode(json_encode(self::HEADER, JSON_THROW_ON_ERROR))
            .'.'.self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $signingInput.'.'.self::base64UrlEncode($this->sign($signingInput));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidToken
     */
    public function decode(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidToken('Malformed token.');
        }
        [$header, $payload, $signature] = $parts;

        $headerData = self::decodeJson($header);
        if (($headerData['alg'] ?? null) !== 'HS256') {
            throw new InvalidToken('Unsupported algorithm.');
        }

        $givenSignature = self::base64UrlDecode($signature);
        if ($givenSignature === null || ! hash_equals($this->sign($header.'.'.$payload), $givenSignature)) {
            throw new InvalidToken('Bad signature.');
        }

        $claims = self::decodeJson($payload);
        $now ??= time();
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new InvalidToken('Expired.');
        }
        if (is_int($claims['nbf'] ?? null) && $claims['nbf'] > $now) {
            throw new InvalidToken('Not valid yet.');
        }

        return $claims;
    }

    private function sign(string $input): string
    {
        return hash_hmac('sha256', $input, $this->secret, true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $segment): array
    {
        $raw = self::base64UrlDecode($segment);
        if ($raw === null) {
            throw new InvalidToken('Malformed token.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidToken('Malformed token.');
        }
        if (! is_array($data)) {
            throw new InvalidToken('Malformed token.');
        }

        return $data;
    }
}
