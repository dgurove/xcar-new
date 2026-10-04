<?php

namespace App\Http\Admin;

use App\Billing\Documents\StatementPdf;
use App\Billing\Export\ManagerStatement;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\PartyRules;
use App\Chats\Chat;
use App\Http\Cabinet\InviteController;
use App\Offers\Actions\SyncViewers;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Area;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\OfficePreview;
use App\Support\Phone;
use App\Support\Surface;
use App\Users\Actions\DecideAccess;
use App\Users\Actions\IssueImpersonation;
use App\Users\Actions\IssuePasswordLink;
use App\Users\Actions\TransferBuyer;
use App\Users\BuyerGroup;
use App\Users\CrmArea;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Пользователи: список и правки — администратору, карточка человека — любому сотруднику. */
class UserController
{
    public const PRESETS = ['staff' => 'Сотрудники', 'managers' => 'Менеджеры', 'park' => 'Парковка', 'buyers' => 'Покупатели', 'invites' => 'Ссылки', 'waiting' => 'Ждут', 'visitors' => 'Посетители', 'rejected' => 'Отклонённые'];

    /** Роли, которые админ отмечает галками (ролей у человека может быть несколько); заводят людей только ссылкой. */
    public const ROLES = [Role::Admin, Role::Moderator, Role::Manager, Role::Parking, Role::Reviewer];

