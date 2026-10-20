<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueDealInvoice;
use App\Billing\ChargeKind;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Logistics\Actions\AuctionPickup;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Actions\MarkSold;
use App\Park\VehicleState;
use App\Support\Money;
use App\Support\Phone;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Track;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Продана на аукционе» (Совкомбанк, 20.10.2026, владелец): покупатель с аукциона вендора платит нам по нашему счёту и
 * забирает машину — у нас (с парковки, у менеджера) или ещё у владельца, раньше нашего вывоза. Сделка без менеджера
 * (`buyer_id` пуст, `auction_at`, покупатель — контрагент `buyer_party_id`), схема ПРАЙМ, счёт одной строкой
 * «Транспортное средство …» на цену аукциона, без вознаграждения, паспорта и ДКП («это же для админов»). Предложение
 * закрывается сразу («Выдан»), даже если счёт ещё не оплачен: долг — в «Оплаты → Нам должны». Вывоз, что ещё не в пути, —
 * «Забрал аукцион» (`AuctionPickup`); стоит на нашей парковке — выдача покупателю (`MarkSold`). «Счёт выставлен вне
 * системы» — без счёта и, если надо, без покупателя. Только админ CRM; откат — `UndoAuctionSale`.
 */
final class SellAtAuction
{
    public function __construct(private AuctionPickup $pickup, private MarkSold $markSold, private SetCarPlace $place, private ChangeOfferState $state, private IssueDealInvoice $invoice) {}

    /** Можно ли сейчас: вендор по комиссии, предложение ещё не в сделке и не в гараже. */
    public static function allows(Offer $offer, ?User $by = null): bool
    {
        return ($by === null || $by->canManageCrm())
            && (bool) $offer->vendor?->isCommission()
            && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true)
            && ! $offer->deal()->exists() && ! $offer->garageCar()->exists();
    }

    public static function rules(): array
    {
        return [
            'buyer_kind' => ['required', Rule::in(['person', 'company'])],
            'buyer_name' => ['nullable', 'string', 'max:200', 'required_unless:outside,1'],
            'buyer_phone' => ['nullable', 'string', 'max:40'],
            'buyer_inn' => ['nullable', 'regex:/^\s*(\d\s*){10}(\d\s*\d\s*)?$/'],
            'price' => ['nullable', 'required_unless:outside,1'],
            'sold_on' => ['required', 'date', 'before_or_equal:today'],
            'outside' => ['nullable', 'boolean'],
        ];
    }

    public static function messages(): array
    {
        return ['buyer_name.required_unless' => 'Кто купил', 'price.required_unless' => 'Цена на аукционе', 'buyer_inn.regex' => '10 или 12 цифр',
            'sold_on.before_or_equal' => 'Не позже сегодня'];
    }

    /** @param  array{buyer_kind: string, buyer_name?: ?string, buyer_phone?: ?string, buyer_inn?: ?string, price?: mixed, sold_on: string, outside?: bool}  $data */
    public function __invoke(Offer $offer, User $by, array $data): Deal
    {
        $price = Money::rubles($data['price'] ?? null) ?: null;
        $outside = (bool) ($data['outside'] ?? false);
        if (! $outside && ! $price) {
            throw ValidationException::withMessages(['price' => 'Цена на аукционе']);
        }
        $day = Carbon::parse($data['sold_on'], 'Europe/Moscow');
        $at = $day->isToday() ? now() : $day->setTime(12, 0);

        return DB::transaction(function () use ($offer, $by, $data, $price, $outside, $at) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! self::allows($offer, $by)) {
                throw ValidationException::withMessages(['offer' => 'Отметить продажу на аукционе сейчас нельзя']);
            }
            $party = $this->party($data);
            $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => null, 'amount' => $price, 'cost' => $offer->floor_price,
                'scheme' => DealScheme::Prime, 'state' => DealState::Active, 'auction_at' => $at, 'buyer_party_id' => $party?->id]);
            $wasPlace = $offer->car_place;

            // Вывоз ещё не начинали — забрал покупатель аукциона; уже везут — довезут, он заберёт у нас.
            $taken = ($this->pickup)($offer, $by, $at);
            $vehicle = $offer->parkVehicle;
            $sold = false;
            if (! $taken && $vehicle && in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
                // Стоит (или едет) на нашу парковку — выдаёт парковка покупателю, как по письму о продаже.
                ($this->markSold)($vehicle, $by, $at, $party?->name, $party?->phone);
                $sold = true;
            } elseif ($taken || $offer->fresh()->car_place !== CarPlace::Moving) {
                ($this->place)($offer->fresh(), CarPlace::Buyer, $by);
            }

            $offer->log(OfferEventType::Note, $by, ['text' => 'Продана на аукционе'.implode('', array_map(fn ($p) => ', '.$p, array_filter([
                $party?->name, $price ? Money::rub($price) : null, $outside ? 'счёт вне системы' : null]))),
                'auction' => ['deal' => $deal->id, 'place' => $wasPlace?->value, 'vehicle' => $sold ? $vehicle->id : null, 'since' => $offer->auction_at?->toIso8601String()]]);
            ($this->state)($offer->fresh(), OfferState::Delivered, $by, followRoute: false, auction: true);
            // Шаг маршрута больше ничего не ждёт: ни часов, ни «чей ход».
            $offer->positions()->where('track', Track::Sale->value)->update(['deadline_at' => null, 'waits_for' => null]);

            if (! $outside && $party) {
                ($this->invoice)($deal->fresh(), $by, $party, ChargeKind::Sale, (float) $price, now()->addDays(3)->endOfDay(), withFee: false);
            }

            return $deal->fresh();
        });
    }

    /** Покупатель аукциона: организация — по ИНН среди наших контрагентов, иначе новая; человек — всегда новый. */
    private function party(array $data): ?Party
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($data['buyer_name'] ?? '')));
        if ($name === '') {
            return null;
        }
        $phone = Phone::normalize($data['buyer_phone'] ?? null);
        if (($data['buyer_kind'] ?? 'person') === 'company') {
            $inn = preg_replace('/\D+/', '', (string) ($data['buyer_inn'] ?? '')) ?: null;
            $known = $inn ? Party::where('is_self', false)->where('inn', $inn)->orderBy('id')->first() : null;

            return $known ?? Party::create(['kind' => PartyKind::Company, 'name' => $name, 'inn' => $inn, 'phone' => $phone]);
        }

        return Party::create(['kind' => PartyKind::Person, 'name' => $name, 'phone' => $phone]);
    }
}
