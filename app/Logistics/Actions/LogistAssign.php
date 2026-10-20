<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Логист назначает вывоз в регионе (17.10.2026, владелец: «задача падает Татьяне, она сама вводит расстояние, стоимость
 * и назначает исполнителя»): исполнитель — её человек (`Contractor` с её `logist_id`), сама она не везёт; цена — наш долг ей
 * («Оплаты → Мы должны», `SettleCost`), со своими исполнителями она рассчитывается сама. Документы СДЭКом — как решил админ,
 * назначая её (`AssignExecutor`, галка), — уходят исполнителю, которого она поставит. Поменять можно, пока машину не забрали.
 *
 * С 20.10.2026 это окно отрезка маршрута (`Logistics\Route`): у каждого отрезка свой исполнитель, цена, межрегион и
 * комментарий. Отрезок, до которого не дошла очередь (`Planned`), назначается заранее и остаётся «Впереди»: исполнитель
 * получит поручение, когда машину привезут на стоянку перед ним. Стоянки — не здесь: «+ Стоянка» в маршруте (`AddStop`).
 */
final class LogistAssign
{
    public function __construct(private SettleCost $settle) {}

    public static function rules(): array
    {
        return [
            'executor_id' => ['required', 'integer'],
            'distance_km' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'cost' => ['required', 'string', 'max:20'],
            // Сколько она платит своему исполнителю — для её «Оплат» (18.10.2026); XCar это не видит и не платит.
            'executor_cost' => ['nullable', 'string', 'max:20'],
            // Межрегион и комментарий — у отрезка (20.10.2026): «внутри региона» до стоянки, «между регионами» дальше.
            'reach' => ['nullable', 'in:local,intercity'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if ($t->channel !== Channel::Logist || ! ($t->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['executor_id' => 'Задача не у вас']);
        }
        $planned = $t->state === TransportState::Planned;
        if (! ($t->isOpen() || $planned) || $t->state->picked()) {
            throw ValidationException::withMessages(['executor_id' => 'ТС уже забрали']);
        }
        $logist = $t->logist;
        $who = User::find($data['executor_id']);
        // Логист распределяет, а не везёт: исполнитель — его человек (18.10.2026, владелец).
        if (! $who || $who->rejected_at || ! ($who->isContractor() && $who->logist_id === $logist?->id)) {
            throw ValidationException::withMessages(['executor_id' => 'Выберите своего исполнителя']);
        }
        $cost = Money::rubles($data['cost'] ?? null);
        if (! $cost) {
            throw ValidationException::withMessages(['cost' => 'Стоимость вывоза']);
        }

        return DB::transaction(function () use ($t, $who, $cost, $data, $by, $planned) {
            $previous = $t->executor;
            $changed = $t->executor_id !== $who->id;
            $was = (int) $t->cost;
            $t->fill([
                'executor_id' => $who->id, 'cost' => $cost, 'distance_km' => $data['distance_km'] ?? null ?: null,
                ...($t->hasLogist($by) && array_key_exists('executor_cost', $data) ? ['executor_cost' => Money::rubles($data['executor_cost'])] : []),
                'state' => $planned ? TransportState::Planned : ($t->agreed_at ? TransportState::Agreed : TransportState::Assigned),
                'assigned_at' => $planned ? null : ($changed ? now() : $t->assigned_at),
            ]);
            if (array_key_exists('reach', $data)) {
                $t->reach = $data['reach'] ?: null;
            }
            if (array_key_exists('comment', $data)) {
                $t->comment = trim((string) $data['comment']) ?: null;
            }
            if ($changed) {
                $t->fill(['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
            }
            $t->save();
            ($this->settle)($t, $by);
            if ($changed) {
                TransportEvent::record($t, 'assigned', $by, ['executor' => $who->id, 'was' => $previous?->id]);
            }
            if ($was !== $cost) {
                TransportEvent::record($t, 'cost', $by, ['cost' => $cost, 'was' => $was ?: null]);
            }
            // Отрезок впереди — поручение уйдёт, когда до него дойдёт очередь (`Route::sync`).
            if ($changed && ! $who->is($by) && ! $planned) {
                TransportAssigned::dispatch($t->load('executor'), $previous);
            }

            return $t;
        });
    }
}
