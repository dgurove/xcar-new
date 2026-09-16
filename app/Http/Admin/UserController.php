<?php

namespace App\Http\Admin;

use App\Support\Phone;
use App\Users\Actions\DecideAccess;
use App\Users\Actions\IssuePasswordLink;
use App\Users\Actions\TransferBuyer;
use App\Users\Invite;
use App\Users\Role;
use App\Users\Section;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Пользователи: роль, доступ к стоянке, почта. Только для администратора. */
class UserController
{
    public const PRESETS = ['staff' => 'Сотрудники', 'managers' => 'Менеджеры', 'buyers' => 'Покупатели', 'invites' => 'Ссылки', 'waiting' => 'Ждут', 'visitors' => 'Посетители', 'rejected' => 'Отклонённые'];

    /** Роли, которые заводит админ: покупатели приходят только по ссылке менеджера, посетителей больше нет. */
    public const CREATABLE = [Role::Moderator, Role::Admin, Role::Manager];

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $preset = $request->query('preset', 'staff');
        $q = User::query()->with('manager')->orderBy('name');
        match ($preset) {
            'waiting' => $q->whereNull('approved_at')->whereNull('rejected_at')->where('role', Role::Visitor)->reorder('created_at', 'desc'),
            'rejected' => $q->whereNotNull('rejected_at')->whereNull('approved_at')->reorder('rejected_at', 'desc'),
            'managers' => $q->where('role', Role::Manager)->withCount('buyers'),
            'buyers' => $q->where('role', Role::Buyer)->reorder('created_at', 'desc'),
            'visitors' => $q->where('role', Role::Visitor)->whereNotNull('approved_at'),
            'invites' => $q->whereRaw('false'),
            default => $q->whereIn('role', [Role::Admin, Role::Moderator]),
        };
        if ($manager = (int) $request->query('manager')) {
            $q->where('manager_id', $manager);
        }
        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D+/', '', $term);
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('login', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
                ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")));
        }
        $counts = [
            'staff' => User::whereIn('role', [Role::Admin, Role::Moderator])->count(),
            'managers' => User::where('role', Role::Manager)->count(),
            'buyers' => User::where('role', Role::Buyer)->count(),
            'invites' => Invite::whereNull('disabled_at')->where(fn ($w) => $w->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'))->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'waiting' => User::whereNull('approved_at')->whereNull('rejected_at')->where('role', Role::Visitor)->count(),
            'visitors' => User::where('role', Role::Visitor)->whereNotNull('approved_at')->count(),
            'rejected' => User::whereNotNull('rejected_at')->whereNull('approved_at')->count(),
        ];
        // Прежний допуск по заявке остался в коде, но людей там больше не бывает — пустые пилюли не показываем.
        $pills = array_filter(self::PRESETS, fn ($_, $key) => ! in_array($key, ['waiting', 'visitors', 'rejected'], true) || $counts[$key] > 0 || $preset === $key, ARRAY_FILTER_USE_BOTH);

        return view('admin.users.index', [
            'users' => $q->paginate(50)->withQueryString(),
            'preset' => $preset,
            'pills' => $pills,
            'counts' => $counts,
            'managers' => User::where('role', Role::Manager)->orderBy('name')->get(),
            // Все ссылки — и админские, и менеджерские: админ видит, кто кого зовёт.
            'invites' => $preset === 'invites' ? \App\Http\Cabinet\InviteController::listFor($request->user()) : collect(),
            'fresh' => session('invite'),
        ]);
    }

    /** Ссылка на новый пароль — админ выдаёт кому угодно: сотруднику без почты, менеджеру, покупателю. */
    public function passwordLink(Request $request, User $user, IssuePasswordLink $issue)
    {
        abort_unless($request->user()->isAdmin(), 404);

        return back()->with('password_link', ['user' => $user->id, 'url' => $issue($user, $request->user())]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $data = $this->data($request);
        // Пароль случайный: человек входит по коду из письма или заводит ключ доступа.
        $data['password'] = Str::random(32);
        User::create($data + ['approved_at' => now(), 'approved_by' => $request->user()->id]);

        return redirect('/nastroyki/polzovateli?preset='.$this->presetOf($data['role']))->with('toast', 'Добавлен');
    }

    /** Решение по ждущему: открыть с выбранной ролью или отклонить. */
    public function decide(Request $request, User $user, DecideAccess $decide)
    {
        abort_unless($request->user()->isAdmin() && ! $user->is($request->user()), 404);
        if ($request->boolean('reject')) {
            $decide->reject($user, $request->user());

            return back()->with('toast', 'Отклонён');
        }
        $role = Role::from($request->validate(['role' => ['required', Rule::enum(Role::class)]])['role']);
        $decide->approve($user, $role, $request->user());

        return back()->with('toast', "Доступ открыт: {$role->label()}");
    }

    public function update(Request $request, User $user, TransferBuyer $transfer)
    {
        abort_unless($request->user()->isAdmin(), 404);
        if ($user->isBuyer()) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'manager_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Manager->value)],
            ]);
            $user->update(['name' => $data['name']]);
            $transfer($user, User::find($data['manager_id']));

            return back()->with('toast', 'Сохранено');
        }
        // Себя из администраторов не разжаловать: иначе в панель никто не зайдёт — data() оставляет Admin.
        $data = $this->data($request, $user);
        // Роль ждущему через шторку — это и есть допуск: без approved_at менеджер остался бы за стеной и пропал бы из «Ждут».
        if (! $user->isApproved() && $data['role'] !== Role::Visitor) {
            $data += ['approved_at' => now(), 'approved_by' => $request->user()->id, 'rejected_at' => null];
        }
        $user->update($data);

        return back()->with('toast', 'Сохранено');
    }

    private function data(Request $request, ?User $user = null): array
    {
        // Логин хранится и проверяется на уникальность строчными — приводим до валидации.
        $request->merge([
            'phone' => $request->filled('phone') ? Phone::normalize($request->input('phone')) : null,
            'login' => mb_strtolower(trim((string) $request->input('login'))) ?: null,
        ]);
        // У себя роль не меняется — селект отключён и в запрос не попадает.
        $self = $user?->is($request->user()) ?? false;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'digits:11', Rule::unique('users', 'phone')->ignore($user)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user)],
            'login' => ['nullable', 'string', \App\Http\Auth\InviteController::LOGIN_RULE, Rule::unique('users', 'login')->ignore($user)],
            'role' => [$self ? 'nullable' : 'required', Rule::enum(Role::class), Rule::notIn([Role::Buyer->value])],
        ], [
            'login.regex' => 'Логин — латиницей, от трёх знаков: буквы, цифры, точка',
        ]);
        if (! $data['phone'] && ! $data['email'] && ! ($data['login'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'Нужен телефон, почта или логин — чем-то человек должен входить']);
        }
        $data['email'] = $data['email'] ?: null;
        $data['phone'] = $data['phone'] ?: null;
        $data['login'] = $data['login'] ?? null;
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
