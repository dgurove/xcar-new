<?php

namespace App\Logistics\Actions;

use App\Logistics\Choices;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Executor;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Кто вывозит (17.10.2026, владелец: «кто именно вывозит по умолчанию не определено — админ должен это нажать»):
 * — логист (регионы): задача уходит ему, исполнителя, расстояние и цену он ставит сам (`LogistAssign`);
 * — менеджер, которому мы подтвердили сделку (в его гараж или его покупателю) — только он, другого менеджера не дать;
 * — наши люди ООО «ПРАЙМ» (`Role::Driver`).
 * Документы СДЭКом — тем, у кого нет печати (`User::seals`), админ может переключить. Поменять можно, пока машину не
 * забрали; созвон остаётся (дата с владельцем та же), документы прежнему исполнителю — нет.
 */
final class AssignExecutor
{
    public function __construct(private SettleCost $settle) {}

    public function __invoke(Transport $t, Executor $kind, ?User $who, User $by, ?bool $cdek = null): Transport
    {
        if (! $by->isAdmin()) {
            throw ValidationException::withMessages(['executor' => 'Исполнителя выбирает админ']);
        }
        if (! $t->isOpen() || $t->state->picked()) {
            throw ValidationException::withMessages(['executor' => 'ТС уже забрали: можно только «Привёз» или отмена']);
        }
        // Закрытый доступ — не исполнитель.
        if ($who?->rejected_at) {
            throw ValidationException::withMessages(['executor_id' => 'У '.$who->shortName().' закрыт доступ']);
        }
        match ($kind) {
            Executor::Logist => $who?->isLogist() ?: throw ValidationException::withMessages(['executor_id' => 'Выберите логиста']),
            Executor::Staff => $who?->isDriver() ?: throw ValidationException::withMessages(['executor_id' => 'Выберите сотрудника на вывозе']),
            Executor::Manager => $who && $who->is(Choices::managerOf($t)) ?: throw ValidationException::withMessages(['executor_id' => 'Вывозит только менеджер, которому подтвердили сделку']),
        };

        return DB::transaction(function () use ($t, $kind, $who, $by, $cdek) {
            $previous = $t->executorUser ?? $t->coordinator;
            $logist = $kind === Executor::Logist;
            $executor = $logist ? null : $who;
            $changed = $t->executor_id !== $executor?->id;
            $t->fill([
                'executor' => $kind,
                'executor_id' => $executor?->id,
                'coordinator_id' => $logist ? $who->id : null,
                'state' => $logist ? TransportState::Logist : ($t->agreed_at ? TransportState::Agreed : TransportState::Assigned),
                'assigned_at' => $changed || $t->coordinator_id !== ($logist ? $who->id : null) ? now() : $t->assigned_at,
                // Цену и расстояние ставит логист; ушли от него — долга нет.
                'cost' => $logist && $t->coordinator_id === $who->id ? $t->cost : null,
                'distance_km' => $logist && $t->coordinator_id === $who->id ? $t->distance_km : null,
            ]);
            if ($changed) {
                $t->fill(['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
            }
            // Документы нужны только на вывоз от владельца: перевод между парковками едет без них.
            $t->docs_by_cdek = $executor && $t->isPickup() ? ($cdek ?? ! $executor->seals()) : false;
            // Менеджер сделки везёт к себе: точка — его гараж.
            if ($kind === Executor::Manager && $t->destination === null) {
                $t->destination = Point::Keeper;
            }
            $t->created_by ??= $by->id;
            $t->save();
            ($this->settle)($t, $by);
            $t->load(['executorUser', 'coordinator']);
            if (($who && ! $who->is($previous)) || $changed) {
                TransportAssigned::dispatch($t, $previous);
            }

            return $t;
        });
    }

    /**
     * Из формы «Кто вывозит» (`x-logistics.assign-form`): вид и человек — поле `who[вид]` выбранного вида (старое
     * `executor_id` — тоже). Вида нет — null: задача просто заводится.
     *
     * @return array{0: Executor, 1: ?User}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $kind = Executor::tryFrom((string) $request->input('executor'));
        if (! $kind) {
            return null;
        }
        $id = $request->input('who.'.$kind->value) ?? $request->input('executor_id');
        if (! $id || ! ($who = User::find((int) $id))) {
            throw ValidationException::withMessages(['executor_id' => 'Кто именно']);
        }

        return [$kind, $who];
    }

    /** Снять исполнителя: вывоз снова ждёт админа (менеджеру отдали сделку другого, отменили сделку). */
    public function release(Transport $t, ?User $by): Transport
    {
        if (! $t->isOpen() || $t->state->picked() || $t->state === TransportState::New) {
            return $t;
        }
        $t->update(['executor' => null, 'executor_id' => null, 'coordinator_id' => null, 'cost' => null, 'distance_km' => null,
            'state' => TransportState::New, 'assigned_at' => null, 'docs_by_cdek' => false,
            'docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
        // Долг логисту (если был) — аннулировать: исполнителя больше нет.
        if ($t->invoice_id && $by) {
            ($this->settle)($t, $by);
        }
        TransportAssigned::dispatch($t, null);

        return $t;
    }

    /** Админ переключает «Документы СДЭКом» у назначенного исполнителя. */
    public function cdek(Transport $t, bool $cdek, User $by): Transport
    {
        if (! $by->isAdmin() || ! $t->executor_id || ! $t->isPickup() || $t->state->picked()) {
            throw ValidationException::withMessages(['docs_by_cdek' => 'Поздно менять']);
        }
        $t->update(['docs_by_cdek' => $cdek] + ($cdek ? [] : ['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]));

        return $t;
    }
}
