<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Telegram\Bot;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Запрос номеров вендору (15–16.10.2026, владелец каждый день шлёт Т-Страхованию номера убытка с интересом или
 * подтверждением): запись `ClaimRequest` со снимком номеров, у каждого предложения — ссылка на неё и строка «Истории»,
 * отправившему — номера в Telegram (в буфер их кладёт браузер в момент нажатия). Отправленный в «К запросу» больше не
 * попадает; вернуть — `giveBack`.
 */
final class SendClaimRequest
{
    public function __construct(private Bot $bot) {}

    /** Только «К запросу» этого вендора (`ClaimRequest::pendingFor`) из переданных; ничего — null. */
    public function __invoke(Vendor $vendor, array $ids, User $by, bool $notify = true): ?ClaimRequest
    {
        $request = DB::transaction(function () use ($vendor, $ids, $by) {
            $offers = ClaimRequest::pendingFor($vendor)->whereIn('offers.id', array_map('intval', $ids))->orderBy('offers.id')->lockForUpdate()->get();
            if ($offers->isEmpty()) {
                return null;
            }
            $request = ClaimRequest::create(['vendor_id' => $vendor->id, 'user_id' => $by->id, 'refs' => $offers->pluck('claim_ref')->values()->all()]);
            $this->mark($offers, $request, $by);

            return $request;
        });
        if ($request && $notify) {
            $this->telegram($request, $by);
        }

        return $request;
    }

    /** Прислать номера запроса в Telegram ещё раз. */
    public function telegram(ClaimRequest $request, User $to): bool
    {
        if (! $to->telegram_chat_id) {
            return false;
        }
        $this->bot->send((int) $to->telegram_chat_id, $request->telegram());

        return true;
    }

    /** Вернуть номер в «К запросу»: в снимке запроса он остаётся — это правда о том, что отправили. */
    public function giveBack(Offer $offer, User $by): void
    {
        if ($offer->claim_request_id === null) {
            return;
        }
        $offer->forceFill(['claim_request_id' => null])->saveQuietly();
        $offer->log(OfferEventType::Requested, $by, ['cleared' => true]);
    }

    /** @param Collection<int, Offer> $offers */
    private function mark(Collection $offers, ClaimRequest $request, User $by): void
    {
        foreach ($offers as $o) {
            $o->forceFill(['claim_request_id' => $request->id])->saveQuietly();
            $o->log(OfferEventType::Requested, $by, ['request' => $request->id]);
        }
    }
}
