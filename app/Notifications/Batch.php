<?php

namespace App\Notifications;

use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Массовое сотрудникам — одной пачкой (27.10.2026, владелец: «по 100 предложениям одновременно заканчивается срок приёма —
 * 100 уведомлений, это ебаный бред», админы отключали уведомления). Уведомление с `batchKey()` не уходит сразу: строка на
 * человека и вид копится (`notice_batch_items`), часы `offers:tick` отправляют, когда `QUIET` минуты не было новых или
 * с первого прошло `WAIT`. Одно — уходит как было; несколько — сводное `BatchNotice` («Срок вышел: 7 шагов»), одной
 * строкой ленты, одним пушем, одним Telegram. Менеджеров не касается: им своя сделка — своим уведомлением.
 */
final class Batch
{
    public const QUIET = 2;

    public const WAIT = 10;

    /** Вид пачки → заголовок сводного (число вставляется) и куда ведёт. */
    public const KINDS = [
        'stage-overdue' => ['Срок вышел: %s', ['шаг', 'шага', 'шагов'], 'crm', '/work/deals'],
        'stage-due' => ['Срок подходит: %s', ['шаг', 'шага', 'шагов'], 'crm', '/work/deals'],
        'transport-moved' => ['Вывоз: %s', ['событие', 'события', 'событий'], 'logistics', '/transports'],
        'transport-requested' => ['Новые вывозы: %s', ['задача', 'задачи', 'задач'], 'logistics', '/transports'],
        'park-idle' => ['Стоят дольше 30 дней: %s', ['ТС', 'ТС', 'ТС'], 'park', '/cars?sort=-days'],
        'park-handled' => ['Приём и выдача: %s', ['ТС', 'ТС', 'ТС'], 'park', '/cars'],
    ];

    /** Отправить сотрудникам: пачкой, если уведомление массовое, иначе сразу. */
    public static function send(iterable $users, Notice $notice): void
    {
        $users = collect($users)->filter();
        if ($users->isEmpty()) {
            return;
        }
        if (! ($key = $notice->batchKey())) {
            Notification::send($users, $notice);

            return;
        }
        // base64: в сериализованном объекте нулевые байты (private-поля), текстовое поле Postgres их режет.
        $payload = base64_encode(serialize($notice));
        DB::table('notice_batch_items')->insert($users->map(fn (User $u) => [
            'user_id' => $u->id, 'key' => $key, 'line' => mb_substr($notice->batchLine(), 0, 200), 'notice' => $payload, 'created_at' => now(),
        ])->values()->all());
    }

    /** Часы: созревшие пачки — уведомлением. @return int сколько отправлено */
    public static function flush(): int
    {
        $ready = DB::table('notice_batch_items')->select('user_id', 'key')->groupBy('user_id', 'key')
            ->havingRaw('max(created_at) <= ? or min(created_at) <= ?', [now()->subMinutes(self::QUIET), now()->subMinutes(self::WAIT)])->get();
        $sent = 0;
        foreach ($ready as $group) {
            $items = DB::table('notice_batch_items')->where('user_id', $group->user_id)->where('key', $group->key)->orderBy('id')->get();
            DB::table('notice_batch_items')->whereIn('id', $items->pluck('id'))->delete();
            $user = User::find($group->user_id);
            try {
                $first = unserialize(base64_decode($items->first()->notice));
            } catch (Throwable) {
                continue;
            }
            if (! $user || ! $first instanceof Notice) {
                continue;
            }
            $user->notify($items->count() === 1 ? $first : new BatchNotice($group->key, $items->pluck('line')->all(), $first));
            $sent++;
        }

        return $sent;
    }

    /** Куда ведёт сводное: адрес приложения вида. */
    public static function href(string $key): string
    {
        [, , $app, $path] = self::KINDS[$key];

        return match ($app) {
            'logistics' => Surface::Logistics->url($path),
            'park' => Surface::Park->url($path),
            default => Surface::Crm->url($path),
        };
    }
}
