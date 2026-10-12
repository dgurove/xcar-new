<?php

namespace App\Offers;

use App\Users\BuyerGroup;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Показ: менеджер открыл предложение покупателю лично или группе. Ровно одно из
 * user_id / group_id. Видимость покупателю считается от показов его менеджера.
 */
#[Fillable(['offer_id', 'manager_id', 'user_id', 'group_id', 'show_vin', 'created_at'])]
class Showing extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'show_vin' => 'bool'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BuyerGroup::class, 'group_id');
    }

    /**
     * Кто из покупателей видит оффер: личные показы плюс участники показанных
     * групп, без повторов. Живые уведомления и «видят N» считают по этому же списку.
     *
     * @return Collection<int, int> id покупателей
     */
    public static function buyerIdsOf(Offer|int $offer, ?int $manager = null): Collection
    {
        $offerId = $offer instanceof Offer ? $offer->id : $offer;
        $direct = self::where('offer_id', $offerId)->when($manager, fn ($q) => $q->where('manager_id', $manager))->whereNotNull('user_id')->pluck('user_id');
        $viaGroups = DB::table('buyer_group_user')
            ->whereIn('group_id', self::where('offer_id', $offerId)->when($manager, fn ($q) => $q->where('manager_id', $manager))->whereNotNull('group_id')->select('group_id'))
            ->pluck('user_id');
        $ids = $direct->merge($viaGroups)->unique()->values();

        // Кто видит всё, что открыто менеджеру (12.10.2026), показом ничего не получает — его здесь нет.
        return $ids->isEmpty() ? $ids : User::whereIn('id', $ids)->where('buyer_sees', 'picked')->pluck('id')->values();
    }

    /**
     * Все покупатели, кому предложение сейчас видно: открытые вручную плюс те, кто видит всё, у менеджеров, чья волна
     * наступила. Живое обновление карточки и уведомления о выходе в продажу — по этому списку.
     *
     * @return Collection<int, int>
     */
    public static function seersOf(Offer $offer): Collection
    {
        $managers = DB::table('offer_viewers')->where('offer_id', $offer->id)->where('opens_at', '<=', now())->select('user_id');
        $all = User::withRole(Role::Buyer)->where('buyer_sees', '!=', 'picked')->whereIn('manager_id', $managers)->whereNull('rejected_at')->pluck('id');

        return self::buyerIdsOf($offer)->merge($all)->unique()->values();
    }

    /**
     * Есть ли у менеджера кому открывать вручную: покупатели в режиме «Выбранные». Нет — значка «Открыть покупателям» на
     * плитке и «Выбрать» нет: все и так видят всё. Раз на запрос.
     */
    public static function pickable(User $manager): bool
    {
        if (app()->runningInConsole()) {
            return User::withRole(Role::Buyer)->where('manager_id', $manager->id)->where('buyer_sees', 'picked')->exists();
        }
        $key = 'showings.pickable.'.$manager->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, User::withRole(Role::Buyer)->where('manager_id', $manager->id)->where('buyer_sees', 'picked')->whereNull('rejected_at')->exists());
        }

        return request()->attributes->get($key);
    }

    /** Покупатели менеджера, что видят всё открытое ему (`buyer_sees = all`). */
    public static function seeAllCount(User $manager): int
    {
        return User::withRole(Role::Buyer)->where('manager_id', $manager->id)->where('buyer_sees', '!=', 'picked')->whereNull('rejected_at')->count();
    }

    /**
     * Сколько покупателей видит каждый из офферов у менеджера — одной выборкой
     * на список, для чипа «видят N» на карточке.
     *
     * @param  list<int>  $offerIds
     * @return array<int, int> offer_id → число покупателей
     */
    public static function countsFor(User $manager, array $offerIds): array
    {
        if (! $offerIds) {
            return [];
        }
        $rows = DB::table('showings as s')
            ->leftJoin('buyer_group_user as m', 'm.group_id', '=', 's.group_id')
            ->join('users as u', 'u.id', '=', DB::raw('coalesce(s.user_id, m.user_id)'))
            ->where('u.buyer_sees', 'picked')
            ->where('s.manager_id', $manager->id)
            ->whereIn('s.offer_id', $offerIds)
            ->selectRaw('s.offer_id, count(distinct coalesce(s.user_id, m.user_id)) as n')
            ->groupBy('s.offer_id')
            ->pluck('n', 'offer_id');

        // Видящие всё видят каждое открытое менеджеру — прибавляются к каждому.
        $all = self::seeAllCount($manager);

        return array_map(fn ($n) => (int) $n + $all, $rows->all() + array_fill_keys($offerIds, 0));
    }

    /**
     * Числа «видят N» для карточек списка кладутся на запрос: карточка берёт их
     * сама, без пропсов и без запроса на каждую. Не менеджеру — ничего.
     *
     * @param  list<int>  $offerIds
     */
    public static function remember(?User $user, array $offerIds): void
    {
        if (! $user?->isManager() || app()->runningInConsole()) {
            return;
        }
        $known = request()->attributes->get('showings.counts', []);
        request()->attributes->set('showings.counts', $known + self::countsFor($user, $offerIds));
    }

    /** Число «видят N» для карточки из запомненного на запросе; null — не считали. */
    public static function remembered(int $offerId): ?int
    {
        $known = request()->attributes->get('showings.counts');

        return $known === null ? null : ($known[$offerId] ?? 0);
    }

    /** Сводка для CRM: сколько покупателей у скольких менеджеров, по менеджерам. */
    public static function summary(Offer $offer): Collection
    {
        return User::withRole(Role::Manager)
            ->whereIn('id', self::where('offer_id', $offer->id)->select('manager_id'))
            ->orderBy('name')->get()
            ->map(fn (User $m) => ['manager' => $m, 'buyers' => self::buyerIdsOf($offer, $m->id)->count()]);
    }
}
