<?php

namespace App\Http\Admin;

use App\Offers\Actions\AssignPickup;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Park\Actions\CloseRequest;
use App\Users\User;
use App\Workflow\Actions\DropRoute;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\StepBack;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Маршрут на карточке оффера: наши исходы, отмена шага, вывоз и возврат на пройденный шаг. */
class RouteController
{
    public function exit(Request $request, Offer $offer, Outcome $exit, TakeExit $take, ScheduleOffer $schedule)
    {
        // «Опубликовать» маршрута — с выбором слота, как кнопка редактора: в слот — маршрут догонят часы
        // (`ChangeOfferState` в 16:00 сам найдёт выход в «Приём»), сейчас — шаг как обычно.
        $when = (string) $request->input('when', Slots::NOW);
        if ($when !== Slots::NOW && in_array($when, Slots::WHEN, true) && $exit->to?->offer_state === OfferState::Open
            && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            $offer = $schedule($offer, $when, $request->user());

            return back(fallback: "/offers/{$offer->number}")->with('toast', 'Выйдет '.Slots::phrase($offer->slot_at));
        }
        $payload = [];
        foreach ($exit->to?->staff_fields ?? [] as $field) {
            $value = trim((string) $request->input("fields.{$field['key']}", ''));
            if ($value !== '') {
                $payload[$field['key']] = $value;
            }
        }
        // «Забрал» ответственного за вывоз сотрудник жмёт за него (или за нас, когда вывозим сами).
        $offer = $take($offer, $exit, $exit->actor === Actor::Keeper ? Actor::Keeper : Actor::Staff, $request->user(), $payload);

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
     * «Нужен вывоз» и его правка: кто вывозит (пусто — мы) и куда (`Destination`). На парковку — с заявкой на эвакуацию,
     * к менеджеру и к нам — мимо парковки. Отвечает туда, откуда нажали: редактор, «Без цены», «Работа → Вывоз».
     */
    public function pickup(Request $request, Offer $offer, AssignPickup $assign)
    {
        $data = $request->validate([
            'evacuator_id' => ['nullable', 'integer', 'exists:users,id'],
            'evacuation_to' => ['nullable', Rule::enum(Destination::class)],
        ]);
        $evacuator = isset($data['evacuator_id']) ? User::find($data['evacuator_id']) : null;
        $to = Destination::tryFrom((string) ($data['evacuation_to'] ?? '')) ?? ($evacuator ? Destination::Keeper : Destination::Yard);
        // «К менеджеру» без менеджера бывает только у машины в гараже — к её держателю; иначе сегмент спрятан, но мог
        // остаться выбранным.
        if ($to === Destination::Keeper && ! $evacuator && ! $offer->garageCar()->exists()) {
            $to = Destination::Ours;
        }
        $offer->loadMissing('vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests');
        $assign($offer, $evacuator, $to, $request->user());
        $toast = 'Вывоз: '.($evacuator?->shortName() ?? 'мы').', '.($to === Destination::Keeper && ($keeper = $offer->keeper()) ? 'к '.$keeper->shortName() : mb_strtolower($to->label()));

        return back(fallback: "/offers/{$offer->number}")->with('toast', $toast);
    }

    public function dropPickup(Request $request, Offer $offer, DropRoute $drop, CloseRequest $close)
    {
        $drop($offer, Track::Service, $request->user());
        if ($vehicle = $offer->parkVehicle) {
            foreach ($vehicle->requests->filter(fn ($r) => $r->isTow() && $r->isOpen()) as $open) {
                $close($open, $request->user(), false, 'Вывоз не нужен');
            }
        }

        return redirect("/offers/{$offer->number}")->with('toast', 'Вывоз отменён');
    }
}
