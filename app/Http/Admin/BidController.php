<?php

namespace App\Http\Admin;

use App\Garage\Actions\EnsurePickup;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\AssignPickup;
use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BidController
{
    /**
     * Принять подтверждение вместе с деньгами: кому платят за ТС (нам или страхователю по ДКП — тогда и сколько),
     * вознаграждение менеджеру суммой и режим. Гаражное — без денег, с тем,
     * кто платит поставщику: вознаграждение назначают, когда машину продадут из гаража.
     */
    public function accept(Request $request, Bid $bid, AcceptBid $accept)
    {
        $switch = $bid->offer->deal()->exists();
        $stage = $this->stageId($bid);
        $default = DealScheme::forOffer($bid->offer);
        if ($bid->isGarage()) {
            $request->merge(['share' => Money::rubles($request->input('share')) ?: null]);
            $data = $request->validate(['payer' => ['nullable', Rule::enum(GaragePayer::class)], 'pickup' => ['nullable', Rule::in(['manager', 'us'])],
                'share' => ['nullable', 'integer', 'min:1']]);
            DB::transaction(function () use ($bid, $data, $request, $accept, $default) {
                $deal = $accept($bid, $request->user(), payer: GaragePayer::tryFrom($data['payer'] ?? '') ?? GaragePayer::Us, scheme: $default, share: isset($data['share']) ? (int) $data['share'] : null);
                // Машина едет к менеджеру в гараж (05.10.2026, Бородин: был вывоз «к нам»), везёт он сам или мы; вывоз у
                // гаражной есть всегда (06.10.2026).
                app(EnsurePickup::class)($deal->offer, $request->user(), ($data['pickup'] ?? 'manager') === 'manager' ? $bid->user : null);
            });

            return $this->accepted($request, $bid, $switch ? null : $stage, ($switch ? 'Отдали ' : 'В гараж: ').$bid->user->shortName());
        }
        $request->merge(['owner_price' => Money::rubles($request->input('owner_price')) ?: null]);
        $data = $request->validate([
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.$bid->amount],
            'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            // По ДКП — что покупатель отдаст страхователю: меньше цены подтверждения, иначе подбору не из чего.
            'owner_price' => ['nullable', 'integer', 'min:1', 'max:'.$bid->amount],
            'pickup' => ['nullable', Rule::in(['manager', 'us'])],
        ]);
        // Оптом (Каркаде) схема одна — ПРАЙМ, выбора в шторке нет и с запроса её не берём.
        $scheme = DealScheme::choiceFor($bid->offer->vendor?->deal_format) ? (DealScheme::tryFrom($data['scheme'] ?? '') ?? $default) : $default;
        // Р.О.С. — только у Альфы (`DealScheme::casesFor`).
        $scheme = in_array($scheme, DealScheme::casesFor($bid->offer->vendor), true) ? $scheme : $default;
        if ($scheme->paysSelection() && ! isset($data['owner_price']) && ! $bid->offer->owner_price && ! $bid->offer->floor_price) {
            return back()->withErrors(['owner_price' => $scheme === DealScheme::OwnerDkp ? 'Сколько покупатель отдаёт страхователю по ДКП' : 'Сколько покупатель отдаёт страховой'])->withInput();
        }
        // Совкомбанк: забирает всегда менеджер сделки — шаг «Заберите автомобиль» (10.10.2026).
        if ($bid->offer->vendor?->isCommission()) {
            $data['pickup'] = 'manager';
        }
        DB::transaction(function () use ($bid, $data, $request, $accept, $scheme) {
            $offer = $bid->offer->loadMissing('vendor.workflows', 'parkVehicle');
            $choosable = isset($data['pickup']) && $offer->pickupChoosable();
            // Отдаём другому, а забирать должен был прежний: отмена сделки поручение снимет, «Мы» — вывозим к нам.
            $wasPicker = $offer->deal && $offer->evacuator_id === $offer->deal->buyer_id && $offer->pickupDestination() === Destination::Keeper;
            $accept($bid, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, CommissionMode::tryFrom($data['mode'] ?? '') ?? CommissionMode::Payout,
                scheme: $scheme, ownerPrice: isset($data['owner_price']) ? (int) $data['owner_price'] : null, loss: $request->boolean('loss'));
            if (! $choosable) {
                return;
            }
            // Кто забирает ТС у владельца (04.10.2026): менеджер сделки — он и вывозчик; мы — как назначено.
            $offer = $offer->fresh(['vendor.workflows', 'positions.stage.workflow']);
            if ($data['pickup'] === 'manager') {
                app(AssignPickup::class)($offer, $bid->user, Destination::Keeper, $request->user());
            } elseif ($wasPicker || ! $offer->evacuation_to) {
                // «Логистика»: вывоз ещё никому не поручен (или забирал прежний) — везём мы, перевозка встаёт в логистику
                // (`SyncPickupTransport`); поручен другому менеджеру — как назначено.
                app(AssignPickup::class)($offer, null, Destination::Ours, $request->user());
            }
        });

        return $this->accepted($request, $bid, $switch ? null : $stage, $switch ? 'Сделка передана '.$bid->user->shortName() : 'Подтверждение принято, сделка открыта');
    }

    /**
     * Ответ «Принять» (владелец 08.10.2026: «нажал Принять — и сразу окошко с письмом»): строка ушла из «Есть
     * подтверждения», карточка — к следующему (`OfferController::gone`), а этап, на который встала сделка, просит
     * письмо вендору («уведомление о покупке») — окно писем открывается с черновиком (`letters`, x-ui.row-card,
     * x-mail.window). Письмо не уходит само: его читают перед отправкой. «Отдать» другому ($before null) — вендору о
     * покупке уже писали, окна нет, даже если ветка сменилась.
     */
    private function accepted(Request $request, Bid $bid, ?int $before, string $toast)
    {
        $after = $before ? $this->stageId($bid) : null;
        $letter = $after && $after !== $before ? $bid->offer->vendorLetterUrl(Stage::find($after)) : null;

        return back()->with('toast', $toast)->with(OfferController::gone($request, $bid->offer))
            ->with('letters', $letter ? $letter.'&then=close' : null);
    }

    /** Этап продажи предложения — только номер: сравнить до и после принятия. */
    private function stageId(Bid $bid): ?int
    {
        return Position::where('offer_id', $bid->offer_id)->where('track', Track::Sale)->value('stage_id');
    }

    /** Подтверждение за менеджера (`PlaceBidFor`): менеджер без интернета — встаёт в список, принимают обычным «Принять». */
    public function storeFor(Request $request, Offer $offer, PlaceBidFor $place)
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['nullable', Rule::enum(BidKind::class)],
            'amount' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);
        $manager = User::findOrFail($data['manager_id']);
        $amount = Money::rubles($data['amount'] ?? null) ?: null;
        $place($offer, $manager, $request->user(), $amount, BidKind::tryFrom($data['kind'] ?? '') ?? BidKind::Buyer, trim((string) ($data['comment'] ?? '')) ?: null);

        return back()->with('toast', 'Подтверждение '.$manager->shortName().' внесено');
    }

    public function decline(Request $request, Bid $bid, DeclineBid $decline)
    {
        $decline($bid, $request->user());

        return back()->with('toast', 'Подтверждение отклонено');
    }
}
