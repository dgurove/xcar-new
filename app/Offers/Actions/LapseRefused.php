<?php

namespace App\Offers\Actions;

use App\Notifications\Notify;
use App\Notifications\RefusalNotice;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Авто-снятие после отказа менеджера (19.11.2026, начальник): машину вернули в продажу на время (сама — на 24 часа, когда отдать
 * было некому; админ — «Вернуть в продажу…» из «Менеджер отказался»), приём кончился, а после возврата (`lapse_since`) никто не
 * подтвердил — в «Архив» и поставщику «не актуально» (`TellSupplierNotActual`). Подтвердили — авто-снятия нет, решает админ
 * («Есть подтверждения»). Часы — `offers:tick`, до `ReturnClosedToRated` (тот такие не берёт). Под замком строки: второй тик
 * ничего не делает. Срок продлили («+1 ч») — ждём нового конца.
 */
final class LapseRefused
{
    public function __construct(private ChangeOfferState $change, private TellSupplierNotActual $tell) {}

    public function due(): int
    {
        $n = 0;
        $ids = Offer::where('state', OfferState::Open)->whereNotNull('lapse_since')
            ->whereNotNull('bids_close_at')->where('bids_close_at', '<=', now())->whereDoesntHave('deal')->pluck('id');
        foreach ($ids as $id) {
            try {
                $n += (int) $this->one($id);
            } catch (Throwable $e) {
                Log::warning('offers: авто-снятие после отказа не прошло', ['offer' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /** Снять одну: true — сняли и написали поставщику. */
    public function one(int $id, ?User $by = null): bool
    {
        $lapsed = DB::transaction(function () use ($id, $by) {
            $offer = Offer::whereKey($id)->lockForUpdate()->first();
            if (! $offer?->lapse_since || $offer->state !== OfferState::Open || ! $offer->bids_close_at?->isPast() || $offer->deal()->exists()) {
                return null;
            }
            // Подтвердили после возврата — снимать нельзя: дальше обычная «Есть подтверждения».
            if ($offer->bids()->where('state', BidState::Active)->where('created_at', '>', $offer->lapse_since)->exists()) {
                $offer->forceFill(['lapse_since' => null])->save();

                return null;
            }
            $offer = ($this->change)($offer, OfferState::Archived, $by);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Снято с продажи: после отказа менеджера никто не подтвердил']);

            return $offer;
        });
        if (! $lapsed) {
            return false;
        }
        // Письмо — после коммита архива: не вышло (нет адреса) — машина всё равно снята, админам громко (`TellSupplierNotActual`).
        $letter = ($this->tell)($lapsed->fresh(), $by);
        $letter && Notify::toStaff(new RefusalNotice($lapsed, 'Снято с продажи', 'После отказа менеджера никто не подтвердил, поставщику ушло «не актуально»'));

        return true;
    }
}
