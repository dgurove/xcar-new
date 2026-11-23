<?php

namespace App\Mail\Actions;

use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Прочитано по ходу машины (23.11.2026, владелец: «много непрочитанных по цепочкам, которые уже закрыты», 2610061342 выдана, а
 * письмо висело; из 360 непрочитанных 214 старше месяца). Машина ушла дальше — входящие письма её веток, пришедшие до этого
 * момента, прочитаны: с ними уже работали. Когда:
 * - предложение ушло в сделку или гараж, выдано, в архиве, отменено (`ChangeOfferState`, `STATES`);
 * - черновик из цепочки «Из писем» завёл человек (`PromoteCandidate`; завела почта сама — письма никто не видел);
 * - ТС парковки приняли — заявки на приём (`intake`); выдали или отменили — все.
 * По ходу сделки (23.11.2026, владелец: «пришёл счёт, мы его отправили менеджеру — письмо прочитано», «ГОТС поступили на
 * реализацию, мы спросили менеджера — тоже»):
 * - «Разобрать ответ» закрыт как угодно — его письмо (`ReplyReview::finish`);
 * - шаг сдвинул сотрудник — всё до этого (`onStage`); шаг по письму — это письмо;
 * - машину забрали — всё до этого (`onPlace`, из `SetCarPlace`);
 * - сотрудник написал поставщику по машине — всё до этого, во всех ветках машины (`OnMessage::onSent`);
 * - переоценку решили — её письмо.
 * Письма после выдачи и архива сами не читаются (владелец: там бывает запрос документов) — только этими правилами.
 * Письмо позже момента не трогается: новое о машине остаётся новым. Флаг — тем же путём, что открытие (`MarkMessageRead::apply`:
 * у нас и в ящике). Прошлое — `mail:read-settled`.
 */
final class ReadSettled
{
    /** Состояния, в которые машина уходит дальше продажи: письма до перехода обработаны. */
    public const STATES = [OfferState::Sold, OfferState::Garage, OfferState::Delivered, OfferState::Archived, OfferState::Cancelled];

    public function __construct(private MarkMessageRead $mark) {}

    /** @return list<int> */
    public function offer(Offer $offer, ?Carbon $until = null, bool $apply = true): array
    {
        $threads = Thread::where('offer_id', $offer->id)
            ->orWhereIn('candidate_id', DB::table('mail_candidates')->where('offer_id', $offer->id)->select('id'))->select('id');

        return $this->read($threads, $until ?? now(), null, $apply);
    }

    /** Ветки цепочки «Из писем». @return list<int> */
    public function candidate(Candidate $candidate, ?Carbon $until = null, bool $apply = true): array
    {
        return $this->read($candidate->threads()->pluck('id')->all(), $until ?? now(), null, $apply);
    }

    /** @param bool $intake только заявки на приём (приняли ТС) @return list<int> */
    public function vehicle(Vehicle $vehicle, ?Carbon $until = null, bool $intake = false, bool $apply = true): array
    {
        return $this->read(Thread::where('vehicle_id', $vehicle->id)->select('id'), $until ?? now(), $intake ? Intent::Intake->value : null, $apply);
    }

    /** Переход в `STATES` — после записи перехода (письма помечаются уже закоммиченной машине). */
    public function afterState(Offer $offer, OfferState $from, OfferState $next): void
    {
        if ($from !== $next && in_array($next, self::STATES, true)) {
            $this->afterCommit($offer);
        }
    }

    /** Письмо и всё, что раньше него в ветке, с его «ч.2» — как открыли (`MarkMessageRead`). @return list<int> */
    public function message(Message|int|null $message): array
    {
        $message = is_int($message) ? Message::find($message) : $message;

        return $message ? ($this->mark)($message) : [];
    }

    /**
     * Шаг сделки сдвинул сотрудник — почту машины до этого он видел. Шаг по конкретному письму (`Disclose`, наше письмо
     * шага) — только это письмо и раньше в его ветке; ход менеджера, таймер и шаг, что система сделала по письму сама
     * (`byLetter`), остальную почту не читают.
     */
    public function onStage(StageEntered $e): void
    {
        if (self::$byLetter) {
            return;
        }
        if ($e->letter) {
            $letter = $e->letter;
            DB::afterCommit(fn () => $this->message($letter));
        } elseif ($e->exit?->actor === Actor::Staff && $e->by?->isStaff()) {
            $this->afterCommit($e->offer);
        }
    }

    /** Машину забрали (место было «у владельца» или не указано): письма о вывозе до этого отработаны. */
    public function onPlace(Offer $offer, ?CarPlace $was, ?CarPlace $now): void
    {
        if (in_array($was, [null, CarPlace::Owner], true) && $now && $now !== CarPlace::Owner) {
            $this->afterCommit($offer);
        }
    }

    /**
     * Шаг, который система сдвигает по письму поставщика сама (оплата получена, просьба реквизитов): прочитано это письмо,
     * а не вся почта машины — `onStage` на время хода молчит.
     */
    public function byLetter(Message $message, callable $move): mixed
    {
        self::$byLetter = true;
        try {
            $result = $move();
        } finally {
            self::$byLetter = false;
        }
        DB::afterCommit(fn () => $this->message($message));

        return $result;
    }

    private static bool $byLetter = false;

    private function afterCommit(Offer $offer): void
    {
        $at = now();
        DB::afterCommit(fn () => $this->offer($offer, $at));
    }

    /** @return list<int> */
    private function read($threads, Carbon $until, ?string $intent, bool $apply): array
    {
        $ids = Message::whereIn('thread_id', $threads)->where('direction', Direction::In)->where('is_seen', false)
            ->where('date_at', '<=', $until)->when($intent, fn ($q) => $q->where('intent', $intent))
            ->pluck('id')->all();

        return $apply ? $this->mark->apply($ids, true) : $ids;
    }
}
