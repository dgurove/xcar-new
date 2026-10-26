<?php

namespace App\Cars;

use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * «Отклонить» значение источника — одно хранилище (`field_rejections`, 26.10.2026, приборка: было три — колонка
 * `offers.migtorg_rejected`, бессрочный кэш читалки `scan:rejected:*` и кэш расхождений парковки `park:docdiff:rejected:*`).
 * Предмет — машина (`car:{id}`: продажа и хранение — одна машина и одни документы, отклонённое в CRM не всплывёт на
 * парковке), у цепочки «Из писем» без машины — `candidate:{id}`. Источник: `docs` — документы и письма («✨», читалка,
 * расхождения дела ТС), `migtorg` — лот Мигторга. Отклонено значение, а не поле: другое значение того же поля придёт снова.
 */
final class Rejections
{
    public static function add(string $subject, string $source, string $field, string $value, ?User $by = null): void
    {
        DB::table('field_rejections')->insertOrIgnore(['subject' => $subject, 'source' => $source, 'field' => $field, 'value' => $value,
            'user_id' => $by?->id, 'created_at' => now()]);
    }

    /** Взяли значение — отказ по полю снимается. */
    public static function forget(string $subject, string $source, string $field): void
    {
        DB::table('field_rejections')->where(compact('subject', 'source', 'field'))->delete();
    }

    /** @return array<string, list<string>> поле → отклонённые значения */
    public static function of(string $subject, string $source): array
    {
        return DB::table('field_rejections')->where(compact('subject', 'source'))->get(['field', 'value'])
            ->groupBy('field')->map(fn ($rows) => $rows->pluck('value')->all())->all();
    }

    public static function has(string $subject, string $source, string $field, string $value): bool
    {
        return in_array($value, self::of($subject, $source)[$field] ?? [], true);
    }

    public static function car(?int $carId): string
    {
        return 'car:'.$carId;
    }
}