    /** Один экран на двух хостах: в CRM /settings/users, в кабинете сайта /account/users. */
    public static function base(): string
    {
        return Surface::current() === Surface::Crm ? '/settings/users' : '/account/users';
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        // Чипы — своего вида: менеджер есть только у покупателей, группы у каждого вида свои (ключ в адресе — свой).
        $kindOf = (string) $request->query('preset', 'staff');
        $facets = Facets::for($request, 'crm-users', ...array_filter([
            $kindOf === 'buyers' ? Common::manager('users.manager_id') : null,
            self::groupFacet($kindOf),
        ]));
        ListPrefs::sync($request, 'crm-users', keep: $facets->keys());
        $preset = $request->query('preset', 'staff');
        $searching = $facets->searching();
        // На сайте ссылки — своя пилюля кабинета, а не пресет.
        if ($preset === 'invites' && Surface::current() !== Surface::Crm) {
            return redirect('/account/invites');
        }
        $q = User::query()->with(['manager', 'userGroups'])->orderBy('name');
        // Лупа — по всем людям, мимо пилюли и чипов.
        if (! $searching) {
            match ($preset) {
                'waiting' => $q->whereNull('approved_at')->whereNull('rejected_at')->withRole(Role::Visitor)->reorder('created_at', 'desc'),
                'rejected' => $q->whereNotNull('rejected_at')->whereNull('approved_at')->reorder('rejected_at', 'desc'),
                'managers' => $q->withRole(Role::Manager)->withCount('buyers'),
                'park' => $q->withRole(Role::Parking),
                'buyers' => $q->withRole(Role::Buyer)->reorder('created_at', 'desc'),
                'visitors' => $q->withRole(Role::Visitor)->whereNotNull('approved_at'),
                'invites' => $q->whereRaw('false'),
                default => $q->withRole(Role::Admin, Role::Moderator),
            };
        }
        // Группы — над списком своего вида: менеджеров в «Менеджерах», модераторов в «Сотрудниках» (только в CRM).
        $kind = Surface::current() === Surface::Crm ? match ($preset) {
            'managers' => UserGroup::MANAGERS, 'staff' => UserGroup::MODERATORS, default => null
        } : null;
        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D+/', '', $term);
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('login', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
                ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")));
        }
        $facets->apply($q);
        $counts = [
            'staff' => User::withRole(Role::Admin, Role::Moderator)->count(),
            'managers' => User::withRole(Role::Manager)->count(),
            'park' => User::withRole(Role::Parking)->count(),
            'buyers' => User::withRole(Role::Buyer)->count(),
            'invites' => Invite::whereNull('disabled_at')->where(fn ($w) => $w->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'))->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'waiting' => User::whereNull('approved_at')->whereNull('rejected_at')->withRole(Role::Visitor)->count(),
            'visitors' => User::withRole(Role::Visitor)->whereNotNull('approved_at')->count(),
            'rejected' => User::whereNotNull('rejected_at')->whereNull('approved_at')->count(),
        ];
        // Прежний допуск по заявке остался в коде, но людей там больше не бывает — пустые пилюли не показываем.
        $pills = array_filter(self::PRESETS, fn ($_, $key) => ! in_array($key, ['waiting', 'visitors', 'rejected'], true) || $counts[$key] > 0 || $preset === $key, ARRAY_FILTER_USE_BOTH);
        if (Surface::current() !== Surface::Crm) {
            unset($pills['invites']);
        }

        return view('admin.users.index', [
            'users' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset,
            'pills' => $pills,
            'counts' => $counts,
            'managers' => User::withRole(Role::Manager)->orderBy('name')->get(),
            // Все ссылки — и админские, и менеджерские: админ видит, кто кого зовёт.
            'invites' => $preset === 'invites' ? InviteController::listFor($request->user()) : collect(),
            'groupKind' => $kind,
            'groups' => $kind ? UserGroup::ofKind($kind)->with('members')->get() : collect(),
            'fresh' => session('invite'),
            'facets' => $facets,
        ]);
    }

    /**
     * Чип «Группа»: у менеджеров и сотрудников — группы CRM (волны показа, модераторы), у покупателей — группы их
     * менеджеров; одноимённые группы разных менеджеров различает имя менеджера подсказкой.
     */
    private static function groupFacet(string $preset): ?Facet
    {
        $key = match ($preset) {
            'buyers' => 'bgroup', 'managers' => 'group', 'staff' => 'team', default => null
        };
        if (! $key) {
            return null;
        }
        $pivot = $preset === 'buyers' ? 'buyer_group_user' : 'user_group_user';

        return Facet::custom($key, 'Группа', ['группа', 'группы', 'групп'],
            fn ($q, array $ids) => $q->whereExists(fn ($e) => $e->from($pivot)->whereColumn("{$pivot}.user_id", 'users.id')->whereIn("{$pivot}.group_id", Common::ints($ids))),
            fn ($q) => DB::table($pivot)->whereIn('user_id', Facet::bare($q->toBase())->select('users.id'))
                ->groupBy('group_id')->selectRaw('group_id::text as v, count(*) as n')->pluck('n', 'v')->map(fn ($n) => (int) $n)->all(),
        )->labels(fn (array $ids) => $preset === 'buyers'
            ? BuyerGroup::whereIn('id', Common::ints($ids))->with('manager')->get()->mapWithKeys(fn (BuyerGroup $g) => [(string) $g->id => new Option((string) $g->id, $g->name, hint: $g->manager?->shortName())])->all()
            : UserGroup::whereIn('id', Common::ints($ids))->get()->mapWithKeys(fn (UserGroup $g) => [(string) $g->id => new Option((string) $g->id, $g->name)])->all());
    }

    /**
     * Карточка человека — админу, из шапки чата и из списка: контакт, его чаты (у менеджера —
     * и чаты его покупателей), менеджеру сделки, покупателю интерес. Правки — админу тем же шитом, что в списке.
     */
    public function show(Request $request, User $user)
    {
        $me = $request->user();
        abort_unless($me->isAdmin(), 404);
        $user->load('manager');
        $chats = Chat::where('user_id', $user->id)->when($user->isManager(), fn ($q) => $q->orWhere('manager_id', $user->id))
            ->withLast()->with(['offer.brand', 'offer.model', 'offer.media', 'user', 'manager'])->orderByDesc('last_message_at')->limit(50)->get();
        // Пришли из шапки чата — «‹ Чат» вместо «‹ Пользователи».
        $chat = $request->integer('chat') ? Chat::find($request->integer('chat')) : null;

        $pill = $user->isManager() && $request->query('pill') === 'money' ? 'money' : 'overview';
        $ledger = $pill === 'money' ? new ManagerLedger($user) : null;

        return view('admin.users.show', [
            'user' => $user,
            'pill' => $pill,
            'position' => $ledger?->position(),
            'party' => $pill === 'money' ? Party::forUser($user, false) : null,
            'moneyDeals' => $ledger?->deals() ?? collect(),
            'back' => $chat ? ['Чат', (Surface::current() === Surface::Crm ? '/work/chats/' : '/account/chats/').$chat->id] : null,
            'chats' => $chats,
            'deals' => $user->isManager() ? Deal::where('buyer_id', $user->id)->with(['offer.brand', 'offer.model', 'offer.media', 'buyer'])->latest()->get() : collect(),
            'buyersCount' => $user->isManager() ? $user->buyers()->count() : 0,
            'interests' => $user->isBuyer() ? $user->interests()->with(['offer.brand', 'offer.model', 'offer.media'])->latest()->get() : collect(),
            'seen' => $user->isBuyer() ? Offer::where('state', OfferState::Open)->visibleTo($user)->count() : null,
            'managers' => $me->isAdmin() ? User::withRole(Role::Manager)->orderBy('name')->get() : collect(),
            'link' => session('password_link'),
        ]);
    }

    /** Реквизиты менеджера — сотрудник правит те же, что менеджер в кабинете. */
    public function party(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin() && $user->isManager(), 404);
        $data = $request->validate(PartyRules::rules());
        // НДС ПРАЙМ в его счетах — в сумме (разница цен), «НДС сверху» — если так договорились.
        Party::forUser($user)->update($data + ['card' => isset($data['card']) ? preg_replace('/\D/', '', $data['card']) : null, 'vat_on_top' => $request->boolean('vat_on_top')]);

        return back()->with('toast', 'Реквизиты сохранены');
    }

