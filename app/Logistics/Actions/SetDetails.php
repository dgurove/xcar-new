<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Владелец и комментарий в карточке вывоза (22.10.2026, владелец: «логисту будет полезно менять, как зовут владельца,
 * номер, указывать второй номер, редактировать комментарий»). Правят ведущие и логист задачи (`SetRoute::full`).
 * Владелец вывоза — страхователь машины (`cars.insured_*`: его же видят CRM и парковка — одна машина); у заявки логиста
 * и у задачи со своим контактом (`from_contact|from_phone`) — контакт самой задачи, второго номера там нет.
 * В историю — что поменяли.
 */
final class SetDetails
{
    public static function rules(): array
    {
        return [
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'owner_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'owner_phone2' => ['sometimes', 'nullable', 'string', 'max:40'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** Свой контакт у задачи — заявка логиста или контакт, которого у машины нет (машина стоит у менеджера). */
    public static function ownContact(Transport $t): bool
    {
        return $t->isOrder() || $t->from_contact !== null || $t->from_phone !== null || ! $t->car;
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if (! SetRoute::full($t, $by)) {
            throw ValidationException::withMessages(['transport' => 'Правят логист, админ и модераторы']);
        }
        $phone = fn (string $key) => array_key_exists($key, $data) ? (Phone::normalize((string) $data[$key]) ?: null) : false;
        foreach (['owner_phone', 'owner_phone2'] as $key) {
            if (filled($data[$key] ?? null) && ! $phone($key)) {
                throw ValidationException::withMessages([$key => 'Номер телефона']);
            }
        }
        $text = fn (string $key) => array_key_exists($key, $data) ? (trim((string) $data[$key]) ?: null) : false;
        $changed = [];
        DB::transaction(function () use ($t, $data, $phone, $text, &$changed) {
            if (array_key_exists('comment', $data) && $text('comment') !== $t->comment) {
                $t->comment = $text('comment');
                $changed[] = 'комментарий';
            }
            $own = self::ownContact($t);
            $fields = array_filter([
                $own ? 'from_contact' : 'insured_name' => $text('owner_name'),
                $own ? 'from_phone' : 'insured_phone' => $phone('owner_phone'),
                ...($own ? [] : ['insured_phone2' => $phone('owner_phone2')]),
            ], fn ($v) => $v !== false);
            $target = $own ? $t : $t->car;
            foreach ($fields as $field => $value) {
                if ($target->{$field} !== $value) {
                    $target->{$field} = $value;
                    $changed[] = str_contains($field, 'phone2') ? 'второй телефон' : (str_contains($field, 'phone') ? 'телефон' : 'имя владельца');
                }
            }
            $t->save();
            if (! $own) {
                $t->car->save();
            }
        });
        if ($changed) {
            TransportEvent::record($t, 'details', $by, ['what' => implode(', ', array_unique($changed))]);
            TransportMoved::dispatch($t, $by, 'details');
        }

        return $t;
    }
}
