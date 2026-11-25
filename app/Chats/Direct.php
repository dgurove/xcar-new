<?php

namespace App\Chats;

use App\Search\Term;
use App\Support\People;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Личка (22.11.2026, менеджеры: «написать менеджеру не по тачке, свободная переписка», владелец: «полноценный мессенджер»):
 * чат без машины с двумя людьми — `offer_id` пуст, `manager_id` задан; та же форма, что у переписки покупателя со своим
 * менеджером, и это одна строка. У пары личка одна, в одну и ту же сторону: младший по рангу — `user_id` (покупатель против
 * своего менеджера, менеджер против сотрудника), при равном — меньший номер; поэтому `AuthorKind::Staff` и `unread_for_staff`
 * по-прежнему значат «отвечающая сторона». Кто кому может написать первым — только здесь (владелец): сотрудник
 * (`canEditDeals`) — сотрудникам, менеджерам, супер-покупателям и покупателям с доступом, менеджер — своим покупателям,
 * покупатель — только своему менеджеру. Админ читает чужие лички (`Chat::allows`), но не пишет в них.
 *
 * **От чьего имени** (25.11.2026, владелец: «админы и модераторы от лица администрации XCAR пишут, а между собой от своего
 * лица»; Ананьеву нужно написать): сотрудник не сотруднику пишет в его чат площадки «Администрация XCAR» (`viaPlatform`,
 * `chatFor`: `offer_id` и `manager_id` пусты, имя сотрудника скрыто — `Message::hidesAuthorFrom`), личка — только между
 * сотрудниками и у менеджера с покупателем. Менеджеру сотрудников поимённо в «Написать» нет — только «Администрация XCAR»;
 * лички, начатые до 25.11, открыты обоим как были.
 */
final class Direct
{
    /** Сотрудник 2, менеджер или супер-покупатель 1, остальные 0. */
    public static function rank(User $u): int
    {
        return match (true) {
            $u->canEditDeals() => 2,
            $u->isManager() || $u->isSuperBuyer() => 1,
            default => 0,
        };
    }

    /** @return array{0: User, 1: User} [user_id, manager_id] пары */
    public static function orient(User $a, User $b): array
    {
        [$ra, $rb] = [self::rank($a), self::rank($b)];

        return $ra < $rb || ($ra === $rb && $a->id < $b->id) ? [$a, $b] : [$b, $a];
    }

