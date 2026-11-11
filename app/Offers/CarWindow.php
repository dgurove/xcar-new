<?php

namespace App\Offers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Окно машины CRM — одна дверь для всех списков (11.11.2026): «Предложения», «Галерея», «Горит», поиск — по номеру,
 * «Сделки» — по сделке, «Гараж» — по номеру; окно одно и то же (`admin/car/window` на `CarView`). Чужое модератору — как
 * не найдено (`Offer::isEditableBy`).
 */
final class CarWindow
{
    public static function of(Request $request, ?Offer $offer, ?Deal $deal = null): ?View
    {
        $user = $request->user();

        return $offer && $user && $offer->isEditableBy($user) ? view('admin.car.window', CarView::for($offer, $user, deal: $deal)->vars()) : null;
    }

    /**
     * Строка «Сделок» — окно её машины о ней: идущая и последняя — как у всех, отменённая или прошлая — о той сделке
     * (`CarView::$pastDeal`). Параллельная своего окна не имеет: она в блоке «Параллельно» сделки машины.
     */
    public static function ofDeal(Request $request, ?Deal $deal): ?View
    {
        return self::of($request, $deal?->offer, $deal);
    }
}
