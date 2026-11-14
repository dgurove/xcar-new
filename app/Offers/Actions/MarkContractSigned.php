<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\FastForward;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Asks;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Договор уже подписан» (14.11.2026, владелец: «договор уже подписан — тогда покупатель скип, реквизиты не нужны»): отметка
 * `deals.contract_signed_at` — покупателя и его данных больше не просим (`Deal::contractSigned`), дело «Укажите покупателя»
 * гаснет. Стоит на шаге договора или данных покупателя — шаги договора проходятся за менеджера до первого не про договор
 * (`FastForward`), как и в «Уже продана…» (`MarkSoldDeal`, оно ставит ту же отметку).
 */
final class MarkContractSigned
{
    public function __construct(private PlaceOnStage $place) {}

    public static function allows(?Deal $deal): bool
    {
        return $deal?->isActive() && ! $deal->contractSigned() && ! $deal->isAuction();
    }

    public function __invoke(Deal $deal, User $by, ?Carbon $at = null): Deal
    {
        return DB::transaction(function () use ($deal, $by, $at) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! self::allows($deal)) {
                throw ValidationException::withMessages(['deal' => 'Отметить договор подписанным сейчас нельзя']);
            }
            self::mark($deal, $by, $at);
            $offer = Offer::findOrFail($deal->offer_id);
            $stage = $offer->stage()?->loadMissing('workflow');
            if ($stage && self::contractual($stage)) {
                $run = FastForward::from($deal, $stage, fn (Stage $s) => ! self::contractual($s));
                // Дальше — только закрытие (у Альфы подписание — последний шаг): закрывает «Уже продана…» со своими вопросами
                // про деньги и вывоз, а не эта отметка.
                if ($run->stop && ! $run->closes()) {
                    Requirement::closeOpen($deal, $stage->id, ['closed_by' => 'staff', 'by' => $by->id, 'exit' => 'Договор подписан']);
                    ($this->place)($offer, $run->stop, $by);
                }
            }

            return $deal->fresh();
        });
    }

    /** Отметка и строка «Истории» — общие с «Уже продана…». */
    public static function mark(Deal $deal, User $by, ?Carbon $at = null): void
    {
        if ($deal->contractSigned()) {
            return;
        }
        $deal->forceFill(['contract_signed_at' => $at ?? now(), 'contract_signed_by' => $by->id])->save();
        AskBuyer::settle($deal);
        Offer::find($deal->offer_id)?->log(OfferEventType::Note, $by, ['text' => 'Договор с покупателем подписан вне системы — данные покупателя не нужны']);
    }

    /** Шаг про договор или данные покупателя для него. */
    private static function contractual(Stage $s): bool
    {
        return $s->aboutContract() || $s->asks === Asks::Buyer || $s->isRequisitesStep();
    }
}
