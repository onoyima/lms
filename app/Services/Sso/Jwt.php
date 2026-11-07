<?php

namespace App\Services\Sso;

use RuntimeException;

/**
 * Minimal, strict JWT signature check for OpenID Connect ID tokens: RS256/RS384/RS512 and
 * ES256 only (never "none" or shared-secret algorithms), keys taken from the provider's JWKS.
 */
class Jwt
{
    private const ALGS = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512, 'ES256' => OPENSSL_ALGO_SHA256];

    /** @return array{0: array, 1: array} [header, claims] — signature verified */
    public static function verify(string $jwt, callable $keyFor): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$h, $p, $sig] = $parts;
        $header = json_decode(self::b64($h), true);
        $claims = json_decode(self::b64($p), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new RuntimeException('Malformed token.');
        }
        $alg = $header['alg'] ?? '';
        if (! isset(self::ALGS[$alg])) {
            throw new RuntimeException("Token algorithm $alg is not allowed.");
        }
        $jwk = $keyFor($header['kid'] ?? null, $alg);
        if (! is_array($jwk)) {
            throw new RuntimeException('No matching signing key.');
        }
        $pem = self::pem($jwk);
        $signature = self::b64($sig);
        if ($alg === 'ES256') {
            $signature = self::ecdsaToDer($signature);
        }
        if (openssl_verify("$h.$p", $signature, $pem, self::ALGS[$alg]) !== 1) {
            throw new RuntimeException('Token signature is not valid.');
        }

        return [$header, $claims];
    }

    public static function b64(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($d === false) {
            throw new RuntimeException('Bad encoding.');
        }

        return $d;
    }

    /** JWK (RSA or EC P-256) to a PEM public key. */
    public static function pem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') === 'RSA') {
            $n = self::b64($jwk['n'] ?? '');
            $e = self::b64($jwk['e'] ?? '');
            $rsa = self::seq(self::int($n).self::int($e));
            $spki = self::seq(self::seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00").self::bitString($rsa));
        } elseif (($jwk['kty'] ?? '') === 'EC' && ($jwk['crv'] ?? '') === 'P-256') {
            $x = str_pad(self::b64($jwk['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
            $y = str_pad(self::b64($jwk['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
            $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;
        } else {
            throw new RuntimeException('Unsupported key type.');
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function len(int $l): string
    {
        if ($l < 128) {
            return chr($l);
        }
        $b = ltrim(pack('N', $l), "\0");

        return chr(0x80 | strlen($b)).$b;
    }

    private static function seq(string $s): string
    {
        return "\x30".self::len(strlen($s)).$s;
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\0".$bytes;
        }

        return "\x02".self::len(strlen($bytes)).$bytes;
    }

    private static function bitString(string $s): string
    {
        return "\x03".self::len(strlen($s) + 1)."\0".$s;
    }

    private static function ecdsaToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new RuntimeException('Bad signature length.');
        }

        return self::seq(self::int(substr($raw, 0, 32)).self::int(substr($raw, 32)));
    }
}
