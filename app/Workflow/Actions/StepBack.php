<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Path;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отменить шаг»: вернуть предложение ровно туда, откуда его увёл последний шаг по журналу пути, — нажали «Ответ
 * поставщика получен», а ответа не было. Цепочкой: откат сматывает журнал, и следующим отменяется предыдущий шаг.
 * Не отменяется: первый этап, конечный («Сделка закрыта»), и шаг, возврат с которого сменил бы состояние
 * предложения (назад в приём из проданного) — принятие подтверждения отменяют «Отдать» или отменой сделки.
 * Деньги откат не трогает: подтверждённую оплату отменяют на странице счёта.
 */
final class StepBack
{
    public function __construct(private EnterStage $enter) {}

    public function __invoke(Offer $offer, Track $track, User $by): Offer
    {
        return DB::transaction(function () use ($offer, $track, $by) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $undo = self::undoable($offer, $track);
            if (! $undo) {
                throw ValidationException::withMessages(['stage' => 'Этот шаг не отменить']);
            }

            return ($this->enter)($offer, $undo['to'], $by, back: true);
        });
    }

    /**
     * Что отменит «Отменить шаг» сейчас: каким выходом пришли, когда, куда вернётся. Одно место на вид и действие.
     *
     * @return ?array{label: ?string, at: Carbon, to: Stage}  label — выход, которым пришли; нет — переставили руками
     */
    public static function undoable(Offer $offer, Track $track): ?array
    {
        $position = Position::where('offer_id', $offer->id)->where('track', $track)->with('stage.exits')->first();
        $last = $position ? Path::journal($offer, $track)->last() : null;
        if (! $last || $position->stage->exits->isEmpty()) {
            return null;
        }
        $here = $last['stage_id'] ? $last['stage_id'] === $position->stage_id : $last['stage'] === $position->stage->name;
        if (! $here || (! $last['from_id'] && ! $last['from'])) {
            return null;
        }
        $in = Stage::where('workflow_id', $position->stage->workflow_id);
        $to = $last['from_id'] ? $in->whereKey($last['from_id'])->first() : $in->where('name', $last['from'])->first();
        if (! $to || ($to->offer_state && $to->offer_state !== $offer->state)) {
            return null;
        }

        return ['label' => $last['exit'], 'at' => $last['at'], 'to' => $to];
    }
}
