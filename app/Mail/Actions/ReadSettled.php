<?php

namespace App\Mail\Actions;

use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Прочитано по ходу машины (23.11.2026, владелец: «много непрочитанных по цепочкам, которые уже закрыты», 2610061342 выдана, а
 * письмо висело; из 360 непрочитанных 214 старше месяца). Машина ушла дальше — входящие письма её веток, пришедшие до этого
 * момента, прочитаны: с ними уже работали. Когда:
 * - предложение ушло в сделку или гараж, выдано, в архиве, отменено (`ChangeOfferState`, `STATES`);
 * - черновик из цепочки «Из писем» завёл человек (`PromoteCandidate`; завела почта сама — письма никто не видел);
 * - ТС парковки приняли — заявки на приём (`intake`); выдали или отменили — все.
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
            $at = now();
            DB::afterCommit(fn () => $this->offer($offer, $at));
        }
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
