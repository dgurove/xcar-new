<?php

namespace App\Http\Admin;

use App\Logistics\Actions\Cancel as CancelTransport;
use App\Logistics\Actions\SaveTransport;
use App\Logistics\Actions\SendToLogistics;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\Actions\AssignPickup;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Slots;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * «Нужен вывоз» и его правка (10.10.2026 — развилкой): «Везёт сам менеджер» — кто из менеджеров и куда (`Destination`:
     * к нему или к нам); «Логистика» — перевозка в logistics.xcar.ru (`SendToLogistics`), везём не мы руками менеджера,
     * а партнёр, наш эвакуатор или перевозчик. Перешли с логистики на менеджера — открытая перевозка отменяется.
     * Отвечает туда, откуда нажали: редактор, «Без цены».
     */
    public function pickup(Request $request, Offer $offer, AssignPickup $assign, SendToLogistics $send, CancelTransport $cancel)
    {
        $logistics = $request->validate(['mode' => ['required', Rule::in(['self', 'logistics'])]], ['mode.required' => 'Кто везёт: менеджер или логистика'])['mode'] === 'logistics';
        $data = $request->validate([
            'evacuator_id' => [$logistics ? 'nullable' : 'required', 'integer', 'exists:users,id'],
            'evacuation_to' => ['nullable', Rule::enum(Destination::class)],
            ...($logistics ? SaveTransport::rules() : []),
        ], ['evacuator_id.required' => 'Выберите менеджера', ...SaveTransport::messages()]);
        $evacuator = ! $logistics && isset($data['evacuator_id']) ? User::find($data['evacuator_id']) : null;
        $to = Destination::tryFrom((string) ($data['evacuation_to'] ?? '')) ?? ($evacuator ? Destination::Keeper : Destination::Ours);
        // «К менеджеру» без менеджера бывает только у машины в гараже — к её держателю.
        if ($to === Destination::Keeper && ! $evacuator && ! $offer->garageCar()->exists()) {
            $to = Destination::Ours;
        }
        $offer->loadMissing('vendor.workflows', 'positions.stage.workflow');
        $by = $request->user();
        // Машина уже на эвакуаторе — менеджеру её не передать, пока в логистике не нажали «Сдал» или не отменили.
        if (! $logistics) {
            self::notMoving($offer, 'mode');
        }
        $transport = DB::transaction(function () use ($offer, $evacuator, $to, $by, $logistics, $data, $assign, $send, $cancel) {
            $offer = $assign($offer, $evacuator, $to, $by);
            if ($logistics) {
                return $send($offer, $data, $by);
            }
            if ($open = Transport::openFor($offer)) {
                $cancel($open, $by);
            }

            return null;
        });
        // Имя не склоняем («к Сергей К.» резало глаз): куда — стрелкой.
        $toast = $transport
            ? 'Логистика: '.($transport->executorName() ?? 'ждёт логиста')
            : 'Вывоз: '.$evacuator->shortName().' → '.($to === Destination::Keeper && ($keeper = $offer->keeper()) ? $keeper->shortName() : mb_strtolower($to->label()));

        return back(fallback: "/offers/{$offer->number}")->with('toast', $toast);
    }

    /** «Вывоз не нужен»: маршрут вывоза снят, открытая перевозка в логистике отменена. */
    public function dropPickup(Request $request, Offer $offer, DropRoute $drop, CancelTransport $cancel)
    {
        self::notMoving($offer, 'exit');
        DB::transaction(function () use ($request, $offer, $drop, $cancel) {
            $drop($offer, Track::Service, $request->user());
            if ($open = Transport::openFor($offer)) {
                $cancel($open, $request->user());
            }
        });

        return redirect("/offers/{$offer->number}")->with('toast', 'Вывоз отменён');
    }

    /** Открытая перевозка уже везёт машину («Забрал» в логистике) — отменять её из CRM молча нельзя. */
    private static function notMoving(Offer $offer, string $field): void
    {
        if (Transport::openFor($offer)?->state === TransportState::Moving) {
            throw ValidationException::withMessages([$field => 'Машину уже везут: сначала «Сдал» или отмена в логистике']);
        }
    }
}
