<?php

namespace App\Offers\Partner;

use App\Cars\Identity;
use App\Mail\Extraction\Code;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Intake\OpenDraft;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * «+ Новое» у поставщика начинается с номера убытка (07.11.2026, владелец):
 * - его черновик без закупочной — открывается редактор (`OPEN`);
 * - его предложение в архиве (отказ, не продали, сняли) — снова черновик без цен, редактор (`REOPENED`);
 * - номер есть где-то ещё — в работе у нас, у другого вендора, в таблице Кати, на аукционе, в сделке — «уже в работе»
 *   без подробностей (`BUSY`);
 * - номера нет нигде — новый черновик его вендора (`CREATED`).
 * Номер Т-Страхования и Совкомбанка с другим годом — тот же номер (`Identity::offersAnyYear`, `offerOtherYear`). Замок —
 * тот же, что у `OpenDraft`: письмо, Мигторг и поставщик одновременно двух черновиков не заведут.
 */
final class FindClaim
{
    public const OPEN = 'open';

    public const REOPENED = 'reopened';

    public const CREATED = 'created';

    public const BUSY = 'busy';

    public function __construct(private CreateOffer $create, private OpenDraft $open, private UpdateOffer $update) {}

    /** @return array{0: string, 1: ?Offer} */
    public function __invoke(User $user, string $ref): array
    {
        $ref = (string) Code::normalize(trim($ref));
        $key = Code::key($ref);
        if (! $key || mb_strlen($ref) > 60 || ! $user->isSupplier() || ! $user->vendor_id) {
            throw ValidationException::withMessages(['claim_ref' => 'Впишите номер убытка']);
        }

        return Cache::lock("draft:claim:{$key}", 30)->block(15, fn () => $this->find($user, $ref, $key));
    }

    /** @return array{0: string, 1: ?Offer} */
    private function find(User $user, string $ref, string $key): array
    {
        $car = Identity::carByClaim($key);
        $offer = $car?->offer ?? (Identity::offersAnyYear([$key])[$key] ?? null) ?? Identity::offerOtherYear($key);
        if (! $offer && LegacyClaim::has($key)) {
            // Номер в таблице Кати — сделка идёт вне системы: уже в работе.
            return [self::BUSY, null];
        }
        // Машина у нас без продажи, но ТС парковки чужого вендора — в работе: своё предложение на чужую машину не встаёт.
        if (! $offer && $car?->vehicle && Vendor::forCrm($car->vehicle->vendor_id) !== $user->vendor_id) {
            return [self::BUSY, null];
        }
        if (! $offer) {
            // Машина у нас без продажи (своя на парковке) — предложение встаёт на неё же: одна машина — одна запись.
            $offer = ($this->create)($user, ['vendor_id' => $user->vendor_id, 'claim_ref' => $ref, 'prices_include_vat' => Vendor::offersVat($user->vendor_id)], ['partner' => true], $car);

            return [self::CREATED, $offer];
        }
        if ($offer->vendor_id !== $user->vendor_id || LegacyClaim::has($offer->claim_ref_key)) {
            return [self::BUSY, null];
        }
        if (PartnerOffers::opens($user, $offer)) {
            return [self::OPEN, $offer];
        }
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true) && PartnerOffers::eligible(Offer::whereKey($offer->id))->exists()) {
            return [self::REOPENED, $this->reopen($offer, $user)];
        }

        return [self::BUSY, null];
    }

    /**
     * Из архива — черновиком без цен (`OpenDraft::reopenArchived`): закупочная, продажная, заявленная, минимальная и ступени
     * гаснут, слота и закрытого приёма нет. Оценочная машины остаётся: та же колонка — заявленная стоимость парковки, по
     * ней считается хранение; поставщик вписывает свою (`OfferRequest::payload`). Сделок, гаража, закупки и аукциона у
     * такого не бывает — проверено до (`PartnerOffers::eligible`).
     */
    private function reopen(Offer $offer, User $user): Offer
    {
        [$offer] = $this->open->reopenArchived($offer, $user);
        $offer->forceFill(['slot_at' => null, 'auction_planned_at' => null, 'intake_closed_at' => null])->save();
        ($this->update)($offer, ['floor_price' => null, 'asking_price' => null, 'publish_price' => null, 'min_bid_price' => null, 'min_bid_share' => null, 'floor_rules' => null],
            $user, ['why' => 'Вернул из архива поставщик']);

        return $offer->refresh();
    }
}
