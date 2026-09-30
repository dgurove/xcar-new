<?php

namespace App\Users\Actions;

use App\Users\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Выход из этого браузера: запись входа за человека закрывается, сессия гаснет только здесь. Без Auth::logout —
 * тот сменил бы remember_token, и человека выкинуло бы со всех его устройств.
 */
final class LeaveImpersonation
{
    public function __invoke(Request $request): void
    {
        Impersonation::current()?->update(['ended_at' => now()]);
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