    public function statement(Request $request, User $user, StatementPdf $pdf)
    {
        abort_unless($request->user()->isAdmin() && $user->isManager(), 404);
        [$from, $to] = $this->period($request);
        $name = 'akt-sverki-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.pdf';

        return response($pdf->render($user, $from, $to), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$name.'"']);
    }

    public function export(Request $request, User $user, ManagerStatement $xlsx)
    {
        abort_unless($request->user()->isAdmin() && $user->isManager(), 404);
        [$from, $to] = $this->period($request);
        $path = $xlsx->write($user, $from, $to, tempnam(sys_get_temp_dir(), 'sdelki-').'.xlsx');
        $name = 'sdelki-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.xlsx';
        // Шторка документов просит Excel HTML-фрагментом.
        if ($request->boolean('preview')) {
            try {
                return OfficePreview::response($path, $name);
            } finally {
                @unlink($path);
            }
        }

        return response()->download($path, $name, [], $request->boolean('inline') ? 'inline' : 'attachment')->deleteFileAfterSend();
    }

    /** @return array{Carbon, Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfYear();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    /** Ссылка на новый пароль — админ выдаёт кому угодно: сотруднику без почты, менеджеру, покупателю. */
    public function passwordLink(Request $request, User $user, IssuePasswordLink $issue)
    {
        abort_unless($request->user()->isAdmin(), 404);

        return back()->with('password_link', ['user' => $user->id, 'url' => $issue($user, $request->user())]);
    }

    /** «Войти как»: одноразовая ссылка на вход за человека — админу, за не сотрудника. Открывают её в окне инкогнито. */
    public function impersonate(Request $request, User $user, IssueImpersonation $issue)
    {
        return back()->with('impersonation_link', ['user' => $user->id, 'url' => $issue($user, $request->user())]);
    }

    /**
     * Удалить насовсем — только того, за кем ничего нет: следы (подтверждения, сделки,
     * предложения, покупатели, чаты) остаются в истории, такому можно лишь закрыть доступ.
     */
    public function destroy(Request $request, User $user, DecideAccess $decide)
    {
        abort_unless($request->user()->isAdmin() && ! $user->is($request->user()), 404);
        if (self::traces($user)) {
            return back()->with('toast', 'За '.$user->shortName().' есть история — только закрыть доступ');
        }
        $preset = $user->isRejected() ? 'rejected' : $this->presetOf($user);
        $decide->reject($user, $request->user());
        $user->delete();

        return redirect(self::base().'?preset='.$preset)->with('toast', 'Удалён');
    }

    /** Есть ли за человеком то, что должно остаться в истории. */
    public static function traces(User $user): bool
    {
        return Bid::where('user_id', $user->id)->exists()
            || Deal::where('buyer_id', $user->id)->exists()
            || DB::table('offer_managers')->where('user_id', $user->id)->exists()
            || $user->buyers()->exists()
            || Chat::where('user_id', $user->id)->where('messages_count', '>', 0)->exists();
    }

    /** Решение по ждущему или уже допущенному: открыть с выбранной ролью или закрыть доступ. */
    public function decide(Request $request, User $user, DecideAccess $decide)
    {
        abort_unless($request->user()->isAdmin() && ! $user->is($request->user()), 404);
        if ($request->boolean('reject')) {
            $decide->reject($user, $request->user());

            return back()->with('toast', $user->wasChanged('approved_at') ? 'Доступ закрыт' : 'Отклонён');
        }
        $role = Role::from($request->validate(['role' => ['required', Rule::enum(Role::class)]])['role']);
        $decide->approve($user, $role, $request->user());

        return back()->with('toast', "Доступ открыт: {$role->label()}");
    }

    public function update(Request $request, User $user, TransferBuyer $transfer, SyncViewers $sync)
    {
        abort_unless($request->user()->isAdmin(), 404);
        if ($user->isBuyer()) {
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:60'],
                'last_name' => ['required', 'string', 'max:60'],
                'manager_id' => ['required', Rule::exists('users', 'id')->where(fn ($q) => $q->whereJsonContains('roles', Role::Manager->value))],
            ]);
            $user->update(['first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name'])]);
            $transfer($user, User::find($data['manager_id']));

            return back()->with('toast', 'Сохранено');
        }
        // Себя из администраторов не разжаловать: иначе в панель никто не зайдёт — data() оставляет Admin.
        $data = $this->data($request, $user);
        // Сняли «Менеджер» у того, у кого есть покупатели, — они переходят к выбранному менеджеру (без него не сохраняем).
        $leaving = $user->isManager() && ! in_array(Role::Manager, $data['roles'], true) && $user->buyers()->exists();
        if ($leaving) {
            $to = $request->validate(['transfer_to' => ['required', Rule::exists('users', 'id')->whereNot('id', $user->id)->where(fn ($q) => $q->whereJsonContains('roles', Role::Manager->value))]],
                ['transfer_to.required' => 'Выберите менеджера, к которому перейдут покупатели'])['transfer_to'];
        }
        // Роль ждущему через шторку — это и есть допуск: без approved_at менеджер остался бы за стеной и пропал бы из «Ждут».
        if (! $user->isApproved() && $data['roles'] !== [Role::Visitor]) {
            $data += ['approved_at' => now(), 'approved_by' => $request->user()->id, 'rejected_at' => null];
        }
        DB::transaction(function () use ($user, $data, $leaving, $transfer) {
            if ($leaving) {
                $target = User::find(request()->input('transfer_to'));
                $user->buyers()->get()->each(fn (User $buyer) => $transfer($buyer, $target));
            }
            $user->update($data);
        });
        // Группы — видов его ролей (UserGroup): менеджеру — для волн показа, модератору — общие предложения.
        $before = $user->userGroups()->pluck('user_groups.id')->all();
        $user->syncUserGroups([...(array) $request->input('moderator_groups', []), ...(array) $request->input('manager_groups', [])]);
        if ($user->isManager() && $before !== $user->userGroups()->pluck('user_groups.id')->all()) {
            $sync->all();
        }

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
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'digits:11', Rule::unique('users', 'phone')->ignore($user)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user)],
            'login' => ['nullable', 'string', \App\Http\Auth\InviteController::LOGIN_RULE, Rule::unique('users', 'login')->ignore($user)],
            'roles' => [$self ? 'nullable' : 'required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_map(fn (Role $r) => $r->value, self::ROLES))],
        ], [
            'login.regex' => 'Логин — латиницей, от трёх знаков: буквы, цифры, точка',
            'roles.required' => 'Отметьте хотя бы одну роль',
            'roles.min' => 'Отметьте хотя бы одну роль',
        ]);
        if (! $data['phone'] && ! $data['email'] && ! ($data['login'] ?? null)) {
            throw ValidationException::withMessages(['phone' => 'Нужен телефон, почта или логин — чем-то человек должен входить']);
        }
        $data['email'] = $data['email'] ?: null;
        $data['phone'] = $data['phone'] ?: null;
        $data['login'] = $data['login'] ?? null;
        // Ролей бывает несколько; себе админ «Админ» не снимет — остаётся при любых галках.
        $roles = array_map(fn ($r) => Role::from($r), (array) ($data['roles'] ?? []));
        if ($self && ! in_array(Role::Admin, $roles, true)) {
            $roles[] = Role::Admin;
        }
        $data['roles'] = array_values(array_unique($roles, SORT_REGULAR));
        // Доступ к парковке — только у роли «Парковка»: что открыто сверх основы, своя парковка, только приёмка.
        $park = in_array(Role::Parking, $data['roles'], true);
        $request->validate(['areas' => ['nullable', 'array'], 'areas.*' => [Rule::in(Area::values())], 'park_yard_id' => ['nullable', Rule::exists('park_yards', 'id')]]);
        $request->validate(['crm_areas' => ['nullable', 'array'], 'crm_areas.*' => [Rule::in(CrmArea::values())]]);
        // Галки областей — у каждой роли свои, в одном списке: парковке — разделы парковки, модератору — сверх черновиков (почта CRM).
        $data['access'] = [
            ...($park ? array_values(array_intersect(Area::values(), (array) $request->input('areas', []))) : []),
            ...(in_array(Role::Moderator, $data['roles'], true) ? array_values(array_intersect(CrmArea::values(), (array) $request->input('crm_areas', []))) : []),
        ];
        $data['park_yard_id'] = $park && $request->filled('park_yard_id') ? (int) $request->input('park_yard_id') : null;
        $data['park_readonly'] = $park && $request->boolean('park_readonly');
        $data['notification_settings'] = array_merge($user?->notification_settings ?? [], ['mail' => $request->boolean('mail')]);

        return $data;
    }

    private function presetOf(User $user): string
    {
        return match (true) {
            $user->isStaff() => 'staff', $user->isManager() => 'managers', $user->isParking() => 'park', $user->hasRole(Role::Visitor) => 'visitors', default => 'staff'
        };
    }
}
