<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Reach;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Куда везти (18.10.2026, начальник: «невозможно указать, куда везти, и внутри региона или между регионами»): точка
 * (`Point`; пусто — «пока неизвестно»), дальность (`Reach`) и комментарий тому, кто везёт. Ставит окно «Кто вывозит» вместе
 * с исполнителем и строка «Куда везти» в карточке — когда стало известно или планы поменялись. Точку правят все, кто ведёт
 * задачу, и исполнитель тоже (владелец: «другой адрес, если что-то не получилось»); дальность и комментарий — ведущие и
 * логист. Привезли — менять поздно. В историю — что стало.
 */
final class SetRoute
{
    /** Поля формы; `reach` обязателен там, где его спрашивают (`$reach`). */
    public static function rules(bool $reach = true): array
    {
        return [
            // Точка или строка парковки `yard:5` (`Point::read`).
            'destination' => ['nullable', 'string', 'max:20'],
            'to_yard_id' => ['nullable', 'integer', 'exists:park_yards,id'],
            'to_address' => ['nullable', 'string', 'max:500'],
            'to_city' => ['nullable', 'string', 'max:200'],
            'reach' => [$reach ? 'required' : 'nullable', Rule::enum(Reach::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return ['reach.required' => 'Внутри региона или между регионами'];
    }

    /** Может ли человек править дальность и комментарий (точку — любой, кто ведёт задачу). */
    public static function full(Transport $t, User $by): bool
    {
        return ($by->leadsLogistics() && ! $t->isOrder()) || $t->hasLogist($by);
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if (! $t->isHandledBy($by)) {
            throw ValidationException::withMessages(['destination' => 'Задача не у вас']);
        }
        if (! $t->isOpen() || $t->state === TransportState::Delivered) {
            throw ValidationException::withMessages(['destination' => 'ТС уже привезли']);
        }
        [$to, $yard] = Point::read($data);
        $address = Point::address($data);
        match (true) {
            $to === Point::Keeper && ! Transport::keeperLabel($t->saleOffer()) => throw ValidationException::withMessages(['destination' => 'У машины нет менеджера']),
            $to === Point::Yard && ! $yard => throw ValidationException::withMessages(['destination' => 'Какая парковка']),
            // «К нам» без адреса — не место (18.10.2026): только парковка по названию или адрес.
            $to === Point::Ours => throw ValidationException::withMessages(['destination' => 'Выберите парковку или адрес']),
            $to === Point::Other && ! $address => throw ValidationException::withMessages(['to_address' => 'Адрес']),
            default => null,
        };
        // Пачкой («Назначить (N)») точку не шлют — у машин она разная: не трогаем.
        $fields = array_key_exists('destination', $data) ? ['destination' => $to, 'to_yard_id' => $to === Point::Yard ? $yard : null,
            'to_address' => $to === Point::Other ? $address : null] : [];
        if (self::full($t, $by)) {
            if (array_key_exists('reach', $data)) {
                $fields['reach'] = Reach::tryFrom((string) $data['reach']) ?? $t->reach;
            }
            if (array_key_exists('comment', $data)) {
                $fields['comment'] = trim((string) $data['comment']) ?: null;
            }
        }
        $was = $t->routeLabel().'|'.$t->reach?->value;
        $t->fill($fields);
        if (! $t->isDirty()) {
            return $t;
        }
        $t->save();
        if ($t->routeLabel().'|'.$t->reach?->value !== $was) {
            TransportEvent::record($t, 'route', $by, ['to' => $t->routeLabel(), 'reach' => $t->reach?->label()]);
        }
        TransportMoved::dispatch($t, $by, 'route');

        return $t;
    }
}
