<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Plural;
use App\Telegram\Bot;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Номера отправлены вендору в запрос (15.10.2026, владелец каждый день шлёт Т-Страхованию номера убытка, по которым есть
 * интерес или подтверждение): метка `requested_at` / `requested_by` и строка в «Истории» у каждого, отправившему — номера
 * столбиком в Telegram. Отправленный в «К запросу» больше не попадает; вернуть — `clear`.
 */
final class MarkRequested
{
    public function __construct(private Bot $bot) {}

    /**
     * Метит только ещё не отправленные с номером убытка; возвращает их номера. `$at` — дата задним числом (списки, что
     * владелец отправлял до кнопки), `$notify` — слать ли в Telegram.
     *
     * @param  Collection<int, Offer>  $offers
     * @return list<string>
     */
    public function __invoke(Vendor $vendor, Collection $offers, User $by, bool $notify = true, ?Carbon $at = null): array
    {
        $offers = $offers->filter(fn (Offer $o) => $o->vendor_id === $vendor->id && $o->requested_at === null && filled($o->claim_ref))->values();
        if ($offers->isEmpty()) {
            return [];
        }
        $at ??= now();
        DB::transaction(function () use ($offers, $by, $at) {
            foreach ($offers as $o) {
                $o->forceFill(['requested_at' => $at, 'requested_by' => $by->id])->saveQuietly();
                $o->log(OfferEventType::Requested, $by);
            }
        });
        $refs = $offers->pluck('claim_ref')->all();
        if ($notify && $by->telegram_chat_id) {
            $n = count($refs);
            $this->bot->send((int) $by->telegram_chat_id, e($vendor->name).', запрос '.$at->translatedFormat('j M').': '.$n.' '.Plural::of($n, ['номер', 'номера', 'номеров'])
                ."\n\n".implode("\n", array_map(fn ($r) => '<code>'.e($r).'</code>', $refs)));
        }

        return $refs;
    }

    /** Снять метку: номер снова в «К запросу». */
    public function clear(Offer $offer, User $by): void
    {
        if ($offer->requested_at === null) {
            return;
        }
        $offer->forceFill(['requested_at' => null, 'requested_by' => null])->saveQuietly();
        $offer->log(OfferEventType::Requested, $by, ['cleared' => true]);
    }
}
