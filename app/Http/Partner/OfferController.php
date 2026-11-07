<?php

namespace App\Http\Partner;

use App\Cars\Car;
use App\Cars\IdentityTaken;
use App\Offers\Actions\DeleteDraft;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\Partner\FindClaim;
use App\Offers\Partner\Handover;
use App\Offers\Partner\PartnerOffers;
use App\Offers\Pricing;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Portion;
use App\Support\Sort;
use App\Support\Table\Column;
use App\Support\Table\ColRole;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Partner XCar (07.11.2026): список черновиков вендора поставщика без закупочной, окно «Номер убытка» и редактор.
 * Видимость — одна дверь `PartnerOffers`; чужое — 404. Никакой автоматики на экранах: ни источников полей, ни разбора
 * текста, ни Мигторга — поставщик вписывает сам.
 */
class OfferController
{
    public const SORTS = ['created' => ['Дата заведения', 'desc'], 'city' => ['Город', 'asc'], 'title' => ['Марка, модель', 'asc']];

    public function index(Request $request)
    {
        $user = $request->user();
        $facets = Facets::for($request, 'partner-offers', Common::city('offers.settlement_id'), Common::category(Car::sql('offers', 'category')));
        ListPrefs::sync($request, 'partner-offers', keep: $facets->keys());
        $sort = Sort::from($request->query('sort'), self::SORTS, '-created');
        $searching = filled($request->query('q'));

        $q = PartnerOffers::for($user)->notEmptyDraft()->with(['settlement', 'car.brand', 'car.model']);
        if ($searching) {
            $q->searchCrm(trim((string) $request->query('q')));
        }
        $facets->apply($q);
        $total = (clone $q)->count();
        $dir = $sort->dir();
        match ($sort->key) {
            'city' => $q->orderByRaw("(select name from settlements where settlements.id = offers.settlement_id) {$dir} nulls last"),
            'title' => $q->orderByRaw('(select brands.name from cars join brands on brands.id = cars.brand_id where cars.id = offers.car_id) '.$dir.' nulls last'),
            default => $q->orderBy('offers.created_at', $dir),
        };
        $q->orderByDesc('offers.id');

        $offers = Portion::of($request, $q, $total);

        return view('partner.offers.index', [
            // Всегда таблица порциями (`?vid=` здесь нет: вид у партнёра один).
            'offers' => $offers,
            'covers' => MediaController::covers($offers->getCollection()),
            'sort' => $sort,
            'facets' => $facets,
            'searching' => $searching,
        ]);
    }

    /** Таблица списка — одно описание на шапку и строки `partner/offers/row`. */
    public static function table(): Table
    {
        return Table::of(
            // Главный кадр — машину узнают глазом (08.11.2026); ширина ровно под кадр, его не меряют.
            new Column('photo', '', role: ColRole::Kind, rem: 4, fixed: true),
            new Column('title', 'Марка, модель', grow: true, sort: 'title'),
            new Column('ref', '№ убытка', from: 'sm', role: ColRole::Code, rem: 10),
            new Column('city', 'Город', from: 'sm', clip: true, sort: 'city', role: ColRole::Place, rem: 8),
            // Цена прямо в строке (08.11.2026): оценочная полем, закупочная рядом на лету; Enter отправляет (`price_row`).
            new Column('value', 'Оценочная', from: 'sm', role: ColRole::Amount, rem: 9.5, fixed: true),
            new Column('floor', 'Закупочная', from: 'sm', role: ColRole::Amount, rem: 7.5, fixed: true),
            new Column('created', 'Заведено', from: 'sm', sort: 'created', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 7.5),
        );
    }

    /** Окно «Номер убытка»: открыть своё, вернуть своё из архива, завести новое или «уже в работе». */
    public function find(Request $request, FindClaim $find)
    {
        $data = $request->validate(['claim_ref' => ['required', 'string', 'max:60']], [], ['claim_ref' => 'номер убытка']);
        [$result, $offer] = $find($request->user(), $data['claim_ref']);
        if ($result === FindClaim::BUSY) {
            throw ValidationException::withMessages(['claim_ref' => 'ТС с этим номером уже в работе']);
        }
        MediaController::forget($request->user()->id);

        return redirect("/offers/{$offer->id}");
    }

