<?php

namespace App\Offers;

use App\Cars\Settlement;
use App\Logistics\Transport;
use Illuminate\Http\Request;

/**
 * Город продажи (14.11.2026, начальник: 624569/2026 везут из Новосибирска, продают в Москве) — одна дверь для формы
 * `x-offer.sale-city` и всех, кто её принимает (редактор `OfferRequest`, «Выставить в продажу», «Вернуть в продажу»,
 * «Опубликовать для других»). Хранится `offers.sale_settlement_id`, пусто — совпадает с городом факта (`Offer::saleCity`).
 */
final class SaleCity
{
    /**
     * Правило формы: галка «Продаём там же», пустой город или город факта — совпадает (null); другой — его id.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, ?int $fact): ?int
    {
        if (filter_var($input['sale_city_same'] ?? true, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        $id = (int) ($input['sale_settlement_id'] ?? 0) ?: null;

        return $id === $fact ? null : $id;
    }

    /**
     * Что пришло шторкой: поля нет — null (не трогаем), есть — `['sale_settlement_id' => ?int]`, только если поменялось
     * (иначе пусто — без пустой записи «Изменён» в «Истории»). Город факта — нынешний.
     *
     * @return array{sale_settlement_id?: ?int}|null
     */
    public static function input(Request $request, Offer $offer): ?array
    {
        if (! $request->has('sale_city_same')) {
            return null;
        }
        $request->validate(['sale_settlement_id' => ['nullable', 'integer', 'exists:settlements,id']]);
        $city = self::from($request->all(), $offer->settlement_id);

        return $city === $offer->sale_settlement_id ? [] : ['sale_settlement_id' => $city];
    }

    /** Куда везёт открытый вывоз, если это другой город, — первым в списке поля. Один запрос на машину за ответ. */
    public static function target(Offer $offer): ?Settlement
    {
        $memo = request()->attributes->get('sale-city-target', []);
        if (! array_key_exists($offer->id, $memo)) {
            $to = Transport::openFor($offer)?->toSettlementId();
            $memo[$offer->id] = $to && $to !== $offer->settlement_id ? Settlement::find($to) : null;
            request()->attributes->set('sale-city-target', $memo);
        }

        return $memo[$offer->id];
    }

    /**
     * Поле в форме — одно (окно: «Цены» и «Осмотр» — одна форма, редактор: «Осмотр» и «Цены» в `offer-form`): кто
     * нарисовал первым для этой формы, тот и держит; второй кусок молчит. Шторки со своей формой — каждая своё.
     */
    public static function claim(Offer $offer, string $form): bool
    {
        $seen = request()->attributes->get('sale-city-forms', []);
        $key = $offer->id.'|'.$form;
        if (isset($seen[$key])) {
            return false;
        }
        request()->attributes->set('sale-city-forms', $seen + [$key => true]);

        return true;
    }
}
