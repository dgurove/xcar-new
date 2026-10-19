<?php

namespace App\Workflow\Actions;

use App\Offers\Actions\AgreeDeal;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Users\User;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;

/**
 * Поставить оффер на этап: позиция и срок, состояние или место машины, просьба к менеджеру, лента, событие.
 * Этап оплаты, а счёта у сделки нет — ход наш (`waits_for = us`): без часов и без просьбы менеджеру, её заведёт
 * выставленный счёт (`PassTurnOnInvoice`). Иначе обе стороны ждали друг друга. У ПРАЙМ без покупателя — ход менеджера
 * (`waits_for = manager`, «Укажите покупателя»): счёт встанет сам, когда он его укажет. Первый вход сделки за пределы
 * согласования (`Stage::isAgreement`) ставит её счета (`AgreeDeal`).
 */
final class EnterStage
{
    public function __construct(private ChangeOfferState $changeState, private AgreeDeal $agree) {}

    /** @param  bool  $back  откат («Отменить шаг», «Вернуть на этот шаг»): журнал пути сматывается до прежнего входа сюда */
    public function __invoke(Offer $offer, Stage $to, ?User $by = null, array $payload = [], ?Outcome $exit = null, bool $back = false): Offer
    {
        // Письмо, которое сдвинуло шаг, — в журнал и событие, не в поля этапа.
        $letter = isset($payload['letter']) ? (int) $payload['letter'] : null;
        // Платёжка нашей оплаты, вписанная выходом из этапа оплаты, — подписями в событие, полями нового этапа она не становится.
        $out = array_filter((array) ($payload['out'] ?? []), fn ($v) => filled($v));
        // Как назвать шаг в «Истории», если не именем выхода: «Отдал наличными» тем же выходом «Платёжное поручение приложено».
        $said = isset($payload['label']) ? (string) $payload['label'] : null;
        unset($payload['letter'], $payload['out'], $payload['label']);
        $to->loadMissing(['workflow', 'block', 'exits.to']);
        $track = $to->workflow->track;
        $now = Carbon::now();

        $position = Position::where('offer_id', $offer->id)->where('track', $track)->with('stage')->first();
        $from = $position?->stage;
        // Сделку запоминаем до смены состояния: конечный этап её закрывает, а написать менеджеру надо именно тогда.
        $deal = $offer->deal()->with('buyer')->first();

        if ($from) {
            Requirement::where('offer_id', $offer->id)->where('stage_id', $from->id)->whereNull('done_at')
                ->update(['done_at' => $now, 'answer' => json_encode(['closed_by' => 'stage'])]);
        }

        if ($track === Track::Sale && $to->offer_state === OfferState::Open
            && (! $offer->bids_close_at || $offer->bids_close_at->isPast())) {
            // Как у публикации кнопкой: через N дней в 17:00 (`Slots::closeFor`), N — срок этапа «Приём» в днях.
            $offer->bids_close_at = Slots::closeFor($now, $to->limit_minutes ? max(1, intdiv($to->limit_minutes, 1440)) : null);
            $offer->save();
        }

        // Согласование позади (поставщик сказал «да», менеджер — «Покупаю») — только теперь у сделки встают счета
        // (09.10.2026): до входа сюда, чтобы этап оплаты сразу видел счёт и отдал ход менеджеру.
        // Совкомбанк (10.10.2026): машину забирают без оплаты — этап получения счетов не ставит, они встают после «Забрал».
        if ($track === Track::Sale && $deal?->isActive() && ! $deal->agreed() && ! $to->isAgreement()
            && ! ($deal->isCommission() && $to->isPickupStep())
            && in_array($to->offer_state, [null, OfferState::Sold], true)) {
            ($this->agree)($deal->setRelation('offer', $offer), $by);
        }

        // Платят поставщику сами (Альфа, «Страховой напрямую») — счёт не наш, ждать его нечего: ход менеджера.
        $noInvoice = $track === Track::Sale && $deal?->isActive() && $to->isPayStep() && ! $deal->hasManagerInvoice() && ! $deal->paysSupplierOn($to);
        // ПРАЙМ: счёт встанет сам, как только менеджер укажет, кому он (05.10.2026) — ход его, а не наш «Выставите счёт».
        // Гаражная Совкомбанка без цены продажи — тоже его ход: цену он называет, когда продаёт (`Deal::managerGap`).
        $needsBuyer = $noInvoice && $deal->managerGap();
        $ours = $noInvoice && ! $needsBuyer;
        // Этап с письмом поставщику (11.10.2026): пока письмо не ушло, ход наш и срока нет — сутки на ответ считаются от
        // письма (`MarkSupplierLetter`). Шаг, сдвинутый самим письмом, и откат — не трогаем.
        $awaitsLetter = $track === Track::Sale && $deal?->isActive() && $to->isLetterStep() && ! $letter && ! $back;
        // Шаг «Менеджер забирает автомобиль», а везёт логистика (17.10.2026): ход наш, без часов и просьбы «Заберите» —
        // шаг пройдёт сам по «Привёз» к нему (`SyncHandover`); сняли вывоз — ход снова его (`SyncHandover::turn`).
        $carried = $track === Track::Sale && $deal?->isActive() && $to->isPickupStep() && $deal->setRelation('offer', $offer->unsetRelation('pickupTask'))->carried();
        $position = Position::updateOrCreate(['offer_id' => $offer->id, 'track' => $track->value], [
            'stage_id' => $to->id,
            'waits_for' => $ours || $awaitsLetter || $carried ? WaitsFor::Us : ($needsBuyer ? WaitsFor::Manager : null),
            'entered_at' => $now,
            'block_entered_at' => $from && $from->block_id === $to->block_id && $position?->block_entered_at ? $position->block_entered_at : $now,
            'deadline_at' => match (true) {
                $awaitsLetter => Stage::letterDeadline($now),
                $noInvoice || $carried => null,
                default => $to->deadlineFor($offer, $now),
            },
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => $payload ?: null,
        ]);
        $offer->unsetRelation('positions');

        // Оплата за ТС подтверждена (поставщик получил, «платим мы» — оплатили сами): у Альфы только теперь можно забирать
        // (`Deal::mayPickUp`, 10.10.2026). Откат шага отметку не снимает — деньги уже ушли.
        if ($track === Track::Sale && $deal?->isActive() && ! $deal->prepaid_at && $exit
            && in_array(mb_strtolower(trim($exit->label)), ['оплата получена', 'поставщику оплачено'], true)) {
            $deal->forceFill(['prepaid_at' => $now])->save();
        }

        if ($track === Track::Sale && $to->offer_state && $offer->state !== $to->offer_state && $offer->state->allows($to->offer_state)) {
            $offer = ($this->changeState)($offer, $to->offer_state, $by, followRoute: false);
        }

        if (! $noInvoice && ! $carried && $deal && $deal->isActive() && $deal->buyer_id && $to->awaitsManager($deal)) {
            Requirement::askFor($deal, $to, $position);
        }

        $offer->log(OfferEventType::StageEntered, $by, [
            'track' => $track->value, 'from' => $from?->name, 'to' => $to->name, 'block' => $to->block?->name, 'exit' => $exit?->label,
            'from_id' => $from?->id, 'to_id' => $to->id, ...($back ? ['back' => true] : []), ...($letter ? ['letter' => $letter] : []),
            ...($out ? ['fields' => $out] : []), ...($said ? ['label' => $said] : []),
        ]);
        StageEntered::dispatch($offer, $track, $from, $to, $exit, $by, $deal, $letter);
        // Машина сделки на нашей парковке: стало можно забирать — выдача, пропуск, получатель (18.10.2026, одна дверь).
        if ($track === Track::Sale && $deal) {
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($deal, $by) {
                if ($fresh = $deal->fresh()) {
                    app(\App\Offers\Actions\SyncParkRelease::class)($fresh, $by);
                }
            });
        }

        return $offer;
    }
}