    /** Личка пары, в какую бы сторону она ни была записана. */
    public static function between(User|int $a, User|int $b): ?Chat
    {
        [$a, $b] = [$a instanceof User ? $a->id : $a, $b instanceof User ? $b->id : $b];

        return Chat::whereNull('offer_id')->whereNotNull('manager_id')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('user_id', $a)->where('manager_id', $b))
                ->orWhere(fn ($q) => $q->where('user_id', $b)->where('manager_id', $a)))
            ->first();
    }

    /** Может ли `from` начать личку с `to` (уже начатая открыта обоим без этой проверки). */
    public static function canStart(User $from, User $to): bool
    {
        if ($from->id === $to->id || $from->is_demo || $to->is_demo || $to->rejected_at !== null) {
            return false;
        }
        $staff = fn (User $u) => $u->canEditDeals();
        $dealer = fn (User $u) => $u->isManager() || $u->isSuperBuyer();

        return match (true) {
            $staff($from) => $staff($to) || $dealer($to) || ($to->isBuyer() && $to->hasBuyerAccess()),
            $dealer($from) => $from->isManager() && $to->isBuyer() && $to->manager_id === $from->id && $to->hasBuyerAccess(),
            $from->isBuyer() => $to->id === $from->manager_id,
            default => false,
        };
    }

    /** Сотрудник пишет не сотруднику — от «Администрации XCAR», в чат площадки человека, а не личкой. */
    public static function viaPlatform(User $from, User $to): bool
    {
        return $from->canEditDeals() && ! $to->canEditDeals();
    }

    /** Чат, куда `me` пишет `to`: чат площадки человека (`viaPlatform`) или личка пары. */
    public static function chatFor(User $me, User $to): ?Chat
    {
        return self::viaPlatform($me, $to) ? self::platformOf($to)->first() : self::between($me, $to);
    }

    /** Чат площадки человека без машины — его «Администрация XCAR». */
    public static function platformOf(User|int $user): Builder
    {
        return Chat::whereNull('offer_id')->whereNull('manager_id')->where('user_id', $user instanceof User ? $user->id : $user)
            ->orderByDesc('last_message_at')->orderBy('id');
    }

    /**
     * Кому можно написать — строки `x-people.pick` группами: сотруднику «Сотрудники», «Менеджеры» и «Покупатели» (с доступом),
     * менеджеру — первой строкой «Администрация XCAR» (обращение к площадке, `id` = `support`) и его «Покупатели».
     *
     * @return Collection<int, array{id: int|string, name: string, sub: ?string, user: ?User, group: string}>
     */
    public static function partners(User $me): Collection
    {
        return once(function () use ($me) {
            $rows = collect();
            $isStaff = $me->canEditDeals();
            if (! $isStaff && ! $me->isManager() && ! $me->isSuperBuyer()) {
                return $rows;
            }
            if (! $isStaff) {
                $rows->push(['id' => 'support', 'name' => Chat::PLATFORM, 'sub' => null, 'user' => null, 'logo' => true, 'group' => '']);
            }
            if ($isStaff) {
                foreach (self::staff($me) as $u) {
                    $rows->push(People::row($u, self::roleWord($u)) + ['group' => 'Сотрудники']);
                }
                foreach (self::dealers($me) as $u) {
                    $rows->push(People::row($u, $u->isSuperBuyer() && ! $u->isManager() ? 'Супер-покупатель' : null) + ['group' => 'Менеджеры']);
                }
                foreach (self::buyers() as $u) {
                    $rows->push(People::row($u) + ['group' => 'Покупатели']);
                }
            } elseif ($me->isManager()) {
                foreach (People::buyersOf($me)->filter(fn (User $b) => $b->hasBuyerAccess()) as $u) {
                    $rows->push(['name' => $u->buyerLabel()] + People::row($u) + ['group' => 'Покупатели']);
                }
            }

            return $rows->values();
        });
    }

    /**
     * Люди для поиска в списке чатов (владелец: «начинаешь вбивать — этот вариант должен быть самым первым»): кому можно
     * написать и чьё имя подходит под запрос, с их личкой, если она есть.
     *
     * @return Collection<int, array{user: User, chat: ?Chat}>
     */
    public static function people(User $me, string $q, int $limit = 6): Collection
    {
        if (trim($q) === '') {
            return collect();
        }
        $partners = self::partners($me)->filter(fn ($p) => $p['user']);
        if ($partners->isEmpty()) {
            return collect();
        }
        // Сотрудника менеджер находит только по имени: телефон и почта сотрудника ему не видны, и угадывать их поиском незачем.
        [$staff, $rest] = $partners->partition(fn ($p) => ! $me->canEditDeals() && $p['user']->canEditDeals());
        $users = User::where(fn ($w) => $w
            ->when($rest->isNotEmpty(), fn ($w) => $w->orWhere(fn ($w) => $w->whereIn('users.id', $rest->pluck('user.id'))->search($q)))
            ->when($staff->isNotEmpty(), fn ($w) => $w->orWhere(fn ($w) => $w->whereIn('users.id', $staff->pluck('user.id'))->where('users.name', 'ilike', Term::like(trim($q))))))
            ->with(User::withAvatar())->orderBy('name')->limit($limit)->get();
        if ($users->isEmpty()) {
            return collect();
        }
        $chats = Chat::withLast()->whereNull('offer_id')->whereNotNull('manager_id')
            ->where(fn ($w) => $w->where('user_id', $me->id)->whereIn('manager_id', $users->pluck('id'))
                ->orWhere(fn ($w) => $w->where('manager_id', $me->id)->whereIn('user_id', $users->pluck('id'))))
            ->get()->keyBy(fn (Chat $c) => $c->user_id === $me->id ? $c->manager_id : $c->user_id);
        // Сотруднику не сотрудник — его чат площадки, а не личка (`viaPlatform`).
        $outside = $users->filter(fn (User $u) => self::viaPlatform($me, $u))->pluck('id');
        if ($outside->isNotEmpty()) {
            $chats = $chats->except($outside->all());
            foreach (Chat::withLast()->whereNull('offer_id')->whereNull('manager_id')->whereIn('user_id', $outside)
                ->orderBy('last_message_at')->get() as $c) {
                $chats->put($c->user_id, $c);
            }
        }

        // С перепиской — первыми, свежие выше; без неё — по имени.
        return $users->map(fn (User $u) => ['user' => $u, 'chat' => $chats->get($u->id)])
            ->sortBy(fn ($p) => $p['chat'] && $p['chat']->messages_count > 0 ? -($p['chat']->last_message_at?->timestamp ?? 0) : PHP_INT_MAX)
            ->values();
    }

    /**
     * Кому пишут — человек из адреса `/with/{user}` или выбор шторки «Написать» (`?user=`, у менеджера и `support`); никого — 404.
     */
    public static function target(Request $request, ?User $user): User|string
    {
        if ($user) {
            return $user;
        }
        $pick = (string) $request->query('user');
        if ($pick === 'support' && self::partners($request->user())->contains('id', 'support')) {
            return 'support';
        }

        return ctype_digit($pick) ? User::findOrFail((int) $pick) : abort(404);
    }

    /** Запрос похож на «Администрация XCAR» — менеджеру она тоже строкой поиска. */
    public static function platformHit(User $me, string $q): bool
    {
        $q = mb_strtolower(trim($q));

        return $q !== '' && str_contains(mb_strtolower(Chat::PLATFORM), $q) && self::partners($me)->contains('id', 'support');
    }

    /** Сотрудники, кроме самого человека. */
    private static function staff(User $me): Collection
    {
        return User::withRole(Role::Admin, Role::Moderator)->where('users.id', '!=', $me->id)->where('is_demo', false)
            ->whereNull('rejected_at')->with(User::withAvatar())->orderBy('name')->get();
    }

    /** Менеджеры и супер-покупатели — сотруднику; сотрудники-менеджеры уже в «Сотрудниках». */
    private static function dealers(User $me): Collection
    {
        return User::withRole(Role::Manager, Role::SuperBuyer)->where('users.id', '!=', $me->id)->where('is_demo', false)
            ->whereNull('rejected_at')->with(User::withAvatar())->orderBy('name')->get()
            ->reject(fn (User $u) => $u->canEditDeals())->values();
    }

    /** Покупатели с доступом — сотруднику, по имени. */
    private static function buyers(): Collection
    {
        return User::withRole(Role::Buyer)->withBuyerAccess()->where('is_demo', false)->whereNull('rejected_at')
            ->with(User::withAvatar())->orderBy('name')->get();
    }

    private static function roleWord(User $u): string
    {
        return $u->isAdmin() ? 'Администратор' : 'Модератор';
    }
}
