<?php

namespace Paymenter\Extensions\Others\Discord\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = $this->getPublicKey();

        if (!$publicKey) {
            abort(500, 'Discord public key not configured.');
        }

        $signature = $request->header('X-Signature-Ed25519');
        $timestamp  = $request->header('X-Signature-Timestamp');
        $body       = $request->getContent();

        if (!$signature || !$timestamp) {
            abort(401, 'Missing Discord signature headers.');
        }

        try {
            $isValid = sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp . $body,
                hex2bin($publicKey)
            );
        } catch (\Throwable) {
            abort(401, 'Invalid signature format.');
        }

        if (!$isValid) {
            abort(401, 'Invalid request signature.');
        }

        return $next($request);
    }

    private function getPublicKey(): ?string
    {
        $cached = \Illuminate\Support\Facades\Cache::get('discord_public_key');
        if ($cached) {
            return $cached;
        }

        $extension = \App\Models\Extension::where('extension', 'Discord')
            ->where('type', 'other')
            ->first();

        $key = $extension?->settings->where('key', 'public_key')->first()?->value;

        if ($key) {
            \Illuminate\Support\Facades\Cache::put('discord_public_key', $key, now()->addMinutes(10));
        }

        return $key;
    }
}
