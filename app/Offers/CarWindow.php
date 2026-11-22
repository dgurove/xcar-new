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
     * Окно «Оплата сделки» — строка «Оплат» (22.11.2026, начальник: «не могу понять, где редактор оплаты, нажимаешь на тачку —
     * попадаешь в сделку»): только «Продажа» (`x-deal.money`) — цена, покупатель, счета, наличные, поступления, вознаграждение —
     * и «Открыть сделку» страницей (`admin/car/money-window`, кусок `admin/car/money-body`). Сделки нет — прежнее окно машины.
     */
    public static function money(Request $request, ?Offer $offer, ?Deal $deal = null): ?View
    {
        $user = $request->user();
        if (! $offer || ! $user || ! $offer->isEditableBy($user)) {
            return null;
        }
        // Строка — сделка человека, а не машины (машину после отмены могли отдать другому): о ней и окно (`CarView::$pastDeal`).
        $vars = CarView::for($offer, $user, deal: $deal?->offer_id === $offer->id ? $deal : null)->vars();

        return ($vars['deal'] ?? $vars['garageCar']?->deal) ? view('admin.car.money-window', $vars) : view('admin.car.window', $vars);
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
