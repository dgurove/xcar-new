<?php

namespace App\Http\Admin;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Cancel as CancelTransport;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\StepBack;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Маршрут на карточке оффера: наши исходы, отмена шага и возврат на пройденный шаг; вывоз — задачей логистики. */
class RouteController
{
    public function exit(Request $request, Offer $offer, Outcome $exit, TakeExit $take, ScheduleOffer $schedule)
    {
        // «Опубликовать» маршрута — с выбором слота, как кнопка редактора: в слот — маршрут догонят часы
        // (`ChangeOfferState` в 16:00 сам найдёт выход в «Приём»), сейчас — шаг как обычно.
        $when = Slots::when($request->input('when'), $request->input('day'), Slots::NOW);
        if ($when !== Slots::NOW && $exit->to?->offer_state === OfferState::Open
            && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            $offer = $schedule($offer, $when, $request->user(), until: Slots::urgentUntil($request->input('urgent_until')));

            return back(fallback: "/offers/{$offer->number}")->with('toast', $offer->isUrgent()
                ? 'Срочно в продаже, приём до '.$offer->bids_close_at->translatedFormat('j M, H:i')
                : 'Выйдет '.Slots::phrase($offer->slot_at));
        }
        $payload = [];
        foreach ($exit->to?->staff_fields ?? [] as $field) {
            $value = trim((string) $request->input("fields.{$field['key']}", ''));
            if ($value !== '') {
                $payload[$field['key']] = $value;
            }
        }
        $offer = $take($offer, $exit, Actor::Staff, $request->user(), $payload);

        // Туда же, откуда нажали: страница сделки, редактор или карточка строки (DetailBack), а не всегда в редактор.
        // Шаг можно отменить — в тосте «Отменить».
        return back(fallback: "/offers/{$offer->number}")->with('toast', $exit->label)
            ->with('toast-undo', (bool) StepBack::undoable($offer, $exit->from->workflow->track));
    }

    /** «Вернуть на этот шаг» у пройденного блока пути: назад — только туда, где предложение уже было. */
    public function place(Request $request, Offer $offer, PlaceOnStage $place)
    {
        $stage = Stage::with('block', 'workflow')->findOrFail($request->validate(['stage_id' => ['required', 'integer']])['stage_id']);
        abort_unless($stage->workflow->vendor_id === $offer->vendor_id, 403);
        // Маршрут гаражной сделки кончился машиной в гараже — назад его ведёт «Отдали по ошибке», не шаг пути.
        abort_if($offer->state === OfferState::Garage, 422);
        $passed = Path::for($offer, $stage->workflow->track)->where('state', Path::DONE)->pluck('block.id')->all();
        abort_unless(in_array($stage->block_id, $passed, true), 422);
        $place($offer, $stage, $request->user(), back: true);

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Снова «'.$stage->block->name.'»');
    }

    /** «Отменить шаг» в текущем шаге пути: назад туда, откуда увёл последний выход (`StepBack`). */
    public function back(Request $request, Offer $offer, StepBack $back)
    {
        $track = Track::from($request->validate(['track' => ['required', 'string']])['track']);
        $undo = StepBack::undoable($offer, $track);
        $back($offer, $track, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', $undo['label'] ? 'Отменено «'.$undo['label'].'»' : 'Снова «'.$undo['to']->name.'»');
    }

    /**
     * «Вывезти» и «Кто вывозит» в CRM (17.10.2026): вывоз — задача логистики (`OpenPickup`), исполнителя выбирает админ —
     * логист (регионы), менеджер сделки или наши (`AssignExecutor`); пусто — задача ждёт в логистике «Без исполнителя».
     * Отвечает туда, откуда нажали: редактор, окошко строки.
     */
    public function pickup(Request $request, Offer $offer, OpenPickup $open, AssignExecutor $assign)
    {
        $request->validate(['executor' => ['nullable', Rule::enum(Executor::class)]]);
        $pick = AssignExecutor::fromRequest($request);
        $by = $request->user();
        $t = DB::transaction(function () use ($offer, $pick, $by, $open, $assign) {
            $t = Transport::openFor($offer) ?? $open($offer, $by, quiet: $pick !== null);

            return $pick ? $assign($t, $pick[0], $pick[1], $by, $pick[2]) : $t;
        });

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Вывоз: '.($t->executorName() ?? 'ждёт исполнителя'));
    }

    /** «Вывоз не нужен»: задача в логистике отменена с причиной (уже везут — нельзя). */
    public function dropPickup(Request $request, Offer $offer, CancelTransport $cancel)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $open = Transport::openFor($offer);
        if ($open?->state->picked()) {
            throw ValidationException::withMessages(['exit' => 'ТС уже везут: сначала «Привёз» или отмена в логистике']);
        }
        if ($open) {
            $cancel($open, $request->user(), $reason);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Вывоз отменён');
    }
}
