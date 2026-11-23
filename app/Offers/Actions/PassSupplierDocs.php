<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Presets\Sovcombank;
use App\Workflow\Track;
use Illuminate\Support\Facades\Log;

/**
 * Документы поставщику ушли (23.11.2026, Совкомбанк: после оплаты просит договор, акт и платёжку, ответа не шлёт) — шаг
 * «Документы поставщику» (`Stage::isSupplierDocs`) проходит «Документы отправлены» сам: письмом шага (`MarkSupplierLetter`) или
 * «Без письма — сообщили сами» (`SkipLetter`). Только у админа: сделку закрывает он, кто отправил — тот и в «Истории».
 */
final class PassSupplierDocs
{
    public static function after(Offer $offer, ?User $by): void
    {
        $offer->unsetRelation('positions');
        $position = $offer->position(Track::Sale);
        $stage = $position?->stage;
        // Закрывать сделку — право админа (`RouteController::exit`): письмо модератора шаг не двигает, «Документы отправлены»
        // нажмёт админ (письмо ему приходит уведомлением, `Notify::letterSent`).
        if (! $stage?->isSupplierDocs() || ! $by?->canManageCrm()) {
            return;
        }
        $exits = $stage->exitsFor(Actor::Staff, $offer->deal);
        $exit = $exits->first(fn (Outcome $x) => $x->label === Sovcombank::DOCS_SENT) ?? $exits->first();
        if (! $exit) {
            return;
        }
        try {
            app(TakeExit::class)($offer, $exit, Actor::Staff, $by);
        } catch (\Throwable $e) {
            Log::warning('Документы поставщику: шаг не прошёл сам', ['offer' => $offer->id, 'error' => $e->getMessage()]);
        }
    }
}
