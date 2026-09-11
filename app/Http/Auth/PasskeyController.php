<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/** Ключи доступа: Face ID и Touch ID вместо пароля. */
class PasskeyController
{
    public function registerOptions(AttestationRequest $request)
    {
        // Дискавери-ключ: при входе телефон сам предложит нужный, ничего вводить не надо.
        return $request->fastRegistration()->userless()->toCreate();
    }

    public function register(AttestedRequest $request)
    {
        $request->save(['alias' => $request->input('alias')]);

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
}
