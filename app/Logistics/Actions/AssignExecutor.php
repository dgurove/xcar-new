<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Choices;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
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
        // «Сам» из CRM (20.10.2026): кто — держатель гаража или менеджер сделки, человека форма не шлёт.
        if ($kind === Channel::Manager && ! $who) {
            $who = Choices::managerOf($t);
        }
        self::guard($kind, $who);
        if ($kind === Channel::Manager && ! ($who && $who->is(Choices::managerOf($t)))) {
            throw ValidationException::withMessages(['executor_id' => 'Вывозит только менеджер, которому подтвердили сделку']);
        }
        // Свой исполнитель — чужую машину; менеджер сделки или держатель гаража свою забирает сам («… сам»).
        if ($kind === Channel::Partner && $who && $who->is(Choices::managerOf($t))) {
            throw ValidationException::withMessages(['executor_id' => $who->shortName().' — это его машина: выберите «'.$who->shortName().' сам»']);
        }

        // Маршрут через стоянки (20.10.2026, `Logistics\Route`) ведёт логист: отдать его другому виду — сначала убрать стоянки.
        $ahead = Transport::where('route_id', $t->route_id)->where('seq', '>', $t->seq)->where('state', TransportState::Planned)->get();
        if ($ahead->isNotEmpty() && $kind !== Channel::Logist) {
            throw ValidationException::withMessages(['channel' => 'В маршруте есть стоянки: их ведёт логист. Сначала уберите стоянки']);
        }

        return DB::transaction(function () use ($t, $kind, $who, $by, $cdek, $batch, $ahead) {
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
            // Маршрут целиком у одного логиста: сменили его — отрезки впереди к новому, без прежних исполнителей и цен.
            if ($logist) {
                $ahead->filter(fn (Transport $leg) => $leg->logist_id !== $who->id)->each(function (Transport $leg) use ($who, $by) {
                    $leg->update(['logist_id' => $who->id, 'channel' => Channel::Logist, 'executor_id' => null, 'cost' => null, 'distance_km' => null, 'executor_cost' => null]);
                    ($this->settle)($leg, $by);
                });
            }
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
            Channel::Partner => $who && Choices::partnerable($who) ?: throw ValidationException::withMessages(['executor_id' => 'Выберите менеджера или исполнителя']),
            Channel::Manager => $who ?: throw ValidationException::withMessages(['executor_id' => 'Кто именно']),
            // Аукцион назначают не формой, а продажей: «Продана на аукционе» (`SellAtAuction`).
            Channel::Auction => throw ValidationException::withMessages(['channel' => 'Отметьте продажу на аукционе']),
        };
    }

    /**
     * Из формы «Кто вывозит» (`x-logistics.assign-form`): вид и человек — поле `who[вид]` выбранного вида (старое
     * `executor_id` — тоже), «Документы СДЭКом» — `cdek[вид]` (формы без галки — null: умолчание по виду). Вида нет — null:
     * задача просто заводится.
     *
     * `$self` — дорожка «Вывоз» в CRM: там можно вернуть «забирает сам» (20.10.2026, владелец про гараж покупателя: «он сам
     * может забирать, а можем мы для него, чаще всего сам»), человек — держатель гаража или менеджер сделки.
     *
     * @return array{0: Channel, 1: ?User, 2: ?bool}|null
     */
    public static function fromRequest(Request $request, bool $self = false): ?array
    {
        $kind = Channel::tryFrom((string) $request->input('channel'));
        // В логистике менеджера формой не назначают: «забирает сам» — не логистика (17.10.2026).
        if (! $kind || $kind === Channel::Auction || ($kind === Channel::Manager && ! $self)) {
            return null;
        }
        if ($kind === Channel::Manager) {
            return [$kind, null, false];
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
        // Шаг без истории и уведомлений — только экраны: «Документы» у Лизы, «Забрал» исполнителя ждёт или нет.
        TransportMoved::dispatch($t, $by, 'cdek');

        return $t;
    }
}
