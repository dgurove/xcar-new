<?php

namespace App\Http\Cabinet;

use App\Media\PhotoIngest;
use Illuminate\Http\Request;

/** Профиль — корень кабинета `/account`: карточка человека и список действий. */
class ProfileController
{
    public function profile(Request $request)
    {
        return view('cabinet.profile', [
            'user' => $request->user(),
            'manager' => $request->user()->isBuyer() ? $request->user()->manager : null,
            'passkeys' => $request->user()->webAuthnCredentials()->latest()->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email,'.$request->user()->id],
            'avatar' => ['nullable', 'image', 'max:8192'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        // Почту покупатель меняет, только если менеджер разрешил её в приглашении.
        $user->update(['name' => $data['name']] + ($user->mayHave('email') ? ['email' => $data['email'] ?: null] : []));
        if ($request->boolean('remove_avatar')) {
            $user->clearMediaCollection('avatar');
        }
        if ($request->hasFile('avatar')) {
            // Исходник с телефона до 8 МБ не нужен: аватар живёт в 128 px.
            app(PhotoIngest::class)->fromUpload($user, 'avatar', $request->file('avatar'), max: 512);
        }

        return back()->with('toast', 'Сохранено');
    }
}
