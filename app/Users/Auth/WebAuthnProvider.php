<?php

namespace App\Users\Auth;

use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Auth\WebAuthnUserProvider;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Exceptions\AssertionException;
use Laragear\WebAuthn\JsonTransport;

/**
 * Провайдер пользователей с входом по ключу. Отличие от пакетного одно:
 * причина, по которой ключ не подошёл, уходит в лог warning — на бою
 * без APP_DEBUG иначе не видно ничего.
 */
class WebAuthnProvider extends WebAuthnUserProvider
{
    protected function validateWebAuthn(WebAuthnAuthenticatable $user, array $credentials): bool
    {
        try {
            $this->validator
                ->send(new AssertionValidation(new JsonTransport($credentials), $user))
                ->thenReturn();
        } catch (AssertionException $e) {
            Log::warning('passkey: '.$e->getMessage(), [
                'user' => $user->getAuthIdentifier(),
                'credential' => substr((string) ($credentials['id'] ?? ''), 0, 8),
                'host' => request()->getHost(),
                'ua' => request()->userAgent(),
            ]);

            return false;
        }

        return true;
    }
}
