<?php

namespace Tests\Unit;

use App\Services\Sso\Jwt;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JwtTest extends TestCase
{
    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** DER ECDSA signature to the raw r||s form JWTs use. */
    private function raw(string $der): string
    {
        $o = 3 + (ord($der[1]) > 0x80 ? 1 : 0);
        $rLen = ord($der[$o]);
        $r = substr($der, $o + 1, $rLen);
        $sLen = ord($der[$o + 2 + $rLen]);
        $s = substr($der, $o + 3 + $rLen, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    public function test_es256_tokens_verify_and_tampering_fails(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key)['ec'];
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => $this->b64($d['x']), 'y' => $this->b64($d['y'])];
        $input = $this->b64('{"alg":"ES256","kid":"e"}').'.'.$this->b64('{"sub":"1"}');
        openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
        $token = $input.'.'.$this->b64($this->raw($der));

        [, $claims] = Jwt::verify($token, fn () => $jwk);
        $this->assertSame('1', $claims['sub']);

        $this->expectException(RuntimeException::class);
        Jwt::verify($this->b64('{"alg":"ES256","kid":"e"}').'.'.$this->b64('{"sub":"2"}').'.'.explode('.', $token)[2], fn () => $jwk);
    }

    public function test_symmetric_and_none_algorithms_are_refused(): void
    {
        foreach (['HS256', 'none', 'PS256'] as $alg) {
            try {
                Jwt::verify($this->b64(json_encode(['alg' => $alg])).'.'.$this->b64('{}').'.', fn () => ['kty' => 'RSA']);
                $this->fail("$alg accepted");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
    }
}
