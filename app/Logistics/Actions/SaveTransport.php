<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Executor;
use App\Logistics\Scope;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Развилка перевозки (владелец 10.10.2026): внутри региона — партнёр региона (менеджер, со стоимостью эвакуации) или наш
 * водитель; между регионами — логист назначает нашего водителя или внешнего перевозчика, стоимость и сроки (админ из CRM
 * может оставить пусто — тогда перевозка ждёт логиста). Точки A, B и «кто принимает» — свободным текстом. Пока машину не
 * забрали, всё правится; дальше — только «Сдал» или отмена.
 */
final class SaveTransport
{
    public function __construct(private SettleCost $settle) {}

    public static function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(Scope::class)],
            'from_region_id' => ['required', 'integer', Rule::exists('regions', 'id')],
            'to_region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'executor' => ['nullable', Rule::enum(Executor::class)],
            'executor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'carrier' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'from_address' => ['nullable', 'string', 'max:500'],
            'to_address' => ['nullable', 'string', 'max:500'],
            'receiver' => ['nullable', 'string', 'max:200'],
            'to_yard_id' => ['nullable', 'integer', Rule::exists('park_yards', 'id')],
            'ship_on' => ['nullable', 'date'],
            'arrive_on' => ['nullable', 'date', 'after_or_equal:ship_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'from_region_id.required' => 'Выберите регион',
            'arrive_on.after_or_equal' => 'Прибытие раньше отправки',
        ];
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if ($t->exists && ! in_array($t->state, [TransportState::New, TransportState::Assigned], true)) {
            throw ValidationException::withMessages(['scope' => 'Машину уже забрали: можно только сдать или отменить']);
        }
        $scope = Scope::from($data['scope']);
        $from = (int) $data['from_region_id'];
        $to = $scope === Scope::Local ? $from : (int) ($data['to_region_id'] ?? 0);
        if ($scope === Scope::Intercity && (! $to || $to === $from)) {
            throw ValidationException::withMessages(['to_region_id' => $to ? 'Регионы совпадают: это внутри региона' : 'Куда: выберите регион']);
        }
        $executor = Executor::tryFrom((string) ($data['executor'] ?? ''));
        $user = $executor && $executor !== Executor::Carrier && ! empty($data['executor_id']) ? User::find($data['executor_id']) : null;
        $carrier = $executor === Executor::Carrier ? trim((string) ($data['carrier'] ?? '')) : null;
        $cost = isset($data['cost']) && $data['cost'] !== '' ? (float) $data['cost'] : null;

        match (true) {
            $scope === Scope::Local && ! $executor => throw ValidationException::withMessages(['executor' => 'Кто везёт: партнёр или наш эвакуатор']),
            $scope === Scope::Local && $executor === Executor::Carrier => throw ValidationException::withMessages(['executor' => 'Перевозчика назначает логист между регионами']),
            $scope === Scope::Intercity && $executor === Executor::Partner => throw ValidationException::withMessages(['executor' => 'Партнёр везёт только внутри своего региона']),
            $executor === Executor::Partner && ! $user?->partnersIn($from) => throw ValidationException::withMessages(['executor_id' => 'Выберите партнёра этого региона']),
            $executor === Executor::Partner && ! $cost => throw ValidationException::withMessages(['cost' => 'Стоимость эвакуации партнёра']),
            $executor === Executor::Driver && ! $user?->isDriver() => throw ValidationException::withMessages(['executor_id' => 'Выберите водителя']),
            $executor === Executor::Carrier && $carrier === '' => throw ValidationException::withMessages(['carrier' => 'Кто везёт: название перевозчика']),
            default => null,
        };
        // Сроки и стоимость перевозчика — у межрегиона; наш водитель возит без суммы.
        if ($executor === Executor::Driver) {
            $cost = null;
        }

        return DB::transaction(function () use ($t, $data, $by, $scope, $from, $to, $executor, $user, $carrier, $cost) {
            $was = $t->exists ? $t->replicate() : null;
            $previous = $t->executorUser;
            $assigned = $executor !== null;
            $t->fill([
                'scope' => $scope, 'from_region_id' => $from, 'to_region_id' => $to,
                'executor' => $executor, 'executor_id' => $user?->id, 'carrier' => $carrier, 'cost' => $cost,
                'from_address' => self::text($data['from_address'] ?? null), 'to_address' => self::text($data['to_address'] ?? null),
                'receiver' => self::text($data['receiver'] ?? null), 'to_yard_id' => $data['to_yard_id'] ?? null,
                'ship_on' => $scope === Scope::Intercity ? ($data['ship_on'] ?? null) : null,
                'arrive_on' => $scope === Scope::Intercity ? ($data['arrive_on'] ?? null) : null,
                'note' => self::text($data['note'] ?? null),
                'state' => $assigned ? TransportState::Assigned : TransportState::New,
                'assigned_at' => $assigned ? ($t->assigned_at && $previous?->is($user) ? $t->assigned_at : now()) : null,
            ]);
            $t->created_by ??= $by->id;
            $t->save();
            ($this->settle)($t, $by);

            $t->load(['executorUser', 'fromRegion', 'toRegion', 'offer', 'vehicle']);
            if ($user && ! $user->is($previous)) {
                TransportAssigned::dispatch($t, $previous);
            }
            if (! $assigned && ($was === null || $was->state !== TransportState::New)) {
                TransportRequested::dispatch($t);
            }

            return $t;
        });
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
