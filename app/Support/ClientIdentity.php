<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/** The platform's Ed25519 signing key (libsodium, built into PHP). */
class ClientIdentity
{
    private string $keypair;

    public function __construct()
    {
        $path = config('platform.signing_key_path');
        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, base64_encode(sodium_crypto_sign_keypair()));
            @chmod($path, 0600);
        }
        $this->keypair = base64_decode(trim(File::get($path)));
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($this->keypair)));
    }

    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->keypair));
    }

    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        return sodium_crypto_sign_verify_detached(base64_decode($signature), $message, base64_decode($publicKey));
    }
}
