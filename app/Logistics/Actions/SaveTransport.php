<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Executor;
use App\Logistics\Scope;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Carbon;
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
            'arrive_on' => ['nullable', 'date'],
        ];
    }

    public static function messages(): array
    {
        return [
            'from_region_id.required' => 'Выберите регион',
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
        // Прибытие раньше отправки — только когда известны обе даты: одно прибытие — тоже срок.
        if ($scope === Scope::Intercity && ! empty($data['ship_on']) && ! empty($data['arrive_on']) && Carbon::parse($data['arrive_on'])->lt(Carbon::parse($data['ship_on']))) {
            throw ValidationException::withMessages(['arrive_on' => 'Прибытие раньше отправки']);
        }

        return DB::transaction(function () use ($t, $data, $by, $scope, $from, $to, $executor, $user, $carrier, $cost) {
            $was = $t->exists ? $t->replicate() : null;
            $previous = $t->executorUser;
            $assigned = $executor !== null;
            $t->fill([
                'scope' => $scope, 'from_region_id' => $from, 'to_region_id' => $to,
                'executor' => $executor, 'executor_id' => $user?->id, 'carrier' => $carrier, 'cost' => $cost,
                'state' => $assigned ? TransportState::Assigned : TransportState::New,
                'assigned_at' => $assigned ? ($t->assigned_at && $previous?->is($user) ? $t->assigned_at : now()) : null,
            ]);
            // Точки, кто принимает и парковка — только то, что в форме было: у логиста нет выбора парковки, и его
            // «Сохранить» не должно стирать парковку, которую выбрала парковка (иначе ТС не переведётся по «Сдал»).
            foreach (['from_address', 'to_address', 'receiver'] as $key) {
                if (array_key_exists($key, $data)) {
                    $t->{$key} = self::text($data[$key]);
                }
            }
            if (array_key_exists('to_yard_id', $data)) {
                $t->to_yard_id = $data['to_yard_id'] ?: null;
            }
            // Сроки — у межрегиона; внутри региона их нет.
            foreach (['ship_on', 'arrive_on'] as $key) {
                if ($scope === Scope::Local || array_key_exists($key, $data)) {
                    $t->{$key} = $scope === Scope::Intercity ? ($data[$key] ?: null) : null;
                }
            }
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
