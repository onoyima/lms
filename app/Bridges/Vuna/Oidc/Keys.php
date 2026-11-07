<?php

namespace App\Bridges\Vuna\Oidc;

use RuntimeException;

/**
 * The bridge's signing key (RSA 2048): created once, kept outside the web root with owner-only
 * permissions, published as a JSON Web Key so the platform can check every ID token it receives.
 */
class Keys
{
    private ?\OpenSSLAsymmetricKey $private = null;

    private function path(): string
    {
        return (string) config('vuna_bridge.key_path');
    }

    private function load(): \OpenSSLAsymmetricKey
    {
        if ($this->private) {
            return $this->private;
        }
        $path = $this->path();
        if (! is_file($path)) {
            $dir = dirname($path);
            is_dir($dir) || mkdir($dir, 0700, true);
            $lock = fopen($path.'.lock', 'c');
            flock($lock, LOCK_EX);
            try {
                if (! is_file($path)) { // another request may have made it while we waited
                    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                    if (! $key || ! openssl_pkey_export($key, $pem)) {
                        throw new RuntimeException('Could not create the bridge signing key (is the PHP openssl extension configured?).');
                    }
                    $tmp = $path.'.'.bin2hex(random_bytes(4));
                    file_put_contents($tmp, $pem);
                    @chmod($tmp, 0600);
                    rename($tmp, $path);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $key = openssl_pkey_get_private((string) file_get_contents($path));
        if (! $key) {
            throw new RuntimeException('The bridge signing key cannot be read.');
        }

        return $this->private = $key;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function kid(): string
    {
        $d = openssl_pkey_get_details($this->load())['rsa'];

        return substr(self::b64(hash('sha256', $d['n'].$d['e'], true)), 0, 16);
    }

    /** @return array{keys: list<array>} */
    public function jwks(): array
    {
        $d = openssl_pkey_get_details($this->load())['rsa'];

        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid(), 'n' => self::b64($d['n']), 'e' => self::b64($d['e'])]]];
    }

    public function sign(array $claims): string
    {
        $head = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid()]));
        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (! openssl_sign("$head.$body", $signature, $this->load(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the token.');
        }

        return "$head.$body.".self::b64($signature);
    }
}
