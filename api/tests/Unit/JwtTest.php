<?php

namespace Tests\Unit;

use App\Realtime\InvalidToken;
use App\Realtime\Jwt;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JwtTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789abcdefghij';

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function test_a_token_round_trips(): void
    {
        $jwt = new Jwt(self::SECRET);
        $claims = ['sub' => '7', 'exp' => time() + 60, 'projects' => ['shop-abc123']];

        $token = $jwt->encode($claims);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true));
        $this->assertSame($claims, $jwt->decode($token));
    }

    public function test_the_signature_matches_a_reference_hs256_implementation(): void
    {
        $header = $this->b64('{"alg":"HS256","typ":"JWT"}');
        $payload = $this->b64('{"sub":"1","exp":4102444800}');
        $signature = $this->b64(hash_hmac('sha256', "$header.$payload", self::SECRET, true));

        $this->assertSame(['sub' => '1', 'exp' => 4102444800], (new Jwt(self::SECRET))->decode("$header.$payload.$signature"));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $jwt = new Jwt(self::SECRET);
        $token = $jwt->encode(['sub' => '1', 'exp' => 1000]);

        $this->expectException(InvalidToken::class);
        $jwt->decode($token, now: 1000);
    }

    public function test_a_token_without_an_expiry_is_rejected(): void
    {
        $jwt = new Jwt(self::SECRET);

        $this->expectException(InvalidToken::class);
        $jwt->decode($jwt->encode(['sub' => '1']));
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $jwt = new Jwt(self::SECRET);
        [$header, , $signature] = explode('.', $jwt->encode(['sub' => '1', 'exp' => time() + 60]));
        $forged = $this->b64(json_encode(['sub' => '2', 'exp' => time() + 60]));

        $this->expectException(InvalidToken::class);
        $jwt->decode("$header.$forged.$signature");
    }

    public function test_a_token_signed_with_another_secret_is_rejected(): void
    {
        $token = (new Jwt('another-secret-0123456789abcdefghijkl'))->encode(['sub' => '1', 'exp' => time() + 60]);

        $this->expectException(InvalidToken::class);
        (new Jwt(self::SECRET))->decode($token);
    }

    public function test_the_none_algorithm_is_rejected(): void
    {
        $header = $this->b64('{"alg":"none","typ":"JWT"}');
        $payload = $this->b64(json_encode(['sub' => '1', 'exp' => time() + 60]));

        foreach (["$header.$payload.", "$header.$payload"] as $token) {
            try {
                (new Jwt(self::SECRET))->decode($token);
                $this->fail('An unsigned token was accepted.');
            } catch (InvalidToken) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_another_algorithm_in_the_header_is_rejected_even_with_a_valid_hs256_signature(): void
    {
        $header = $this->b64('{"alg":"HS512","typ":"JWT"}');
        $payload = $this->b64(json_encode(['sub' => '1', 'exp' => time() + 60]));
        $signature = $this->b64(hash_hmac('sha256', "$header.$payload", self::SECRET, true));

        $this->expectException(InvalidToken::class);
        (new Jwt(self::SECRET))->decode("$header.$payload.$signature");
    }

    /** @return array<string, array{string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'one part' => ['abc'],
            'four parts' => ['a.b.c.d'],
            'not base64url' => ['e30.e30.***'],
            'not json' => ['bm90IGpzb24.bm90IGpzb24.c2ln'],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_tokens_are_rejected(string $token): void
    {
        $this->expectException(InvalidToken::class);
        (new Jwt(self::SECRET))->decode($token);
    }

    public function test_a_short_secret_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Jwt('too-short');
    }
}
