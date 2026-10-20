<?php

namespace App\Http\Admin;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Backdate;
use App\Logistics\Actions\Cancel as CancelTransport;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Actions\SetRoute;
use App\Logistics\Actions\StepBack as TransportStepBack;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Workflow\Actions\ExtendDeadline;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\StepBack;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        foreach ($exit->staffFields() as $field) {
            $value = trim((string) $request->input("fields.{$field['key']}", ''));
            if ($value === '' && ($field['required'] ?? false)) {
                throw ValidationException::withMessages(["fields.{$field['key']}" => 'Заполните']);
            }
            if ($value !== '') {
                // Дата со временем — словами, как её прочтёт менеджер: «20 октября, 10:00».
                $value = match ($field['type'] ?? '') {
                    'datetime' => Carbon::parse($value)->translatedFormat('j F, H:i'),
                    'date' => ($field['out'] ?? false) ? Carbon::parse($value)->translatedFormat('j F Y') : $value,
                    default => $value,
                };
                // Платёжка нашей оплаты — подписью в «Историю», не в поля следующего этапа (`Outcome::staffFields`).
                if ($field['out'] ?? false) {
                    $payload['out'][$field['label']] = $value;
                } else {
                    $payload[$field['key']] = $value;
                }
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

    /** «Продлить срок» шага: +1 день, +3 дня или до даты (`ExtendDeadline`). */
    public function deadline(Request $request, Offer $offer, ExtendDeadline $extend)
    {
        $data = $request->validate(['track' => ['required', Rule::enum(Track::class)], 'days' => ['nullable', 'integer', 'min:1', 'max:60'], 'until' => ['nullable', 'date']]);
        $position = $offer->position(Track::from($data['track'])) ?? abort(404);
        $until = ExtendDeadline::untilFrom($position, $data);
        $extend($position, $until, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Срок до '.$until->translatedFormat('j M, H:i'));
    }

    /**
     * «Вывезти» и «Кто вывозит» в CRM (17.10.2026): вывоз — задача логистики (`OpenPickup`), исполнителя выбирает админ —
     * логист (регионы), менеджер сделки или наши (`AssignExecutor`); пусто — задача ждёт в логистике «Без исполнителя».
     * Отвечает туда, откуда нажали: редактор, окошко строки.
     */
    public function pickup(Request $request, Offer $offer, OpenPickup $open, AssignExecutor $assign, SetRoute $route)
    {
        $request->validate(['channel' => ['nullable', Rule::enum(Channel::class)]]);
        $pick = AssignExecutor::fromRequest($request, self: true);
        // Окно «Кто вывозит» — и куда везти, дальность, комментарий (18.10.2026); «Вывезти» без окна их не шлёт. Забирает
        // сам — везёт к себе, точка встаёт сама (`AssignExecutor`).
        $self = $pick && $pick[0] === Channel::Manager;
        $data = $pick && ! $self ? $request->validate(SetRoute::rules(), SetRoute::messages()) : null;
        $by = $request->user();
        $t = DB::transaction(function () use ($offer, $pick, $by, $open, $assign, $route, $data) {
            $t = Transport::openFor($offer) ?? $open($offer, $by, quiet: $pick !== null);
            if (! $pick) {
                return $t;
            }
            $t = $assign($t, $pick[0], $pick[1], $by, $pick[2]);

            return $data === null ? $t : $route($t->fresh(), $data, $by);
        });

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Вывоз: '.($t->carrier() ?? 'ждёт исполнителя'));
    }

    /** «Отменить «шаг»» вывоза из дорожки «Вывоз» — то же, что в логистике (`Logistics\Actions\StepBack`). */
    public function pickupBack(Request $request, Offer $offer, TransportStepBack $back)
    {
        $t = $offer->pickup() ?? abort(404);
        $step = TransportStepBack::last($t);
        $back($t, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Вывоз: отменено «'.(TransportEvent::STEPS[$step] ?? 'шаг').'»');
    }

    /** «Уже вывезли» из дорожки «Вывоз» — то же, что в логистике (`Logistics\Actions\Backdate`): ТС уже не у владельца. */
    public function pickupBackdate(Request $request, Offer $offer, Backdate $backdate)
    {
        $t = $offer->pickup() ?? abort(404);
        $backdate($t, $request->validate(Backdate::rules(), Backdate::messages()), $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Отмечена вывезенной')->with('toast-undo', 'transport');
    }

    /** «Вывоз не нужен»: задача в логистике отменена с причиной (уже везут — нельзя). */
    public function dropPickup(Request $request, Offer $offer, CancelTransport $cancel)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $open = Transport::openFor($offer);
        if ($open?->taken()) {
            throw ValidationException::withMessages(['exit' => 'ТС уже везут: сначала «Привёз» или отмена в логистике']);
        }
        if ($open) {
            $cancel($open, $request->user(), $reason);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Вывоз отменён');
    }
}
