<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

/** Ключи доступа: Face ID и Touch ID вместо пароля. */
class PasskeyController
{
    private const MAX_KEYS = 10;

    public function registerOptions(AttestationRequest $request)
    {
        if ($request->user()->webAuthnCredentials()->count() >= self::MAX_KEYS) {
            return response()->json(['message' => 'Слишком много ключей — удалите лишние'], 422);
        }

        // Дискавери-ключ: при входе телефон сам предложит нужный, ничего вводить не надо.
        return $request->fastRegistration()->userless()->toCreate();
    }

    public function register(AttestedRequest $request)
    {
        $request->save(['alias' => mb_substr((string) $request->input('alias'), 0, 40) ?: null]);

        return response()->noContent();
    }

    public function loginOptions(AssertionRequest $request)
    {
        return $request->toVerify();
    }

    public function login(AssertedRequest $request)
    {
        if (! $request->login(remember: true)) {
            return response()->json(['message' => 'Ключ не подошёл'], 422);
        }

        return response()->json(['redirect' => LoginController::home()]);
    }

    public function destroy(Request $request, string $id)
    {
        $request->user()->webAuthnCredentials()->whereKey($id)->delete();

        return back()->with('toast', 'Ключ удалён');
    }

    /** Ошибка церемонии в браузере — единственный след с чужого телефона. */
    public function report(Request $request)
    {
        $data = $request->validate([
            'stage' => 'required|string|max:20',
            'name' => 'nullable|string|max:60',
            'code' => 'nullable|string|max:60',
            'message' => 'nullable|string|max:300',
            'standalone' => 'nullable|boolean',
        ]);

        Log::warning('passkey: '.$data['stage'].' — '.($data['name'] ?? '?').' '.($data['code'] ?? ''), [
            ...$data,
            'user' => $request->user()?->id,
            'host' => $request->getHost(),
            'ua' => $request->userAgent(),
        ]);

        return response()->noContent();
    }
}
