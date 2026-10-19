<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Choices;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
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
 * Документы СДЭКом — выбор админа галкой «Документы СДЭКом» (17.10.2026, владелец: «нужно выбирать, а не всем»; шлём,
 * когда машину в регионе вывозит логист — Совкомбанк, иногда Альфа; покупатель менеджера забирает у страхователя сам): по
 * умолчанию — только логисту, менеджеру и нашим — нет; переключить можно и в задаче. Поменять исполнителя можно, пока
 * машину не забрали; созвон остаётся (дата с владельцем та же), документы прежнему исполнителю — нет.
 */
final class AssignExecutor
{
    public function __construct(private SettleCost $settle) {}

    public function __invoke(Transport $t, Channel $kind, ?User $who, User $by, ?bool $cdek = null, bool $batch = false): Transport
    {
        if (! $by->leadsLogistics()) {
            throw ValidationException::withMessages(['channel' => 'Исполнителя выбирают админ и модераторы']);
        }
        if (! $t->isOpen() || $t->state->picked()) {
            throw ValidationException::withMessages(['channel' => 'ТС уже забрали: можно только «Привёз» или отмена']);
        }
        self::guard($kind, $who);
        if ($kind === Channel::Manager && ! ($who && $who->is(Choices::managerOf($t)))) {
            throw ValidationException::withMessages(['executor_id' => 'Вывозит только менеджер, которому подтвердили сделку']);
        }
        // Другой менеджер — исполнитель логистики; менеджер этой сделки забирает сам («Принять»), не через логистику.
        if ($kind === Channel::Partner && $who && $who->is(Choices::managerOf($t))) {
            throw ValidationException::withMessages(['executor_id' => $who->shortName().' — менеджер этой сделки: забирает сам, через «Принять»']);
        }

        return DB::transaction(function () use ($t, $kind, $who, $by, $cdek, $batch) {
            $previous = $t->executor ?? $t->logist;
            [$wasLogist, $wasExecutor] = [$t->logist_id, $t->executor_id];
            $logist = $kind === Channel::Logist;
            $executor = $logist ? null : $who;
            $changed = $t->executor_id !== $executor?->id;
            $t->fill([
                'channel' => $kind,
                'executor_id' => $executor?->id,
                'logist_id' => $logist ? $who->id : null,
                'state' => $logist ? TransportState::Logist : ($t->agreed_at ? TransportState::Agreed : TransportState::Assigned),
                'assigned_at' => $changed || $t->logist_id !== ($logist ? $who->id : null) ? now() : $t->assigned_at,
                // Цену и расстояние ставит логист; ушли от него — долга нет.
                'cost' => $logist && $t->logist_id === $who->id ? $t->cost : null,
                'distance_km' => $logist && $t->logist_id === $who->id ? $t->distance_km : null,
            ]);
            if ($changed) {
                $t->fill(['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
            }
            // Документы нужны только на вывоз от владельца: перевод между парковками едет без них. Логисту галка встаёт до
            // его исполнителя — Лиза увидит задачу, когда он назначит своего (`scopeAwaitingDocs`).
            $t->docs_by_cdek = $t->isPickup() && $kind !== Channel::Manager && ($cdek ?? $kind === Channel::Logist);
            // Менеджер сделки везёт к себе: точка — его гараж.
            if ($kind === Channel::Manager && $t->destination === null) {
                $t->destination = Point::Keeper;
            }
            $t->created_by ??= $by->id;
            $t->save();
            ($this->settle)($t, $by);
            $t->load(['executor', 'logist']);
            // История: кого поставили и кто был (`TransportEvent`).
            if ($t->logist_id && $t->logist_id !== $wasLogist) {
                TransportEvent::record($t, 'logist', $by, ['logist' => $t->logist_id, 'was' => $wasLogist ?? $wasExecutor]);
            } elseif ($t->executor_id && $t->executor_id !== $wasExecutor) {
                TransportEvent::record($t, 'assigned', $by, ['executor' => $t->executor_id, 'was' => $wasExecutor ?? $wasLogist]);
            }
            if (($who && ! $who->is($previous)) || $changed) {
                TransportAssigned::dispatch($t, $previous, $batch);
            }

            return $t;
        });
    }

    /** Человек годится в исполнители этого вида (без задачи; менеджера сделки проверяет сам вызов): пачка проверяет один раз. */
    public static function guard(Channel $kind, ?User $who): void
    {
        // Закрытый доступ — не исполнитель.
        if ($who?->rejected_at) {
            throw ValidationException::withMessages(['executor_id' => 'У '.$who->shortName().' закрыт доступ']);
        }
        match ($kind) {
            Channel::Logist => $who?->isLogist() ?: throw ValidationException::withMessages(['executor_id' => 'Выберите логиста']),
            Channel::Staff => $who?->isDriver() ?: throw ValidationException::withMessages(['executor_id' => 'Выберите сотрудника на вывозе']),
            Channel::Partner => $who?->isManager() ?: throw ValidationException::withMessages(['executor_id' => 'Выберите менеджера']),
            Channel::Manager => $who ?: throw ValidationException::withMessages(['executor_id' => 'Кто именно']),
        };
    }

    /**
     * Из формы «Кто вывозит» (`x-logistics.assign-form`): вид и человек — поле `who[вид]` выбранного вида (старое
     * `executor_id` — тоже), «Документы СДЭКом» — `cdek[вид]` (формы без галки — null: умолчание по виду). Вида нет — null:
     * задача просто заводится.
     *
     * @return array{0: Channel, 1: ?User, 2: ?bool}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $kind = Channel::tryFrom((string) $request->input('channel'));
        // Менеджера формой не назначают: «забирает сам» решает «Принять», и это уже не логистика (17.10.2026).
        if (! $kind || $kind === Channel::Manager) {
            return null;
        }
        $id = $request->input('who.'.$kind->value) ?? $request->input('executor_id');
        if (! $id || ! ($who = User::find((int) $id))) {
            throw ValidationException::withMessages(['executor_id' => 'Кто именно']);
        }

        return [$kind, $who, $request->has('cdek') ? $request->boolean('cdek.'.$kind->value) : null];
    }

    /** Снять исполнителя: вывоз снова ждёт админа (менеджеру отдали сделку другого, отменили сделку). */
    public function release(Transport $t, ?User $by): Transport
    {
        if (! $t->isOpen() || $t->state->picked() || $t->state === TransportState::New) {
            return $t;
        }
        $previous = $t->executor ?? $t->logist;
        $t->update(['channel' => null, 'executor_id' => null, 'logist_id' => null, 'cost' => null, 'distance_km' => null,
            'state' => TransportState::New, 'assigned_at' => null, 'docs_by_cdek' => false,
            'docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
        // Долг логисту (если был) — аннулировать: исполнителя больше нет.
        if ($t->invoice_id && $by) {
            ($this->settle)($t, $by);
        }
        TransportEvent::record($t, 'released', $by);
        TransportAssigned::dispatch($t, $previous);

        return $t;
    }

    /** Ведущий логистику переключает «Документы СДЭКом» у задачи (и у логиста до его исполнителя). */
    public function cdek(Transport $t, bool $cdek, User $by): Transport
    {
        if (! $by->leadsLogistics() || ! ($t->executor_id || $t->logist_id) || ! $t->isPickup() || $t->state->picked()) {
            throw ValidationException::withMessages(['docs_by_cdek' => 'Поздно менять']);
        }
        $t->update(['docs_by_cdek' => $cdek] + ($cdek ? [] : ['docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]));

        return $t;
    }
}
