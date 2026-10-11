<?php

namespace App\Park;

use App\Mail\Extraction\Intent;
use App\Support\Facets\Facet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Висящие задачи по ТС (12.10.2026, владелец: «кому не отправили акты приёма, кто запросил осмотр — фокуса на этом нет,
 * а это самое главное»). Одна дверь: чип «Задачи» в «Наличии», слово в столбце «Статус» (`Status`), сортировка «Задачи».
 * То же, что текущие шаги дела (`Timeline`), только запросом по всему списку, а не по ленте событий одной машины.
 * Порядок ключей — важность: первая задача и есть слово строки.
 */
final class Tasks
{
    public const LABELS = [
        'release' => 'Выдать',
        'buyer' => 'Подтвердить покупателя',
        'inspect' => 'Запросили осмотр',
        'docs' => 'Запросили документы',
        'reply' => 'Ответить на письмо',
        'report' => 'Отправить акт приёма',
        'yard' => 'Указать парковку',
        'report_release' => 'Отправить акт выдачи',
    ];

    /** Условие задачи на строку `park_vehicles`. */
    public static function sql(string $key): string
    {
        $billable = "exists (select 1 from vendors vd where vd.id = park_vehicles.vendor_id and vd.kind <> 'own')";
        $sent = fn (string $what) => "not exists (select 1 from park_vehicle_events e where e.vehicle_id = park_vehicles.id and e.type = 'report_sent' and e.payload->>'what' = '{$what}')";
        // Ветка машины из ящика парковки ждёт нашего ответа — то же правило, что шаг «Нужно ответить» в деле.
        $waiting = fn (string $intent) => "exists (select 1 from mail_threads t join mail_accounts a on a.id = t.account_id where t.vehicle_id = park_vehicles.id and a.scope = 'park' and t.needs_reply_at is not null and {$intent})";
        $special = "'".Intent::Inspect->value."', '".Intent::Docs->value."'";
        $open = implode(', ', array_map(fn (RequestState $s) => "'{$s->value}'", RequestState::open()));

        return match ($key) {
            'release' => "park_vehicles.state = 'stored' and exists (select 1 from park_requests r where r.vehicle_id = park_vehicles.id and r.type = 'release' and r.state in ({$open}))",
            'buyer' => "park_vehicles.state = 'stored' and exists (select 1 from vendors vd where vd.id = park_vehicles.vendor_id and vd.release_by_qr)"
                ." and exists (select 1 from park_passes p where p.vehicle_id = park_vehicles.id and p.submitted_at is not null and p.confirmed_at is null and p.revoked_at is null and p.used_at is null)",
            'inspect' => "park_vehicles.state <> 'cancelled' and ".$waiting("t.last_intent = '".Intent::Inspect->value."'"),
            'docs' => "park_vehicles.state <> 'cancelled' and ".$waiting("t.last_intent = '".Intent::Docs->value."'"),
            'reply' => "park_vehicles.state <> 'cancelled' and ".$waiting("(t.last_intent is null or t.last_intent not in ({$special}))"),
            'report' => "park_vehicles.state = 'stored' and park_vehicles.accepted_at is not null and {$billable} and ".$sent('Акт приёма'),
            'yard' => "park_vehicles.state = 'stored' and park_vehicles.yard_id is null",
            'report_release' => "park_vehicles.state = 'released' and {$billable} and ".$sent('Акт выдачи'),
        };
    }

    /** Чип «Задачи ▾»: выбор нескольких — любая из них; числа — по списку при прочих чипах. */
    public static function facet(): Facet
    {
        return Facet::custom('task', 'Задачи', ['задача', 'задачи', 'задач'],
            fn (Builder $q, array $keys) => $q->where(fn ($w) => collect($keys)->filter(fn ($k) => isset(self::LABELS[$k]))
                ->each(fn ($k) => $w->orWhereRaw(self::sql($k)))),
            fn (Builder $q) => self::counts($q),
        )->natural()->labels(fn (array $keys) => collect(self::LABELS)->only($keys)
            ->map(fn ($label, $key) => new \App\Support\Facets\Option($key, $label))->all());
    }

    /** @return array<string, int> задача → сколько ТС в списке её ждут (нулевых нет) */
    public static function counts(Builder $q): array
    {
        $ids = Facet::bare($q->toBase())->select('park_vehicles.id');
        $row = (array) DB::table('park_vehicles')->whereIn('id', $ids)
            ->selectRaw(collect(self::LABELS)->keys()->map(fn ($k) => 'count(*) filter (where '.self::sql($k).') as "'.$k.'"')->implode(', '))
            ->first();

        return array_filter(array_map('intval', $row));
    }

    /**
     * Задачи каждой ТС страницы одним запросом — для слова в строке и сортировки.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>> id ТС → ключи задач по важности
     */
    public static function of(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = DB::table('park_vehicles')->whereIn('id', $ids)
            ->selectRaw('id, '.collect(self::LABELS)->keys()->map(fn ($k) => '('.self::sql($k).') as "'.$k.'"')->implode(', '))
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->id => collect(self::LABELS)->keys()->filter(fn ($k) => (bool) $r->{$k})->values()->all()])->all();
    }
}
