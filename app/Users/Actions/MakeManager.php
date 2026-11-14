<?php

namespace App\Users\Actions;

use App\Garage\Car;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Offers\Showing;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сделать покупателя менеджером (14.11.2026): обратная дверь к `MakeBuyer`. Покупатель — не ещё одна галка ролей:
 * у него один менеджер, `buyer_sees` и доступ, группы покупателей и показы. Здесь всё это снимается, а роли CRM
 * ставятся менеджерские; группы менеджера админ выбирает уже в карточке — так переход не смешивает два вида групп.
 *
 * Гараж покупателя не переводим: `Car::forBuyer()` смотрит на текущую роль владельца, и после смены роли старая машина
 * показала бы закупочную. Поэтому любая машина гаража — блокер, даже закрытая; так же живая сделка, где он покупатель:
 * после перевода он выпал бы из шага сделки.
 */
final class MakeManager
{
    public function __invoke(User $user, ?User $by = null, ?string $contact = null): User
    {
        if ($user->isManager()) {
            throw ValidationException::withMessages(['user' => 'Он уже менеджер']);
        }
        if ($user->isSuperBuyer()) {
            throw ValidationException::withMessages(['user' => 'Супер-покупатель — не покупатель менеджера']);
        }
        if (! $user->isBuyer() || $user->is_demo) {
            throw ValidationException::withMessages(['user' => 'Он не покупатель']);
        }
        if ($blockers = self::blockers($user)) {
            throw ValidationException::withMessages(['user' => 'Сначала закройте его дела: '.implode(', ', $blockers)]);
        }

        // Чем человек войдёт: у покупателя контакта может не быть вовсе (заведён по одному имени) — менеджеру без него
        // не войти, поэтому телефон или почту просим до сохранения.
        $contactFill = $contact !== null && ! $user->hasSignInContact() ? GiveBuyerAccess::contact($user, $contact) : [];

        // Парковка и исполнитель вывоза — свои поверхности, с менеджером живут. Остальные роли покупателя снимаются.
        $kept = array_values(array_filter([Role::Parking, Role::ParkAdmin, Role::Contractor], fn (Role $role) => $user->hasRole($role)));

        DB::transaction(function () use ($user, $by, $kept, $contactFill): void {
            // Группы покупателя и показы — только покупательские; менеджерские группы админ поставит в карточке.
            $user->groups()->detach();
            Showing::where('user_id', $user->id)->delete();
            $user->userGroups()->detach();

            // Группы сняты до сохранения ролей: `User::saved` на смену ролей зовёт SyncViewers, и новых групп у него
            // ещё нет — в волнах он «Остальные», пока админ не выберет группы в карточке.
            $user->forceFill(array_merge($contactFill, [
                'roles' => collect([Role::Manager, ...$kept])->unique(fn (Role $role) => $role->value)->values()->all(),
                'manager_id' => null,
                'buyer_sees' => 'all',
                'buyer_show_vin' => false,
                'buyer_access_at' => null,
                'invite_id' => null,
                'contact_fields' => [],
                'garage_enabled' => false,
                'garage_price_shown' => false,
                'access' => [],
                'vendor_id' => null,
                'offer_regions' => null,
                'garage_share' => null,
                'deal_share' => null,
                'approved_at' => $user->approved_at ?? now(),
                'approved_by' => $user->approved_by ?? $by?->id,
                'rejected_at' => null,
            ]))->save();
        });

        return $user;
    }

    /**
     * Что не даёт перевести покупателя: гараж (даже закрытый — иначе он увидит закупочную) и живая сделка, где он
     * покупатель (иначе выпадет из шага и договора).
     *
     * @return list<string>
     */
    public static function blockers(User $user): array
    {
        return self::forUsers([$user->id])[$user->id] ?? [];
    }

    /**
     * Те же блокеры пачкой: страница «Покупатели» рисует шторку каждому, и по две выборки на человека — лишняя сотня
     * запросов. Здесь две выборки на всю страницу.
     *
     * @param  iterable<int|string>  $ids
     * @return array<int, list<string>>
     */
    public static function forUsers(iterable $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $cars = Car::whereIn('manager_id', $ids)->selectRaw('manager_id, count(*) as n')->groupBy('manager_id')->pluck('n', 'manager_id');
        $deals = DealContract::whereIn('buyer_user_id', $ids)
            ->whereHas('deal', fn ($q) => $q->whereIn('state', [DealState::Active->value, DealState::Parallel->value]))
            ->selectRaw('buyer_user_id, count(*) as n')->groupBy('buyer_user_id')->pluck('n', 'buyer_user_id');

        $blockers = [];
        foreach ($ids as $id) {
            $blockers[$id] = array_values(array_filter([
                $cars->has($id) ? 'машин в гараже: '.$cars[$id] : null,
                $deals->has($id) ? 'живых сделок как покупателя: '.$deals[$id] : null,
            ]));
        }

        return $blockers;
    }
}
