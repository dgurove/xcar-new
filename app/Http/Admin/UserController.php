<?php

namespace App\Http\Admin;

use App\Support\Phone;
use App\Users\Role;
use App\Users\Section;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Пользователи: роль, доступ к стоянке, почта. Только для администратора. */
class UserController
{
    public const PRESETS = ['staff' => 'Сотрудники', 'managers' => 'Менеджеры', 'visitors' => 'Посетители'];

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $preset = $request->query('preset', 'staff');
        $q = User::query()->orderBy('name');
        match ($preset) {
            'managers' => $q->where('role', Role::Manager),
            'visitors' => $q->where('role', Role::Visitor),
            default => $q->whereIn('role', [Role::Admin, Role::Moderator]),
        };
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $term).'%')->orWhere('email', 'ilike', "%{$term}%"));
        }

        return view('admin.users.index', [
            'users' => $q->paginate(50)->withQueryString(),
            'preset' => $preset,
            'counts' => [
                'staff' => User::whereIn('role', [Role::Admin, Role::Moderator])->count(),
                'managers' => User::where('role', Role::Manager)->count(),
                'visitors' => User::where('role', Role::Visitor)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $data = $this->data($request);
        // Пароль случайный: человек входит по коду из письма или заводит ключ доступа.
        $data['password'] = Str::random(32);
        User::create($data);

        return redirect('/nastroyki/polzovateli?preset='.$this->presetOf($data['role']))->with('toast', 'Добавлен');
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 404);
        // Себя из администраторов не разжаловать: иначе в панель никто не зайдёт — data() оставляет Admin.
        $user->update($this->data($request, $user));

        return back()->with('toast', 'Сохранено');
    }

    private function data(Request $request, ?User $user = null): array
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);
        // У себя роль не меняется — селект отключён и в запрос не попадает.
        $self = $user?->is($request->user()) ?? false;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'digits:11', Rule::unique('users', 'phone')->ignore($user)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user)],
            'role' => [$self ? 'nullable' : 'required', Rule::enum(Role::class)],
        ]);
        $data['email'] = $data['email'] ?: null;
        $data['role'] = $self ? Role::Admin : Role::from($data['role']);
        $data['access'] = $request->boolean('park') ? [Section::Park->value] : [];
        $data['notification_settings'] = array_merge($user?->notification_settings ?? [], ['mail' => $request->boolean('mail')]);

        return $data;
    }

    private function presetOf(Role $role): string
    {
        return match ($role) {
            Role::Manager => 'managers', Role::Visitor => 'visitors', default => 'staff'
        };
    }
}
