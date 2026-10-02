<?php

namespace App\Http\Middleware;

use App\Offers\Offer;
use Closure;
use Illuminate\Http\Request;

/**
 * Предложение CRM в руках человека: админу — любое, модератору — черновик не из закупки (`Offer::isEditableBy`).
 * Чужое — 404: не подсказывать, что оно есть.
 */
class EnsureOfferEditable
{
    public function handle(Request $request, Closure $next)
    {
        $offer = $request->route('offer');
        abort_unless($offer instanceof Offer && $offer->isEditableBy($request->user()), 404);

        return $next($request);
    }
}
