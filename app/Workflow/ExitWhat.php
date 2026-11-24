<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\Signing;
use Illuminate\Support\Carbon;

/**
 * Что решили выходом из этапа — строками, на момент выхода (24.11.2026, владелец: «написано, что Андрей решил или менеджер
 * решил, а ЧТО РЕШИЛ — никак не посмотреть»; «Время согласовано — Виталий Зайцев», а какое время, не написано). Значение
 * живёт в сделке и позиции и затирается следующим шагом, поэтому кладётся в событие `StageEntered` (`what`), а журнал пути
 * (`Path::journal`) и «История» читают его оттуда:
 * - время подписания («Время согласовано», «Запись подтверждена») — `deals.signing_at`, иначе названное менеджером
 *   (`signing_wish`), с адресом филиала у записи;
 * - «Поставщик предложил другое время» — его слоты и адрес;
 * - поля сотрудника следующего этапа («Приём назначен», «Покупатель», «Владелец») и платёжка нашей оплаты (`out`).
 */
final class ExitWhat
{
    /**
     * @param  array<string, mixed>  $payload  поля выхода (`RouteController::exit`), без служебных ключей
     * @param  array<string, string>  $out  платёжка нашей оплаты: подпись → значение
     * @return list<string>
     */
    public static function of(?Deal $deal, ?Outcome $exit, array $payload = [], array $out = []): array
    {
        if (! $exit) {
            return [];
        }
        $lines = [];
        if ($deal && (Signing::pickExit($exit) || Signing::confirmExit($exit) || Signing::slotsExit($exit))) {
            $lines = self::signing($deal, $exit);
        }
        foreach ($exit->staffFields() as $field) {
            $value = ($field['out'] ?? false) ? ($out[$field['label']] ?? null) : ($payload[$field['key'] ?? ''] ?? null);
            if (is_string($value) && trim($value) !== '') {
                $lines[] = $field['label'].': '.trim($value);
            }
        }

        return $lines;
    }

    /**
     * Время подписания сделки сейчас — для выхода, что его решил, и для старых записей журнала, где значения нет
     * (`Path::fill`): пока сделка на «Записи», колонки ещё те самые. @return list<string>
     */
    public static function signing(Deal $deal, Outcome $exit): array
    {
        $address = $deal->signing_address ?? Signing::office($deal)['address'];
        if (Signing::slotsExit($exit)) {
            $slots = collect($deal->signing_slots ?? [])->map(fn ($s) => Carbon::parse($s, 'Europe/Moscow'))->sort()->values();

            return $slots->isEmpty() ? [] : array_values(array_filter(['Предложил: '.$slots->map(fn (Carbon $c) => Signing::short($c))->implode(', '), $address]));
        }
        if ($deal->signing_at) {
            return array_values(array_filter([Signing::short($deal->signing_at), Signing::confirmExit($exit) ? $address : null]));
        }
        // Названо заранее: «чт 12.11 в 12:00 или 14:00» — и прошедшие, запись о прошлом.
        $line = Signing::wishLine($deal, past: true);

        return $line ? ['Может подписать: '.$line] : [];
    }
}
