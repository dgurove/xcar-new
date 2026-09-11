<?php

namespace App\Http\Cabinet;

use Illuminate\Http\Request;

class ProfileController
{
    public function show(Request $request)
    {
        return view('cabinet.profile', [
            'user' => $request->user(),
            'passkeys' => $request->user()->webAuthnCredentials()->latest()->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email,'.$request->user()->id],
        ]);

        $request->user()->update(['name' => $data['name'], 'email' => $data['email'] ?: null]);

        return back()->with('toast', 'Сохранено');
    }
}
