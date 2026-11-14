<?php

namespace App\Users\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Notifications\BuyerJoinedNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сделать сотрудника покупателем (14.11.2026, Олег Данилин): покупатель — не ещё одна галка ролей, а отдельная
 * дверь. У него один менеджер, своя карточка и доступ к предложениям; с ролями CRM он не совмещается, поэтому
 * менеджерские настройки снимаются, а покупатели и пригласительные ссылки не остаются висеть на человеке,
 * который больше не менеджер.
 */
final class MakeBuyer
{
    public function __construct(private TransferBuyer $transfer) {}

    public function __invoke(User $user, User $manager, string $sees, bool $access, ?User $transferTo = null, ?string $contact = null): User
    {
        if ($user->isBuyer()) {
            throw ValidationException::withMessages(['user' => 'Он уже покупатель']);
        }
        if (! $manager->isManager() || $manager->is($user)) {
            throw ValidationException::withMessages(['manager_id' => 'Выберите менеджера, к которому прикрепить покупателя']);
        }
        if ($blockers = self::blockers($user)) {
            throw ValidationException::withMessages(['user' => 'Сначала передайте или закройте его дела: '.implode(', ', $blockers)]);
        }

        $buyers = $user->buyers()->get();
        if ($buyers->isNotEmpty() && ! $transferTo) {
            throw ValidationException::withMessages(['transfer_to' => 'Выберите менеджера, к которому перейдут покупатели']);
        }
        if ($transferTo && ($transferTo->is($user) || ! $transferTo->isManager())) {
            throw ValidationException::withMessages(['transfer_to' => 'Покупателей можно передать только менеджеру']);
        }

        // Роли парковки и исполнителя вывоза с покупателем живут: это разные поверхности. Остальные роли CRM —
        // сотруднические, покупателю их не оставляем.
        $kept = array_values(array_filter([Role::Contractor, Role::Parking, Role::ParkAdmin], fn (Role $role) => $user->hasRole($role)));

        // Чем человек войдёт, если у него ещё нет контакта. У сотрудника он уже есть — ветка для будущих конверсий.
        $contactFill = $access && ! $user->hasSignInContact() ? GiveBuyerAccess::contact($user, $contact) : [];
        $contactFields = array_values(array_unique([...($user->contact_fields ?? []), ...array_keys($contactFill)]));

        DB::transaction(function () use ($user, $manager, $sees, $access, $transferTo, $buyers, $kept, $contactFill, $contactFields): void {
            foreach ($buyers as $buyer) {
                ($this->transfer)($buyer, $transferTo);
            }
            // Его ссылки больше не должны звать покупателей к человеку без роли менеджера.
            Invite::where(fn ($q) => $q->where('manager_id', $user->id)->orWhere('created_by', $user->id))
                ->whereNull('disabled_at')->update(['disabled_at' => now()]);

            // Группы менеджеров и модераторов снимаются ДО сохранения ролей: `User::saved` на смену ролей зовёт
            // SyncViewers, и старые группы не должны попасть в пересчёт круга показа.
            $user->userGroups()->detach();

            $user->forceFill(array_merge($contactFill, [
                'roles' => collect([Role::Buyer, ...$kept])->unique(fn (Role $role) => $role->value)->values()->all(),
                'manager_id' => $manager->id,
                'buyer_sees' => $sees === 'picked' ? 'picked' : 'all',
                'buyer_access_at' => $access ? now() : null,
                'contact_fields' => $contactFields,
                'invite_id' => null,
                'access' => [],
                'vendor_id' => null,
                'offer_regions' => null,
                'garage_share' => null,
                'deal_share' => null,
                'garage_enabled' => false,
                'garage_price_shown' => false,
            ]))->save();
        });

        $manager->notify(new BuyerJoinedNotice($user, transferred: true));

        return $user;
    }

    /**
     * Живые дела менеджера: их нельзя молча оставить без ведущего. Для Данилина (14.11.2026) их нет; проверка
     * страхует будущие конверсии.
     *
     * @return list<string>
     */
    public static function blockers(User $user): array
    {
        $items = [];
        if ($bids = Bid::withoutGlobalScope(Bid::SALE)->where('user_id', $user->id)->whereIn('state', [BidState::Active->value, BidState::Accepted->value])->count()) {
            $items[] = 'подтверждений: '.$bids;
        }
        if ($deals = Deal::whereHas('bid', fn ($q) => $q->where('user_id', $user->id))
            ->whereIn('state', [DealState::Active->value, DealState::Parallel->value])->count()) {
            $items[] = 'сделок: '.$deals;
        }
        if ($cars = Car::where('manager_id', $user->id)->where('state', '!=', CarState::Settled->value)->count()) {
            $items[] = 'машин в гараже: '.$cars;
        }

        return $items;
    }
}
