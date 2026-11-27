<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Notifications\DealEventNotice;
use App\Notifications\Notify;
use App\Offers\Deal;
use App\Offers\DealTabs;
use App\Offers\ExtraAsk;
use App\Offers\ExtraRequest;
use App\Offers\ExtraWant;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Менеджер попросил «Доп. запрос…» (27.11.2026, `Offers\ExtraWant`): запись доп. просьбы без письма — ход сделки стоит, как при
 * любой доп. просьбе (`ExtraAsk`), сделка во вкладке CRM «Доп. просьба», группа «Менеджер просит». В «Историю» машины, в его чат
 * строкой площадки, тем, кто ведёт сделки, — громко: ждёт наше письмо.
 */
final class WantExtra
{
    /**
     * Приняли подтверждение с галками доп. запроса (`bids.extra_picks`, 27.11.2026): сделка открывается с его просьбой. Признак
     * проверен при подтверждении; просьба уже есть (переприём `CarryDeal`) или сделка параллельная — ничего.
     */
    public function fromBid(?Deal $deal): ?ExtraAsk
    {
        $bid = $deal?->bid_id ? \App\Offers\Bid::find($deal->bid_id) : null;
        if (! $bid?->extra_picks || ! $deal->buyer || $bid->user_id !== $deal->buyer_id || ! $deal->isActive() || $deal->isParallel() || ExtraAsk::holding($deal)) {
            return null;
        }

        return $this($deal, $deal->buyer, $bid->extra_picks, $bid->comment, fromBid: true);
    }

    /** @param list<int> $picks */
    public function __invoke(Deal $deal, User $by, array $picks, ?string $comment, bool $fromBid = false): ExtraAsk
    {
        $choices = ExtraWant::choices()->keyBy('id');
        $picks = array_values(array_unique(array_filter(array_map('intval', $picks), fn (int $id) => $choices->has($id))));
        if ($picks === []) {
            throw ValidationException::withMessages(['picks' => 'Отметьте, что запросить']);
        }
        $comment = trim((string) $comment) ?: null;

        $ask = DB::transaction(function () use ($deal, $by, $picks, $comment, $fromBid) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! ($fromBid ? $deal->isActive() && ! ExtraAsk::holding($deal) : ExtraWant::can($deal, $by))) {
                throw ValidationException::withMessages(['picks' => 'Доп. запрос уже идёт или сделка закрыта']);
            }
            $offer = $deal->offer;
            $text = ExtraRequest::phrase(ExtraRequest::picked($picks), $offer->settlement?->name);
            $ask = ExtraAsk::create([
                'offer_id' => $offer->id, 'deal_id' => $deal->id, 'message_id' => null, 'text' => mb_substr($text, 0, 500),
                'asks_manager' => true, 'wanted_by' => $by->id, 'picks' => $picks, 'comment' => $comment ? mb_substr($comment, 0, 500) : null,
                'asked_at' => now(), 'since_at' => null, 'frozen_at' => now(),
            ]);
            $offer->log(OfferEventType::ExtraRequest, $by, ['state' => ExtraAsk::WANTED, 'text' => $text, 'ask' => $ask->id] + ($comment ? ['comment' => $comment] : []) + ($fromBid ? ['bid' => true] : []));

            return $ask;
        });

        $deal->refresh();
        DB::afterCommit(function () use ($deal, $ask, $by) {
            $ask->setRelation('deal', $deal);
            if ($deal->offer->chatOpenFor($by)) {
                app(PlatformMessage::class)(app(OpenChat::class)($deal->offer, $by, greet: false), 'Вы попросили запросить у поставщика: '.ExtraWant::words($ask, lower: true));
            }
            Notify::toDeals(DealEventNotice::extraWanted($deal, $ask, $by));
        });
        DealTabs::forget();
        Disclose::publish($deal);

        return $ask;
    }
}
