<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Depot;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Point;
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
            // Куда везёт исполнитель (18.10.2026): туда, куда указано, или сначала на стоянку логиста.
            'leg' => ['nullable', 'in:route,depot'],
            'depot_id' => ['nullable', 'integer'],
        ];
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if ($t->channel !== Channel::Logist || ! ($t->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['executor_id' => 'Задача не у вас']);
        }
        if (! $t->isOpen() || $t->state->picked()) {
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

        return DB::transaction(function () use ($t, $who, $cost, $data, $by) {
            $previous = $t->executor;
            $changed = $t->executor_id !== $who->id;
            $was = (int) $t->cost;
            $t->fill([
                'executor_id' => $who->id, 'cost' => $cost, 'distance_km' => $data['distance_km'] ?? null ?: null,
                ...($t->hasLogist($by) && array_key_exists('executor_cost', $data) ? ['executor_cost' => Money::rubles($data['executor_cost'])] : []),
                'state' => $t->agreed_at ? TransportState::Agreed : TransportState::Assigned,
                'assigned_at' => $changed ? now() : $t->assigned_at,
            ]);
            if ($changed) {
                $t->fill(['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
            }
            if (array_key_exists('leg', $data) && ($t->isPickup() || $t->from_depot_id)) {
                $this->leg($t, $data, $by);
            }
            $t->save();
            ($this->settle)($t, $by);
            if ($changed) {
                TransportEvent::record($t, 'assigned', $by, ['executor' => $who->id, 'was' => $previous?->id]);
            }
            if ($was !== $cost) {
                TransportEvent::record($t, 'cost', $by, ['cost' => $cost, 'was' => $was ?: null]);
            }
            if ($changed && ! $who->is($by)) {
                TransportAssigned::dispatch($t->load('executor'), $previous);
            }

            return $t;
        });
    }

    /**
     * Сначала на стоянку логиста или туда, куда указано: на стоянку — точка задачи становится стоянкой, а указанная
     * уходит в «куда дальше» (`next_*`) и достанется следующему отрезку; обратно — точка возвращается. Стоянка — только
     * своя у логиста.
     */
    private function leg(Transport $t, array $data, User $by): void
    {
        if (($data['leg'] ?? 'route') !== 'depot') {
            if ($t->destination === Point::Depot) {
                $t->fill(['destination' => $t->next_destination, 'to_yard_id' => $t->next_to_yard_id, 'to_address' => $t->next_to_address,
                    'depot_id' => null, 'next_destination' => null, 'next_to_yard_id' => null, 'next_to_address' => null, 'to_contact' => null, 'to_phone' => null]);
                TransportEvent::record($t, 'route', $by, ['to' => $t->routeLabel(), 'reach' => $t->reach?->label()]);
            }

            return;
        }
        $depot = Depot::of($t->logist)->whereNull('archived_at')->find((int) ($data['depot_id'] ?? 0));
        if (! $depot) {
            throw ValidationException::withMessages(['depot_id' => 'Какая стоянка']);
        }
        if ($t->destination !== Point::Depot) {
            $t->fill(['next_destination' => $t->destination, 'next_to_yard_id' => $t->to_yard_id, 'next_to_address' => $t->to_address]);
        }
        if ($t->depot_id !== $depot->id || $t->destination !== Point::Depot) {
            // Кто принимает — тот, кто на стоянке: исполнитель видит его и телефон (`/trip`, «Отправить»).
            $t->fill(['destination' => Point::Depot, 'depot_id' => $depot->id, 'to_yard_id' => null, 'to_address' => null,
                'to_contact' => $depot->contact_name, 'to_phone' => $depot->phone])->setRelation('depot', $depot);
            TransportEvent::record($t, 'route', $by, ['to' => $depot->label(), 'reach' => $t->reach?->label()]);
        }
    }
}
