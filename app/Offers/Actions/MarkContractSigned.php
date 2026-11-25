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

    public function __invoke(Deal $deal, User $by, ?Carbon $at = null, ?string $label = null): Deal
    {
        return DB::transaction(function () use ($deal, $by, $at, $label) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! self::allows($deal)) {
                throw ValidationException::withMessages(['deal' => 'Отметить договор подписанным сейчас нельзя']);
            }
            self::mark($deal, $by, $at);
            $this->advance($deal, $by, $label);

            return $deal->fresh();
        });
    }

    /**
     * Шаги после отметки. Стоит на шаге договора или данных покупателя — шаги договора проходятся до первого не про договор.
     * Впереди только закрытие — закрывает «Уже продана…» со своими вопросами про деньги и вывоз; но если сделку закрывает сам шаг
     * подписания (у Альфы «Подписание» — «Договор подписан (за менеджера)» → «Сделка закрыта»), подписанный договор и есть его
     * конец: сделка закрывается, как той кнопкой (25.11.2026, 0760/046/14575/26: отметили подписанным, а сделка осталась на «Записи
     * на подписание» с напоминаниями — «зачем мне этот приём»). `$label` — подпись в «Истории» («по письму поставщика 25.11»).
     * Да — сделка закрыта.
     */
    public function advance(Deal $deal, User $by, ?string $label = null): bool
    {
        $offer = Offer::findOrFail($deal->offer_id);
        $stage = $offer->stage()?->loadMissing('workflow');
        if (! $deal->isActive() || ! $stage || ! self::contractual($stage)) {
            return false;
        }
        $run = FastForward::from($deal, $stage, fn (Stage $s) => ! self::contractual($s));
        if (! $run->stop || ($run->closes() && ! self::signingCloses($deal, $run))) {
            return false;
        }
        if ($run->stop->id === $stage->id) {
            return false;
        }
        // Приложил сам менеджер (шаг договора, блок денег на сайте, 18.11.2026) — его ответ «Договор приложен», не «за вас».
        Requirement::closeOpen($deal, $stage->id, $by->id === $deal->buyer_id
            ? ['by' => $by->id, 'exit' => 'Договор приложен']
            : ['closed_by' => 'staff', 'by' => $by->id, 'exit' => 'Договор подписан']);
        ($this->place)($offer, $run->stop, $by, payload: ['label' => $label ?? 'Договор подписан']);

        return $run->closes();
    }

    /** Сделку закрывает сам шаг подписания: последний шаг перед закрытием уходит в него исходом «Договор подписан…». */
    private static function signingCloses(Deal $deal, FastForward $run): bool
    {
        $last = collect($run->passed)->last();

        return $last !== null && $last->exitsFor(\App\Workflow\Actor::Staff, $deal)
            ->contains(fn ($x) => $x->to_stage_id === $run->stop->id && str_contains(mb_strtolower($x->label), 'подписан'));
    }

    /**
     * Поставщик написал «Данное ТС реализовано» по оплаченной сделке (`Mail\OnMessage`): договор у него подписан — если сделка на
     * шагах подписания, они проходятся от системы; на других шагах письмо только строкой «Истории». Ошибка не роняет разбор письма.
     */
    public static function byLetter(Deal $deal, \App\Mail\Message $letter): void
    {
        $stage = Offer::find($deal->offer_id)?->stage();
        // Оплачено (`prepaid_at`) — впереди у Альфы только подготовка договора и подписание: шаг про договор и есть они.
        if (! $stage?->aboutContract()) {
            return;
        }
        rescue(function () use ($deal, $letter) {
            $by = User::withRole(\App\Users\Role::Admin)->orderBy('id')->firstOrFail();
            $label = 'Договор подписан, по письму поставщика '.($letter->date_at ?? now())->timezone('Europe/Moscow')->format('d.m');
            DB::transaction(function () use ($deal, $by, $letter, $label) {
                $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
                if (! $deal->isActive() || $deal->isAuction()) {
                    return;
                }
                self::mark($deal, $by, $letter->date_at);
                app(self::class)->advance($deal, $by, $label);
            });
        }, report: true);
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
