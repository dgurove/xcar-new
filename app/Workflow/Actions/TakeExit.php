<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Уйти с этапа настроенным исходом. Состояние перечитывается под блокировкой: оффер могли увести другой кнопкой. */
final class TakeExit
{
    public function __construct(private EnterStage $enter) {}

    public function __invoke(Offer $offer, Outcome $exit, Actor $as, ?User $by = null, array $payload = []): Offer
    {
        return DB::transaction(function () use ($offer, $exit, $as, $by, $payload) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $exit->loadMissing(['from.workflow', 'to']);
            $track = $exit->from->workflow->track;
            $position = Position::where('offer_id', $offer->id)->where('track', $track)->first();

            if ($position?->stage_id !== $exit->stage_id) {
                throw ValidationException::withMessages(['exit' => 'Предложение уже не на этом этапе']);
            }
            if ($exit->actor !== $as) {
                throw ValidationException::withMessages(['exit' => 'Эта кнопка не для вас']);
            }
            if (! $exit->to) {
                throw ValidationException::withMessages(['exit' => 'Кнопка никуда не ведёт']);
            }

            return ($this->enter)($offer, $exit->to, $by, $payload, $exit);
        });
    }
}