    public function edit(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless(PartnerOffers::opens($user, $offer), 404);
        $offer->load(['settlement', 'media', 'vendor']);

        return view('partner.offers.edit', [
            'offer' => $offer,
            'rules' => Pricing::rulesFor($offer),
            'default' => Pricing::rulesFor($offer->vendor_id),
            'queue' => $this->queue($user, $offer),
        ]);
    }

    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update)
    {
        $user = $request->user();
        abort_unless(PartnerOffers::opens($user, $offer), 404);
        $held = $offer->heldByPartner();
        try {
            $update($offer, $request->payload($offer), $user);
        } catch (IdentityTaken $e) {
            // Номер или VIN у другой машины — без её номера и названия (`IdentityTaken` их называет).
            throw ValidationException::withMessages([$e->field === 'vin' ? 'vin' : 'claim_ref' => 'ТС с этим '.($e->field === 'vin' ? 'VIN' : 'номером').' уже в работе']);
        }
        Handover::after($offer, $held);
        MediaController::forget($user->id);
        $offer->refresh();
        // Вписал цену — предложение ушло к нам: из его списка оно пропало, дальше — следующее без цены (последнее — список).
        // Без цены — «Сохранить»: остаётся на месте (08.11.2026: одна кнопка, её подпись — по оценочной).
        $sent = ! PartnerOffers::opens($user, $offer);
        $next = $sent ? $this->next($user, $offer) : $offer;

        // Цена из строки списка (`price_row`, 08.11.2026) — ответ словами, а не страницей: строка уходит сама.
        if ($request->wantsJson()) {
            return response()->json(['sent' => $sent, 'floor' => $offer->floor_price]);
        }

        return redirect($next ? "/offers/{$next->id}" : '/offers')->with('toast', $sent ? 'Цена отправлена: '.$offer->titleWithYear() : 'Сохранено');
    }

    /** Свой черновик без цены — удалить можно (опечатка в номере, завёл не то). Чужой — только наш человек. */
    public function destroy(Request $request, Offer $offer, DeleteDraft $delete)
    {
        $user = $request->user();
        abort_unless(PartnerOffers::opens($user, $offer) && $offer->isDeletableBy($user), 404);
        $delete($offer, $user);
        MediaController::forget($user->id);

        return redirect('/offers')->with('toast', 'Черновик удалён');
    }

    /**
     * Место в очереди для «3 из 57 ‹ ›» в редакторе (08.11.2026): тот же порядок, что список по умолчанию и `next` — новые
     * сверху. Черновика в очереди нет (пустой, только что заведённый) — null.
     *
     * @return array{at: int, total: int, prev: ?int, next: ?int}|null
     */
    private function queue($user, Offer $offer): ?array
    {
        $all = fn () => PartnerOffers::for($user)->notEmptyDraft();
        $before = fn ($q) => $q->where(fn ($w) => $w->where('offers.created_at', '>', $offer->created_at)
            ->orWhere(fn ($e) => $e->where('offers.created_at', $offer->created_at)->where('offers.id', '>', $offer->id)));
        if (! $all()->whereKey($offer->id)->exists()) {
            return null;
        }
        $prev = $before($all())->orderBy('offers.created_at')->orderBy('offers.id')->value('offers.id');
        $next = $all()->whereKeyNot($offer->id)->where(fn ($q) => $q->where('offers.created_at', '<', $offer->created_at)
            ->orWhere(fn ($e) => $e->where('offers.created_at', $offer->created_at)->where('offers.id', '<', $offer->id)))
            ->orderByDesc('offers.created_at')->orderByDesc('offers.id')->value('offers.id');

        return ['at' => $before($all())->count() + 1, 'total' => $all()->count(), 'prev' => $prev, 'next' => $next];
    }

    /** Следующий в его списке после этого (по дате заведения, как список по умолчанию); этот был последним — первый списка. */
    private function next($user, Offer $offer): ?Offer
    {
        $rest = fn () => PartnerOffers::for($user)->notEmptyDraft()->whereKeyNot($offer->id)->orderByDesc('offers.created_at')->orderByDesc('offers.id');

        return $rest()->where(fn ($q) => $q->where('offers.created_at', '<', $offer->created_at)->orWhere(fn ($e) => $e->where('offers.created_at', $offer->created_at)->where('offers.id', '<', $offer->id)))->first()
            ?? $rest()->first();
    }
}
