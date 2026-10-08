<?php

namespace App\Notifications;

use App\Live\Publisher;
use App\Live\Topics;
use App\Support\Surface;
use Illuminate\Support\Facades\DB;

/**
 * Прочитано тем, что человек открыл объект: строки ленты о нём гаснут, чем бы он ни пришёл — из таб-бара, пуша,
 * Telegram или письма, а не только нажатием в колокольчике. Запись — через `pgsql_async`: частая и без ценности при
 * аварии, фиксация на HDD прода стоила бы странице 160–290 мс. Погасло — значкам у этих людей перечитаться.
 */
final class ReadNotices
{
    public function __construct(private Publisher $publish) {}

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $subjects  путь объекта (`data.subject`) или адрес строки (`data.href`)
     * @param  ?Surface  $surface  открыли в этом приложении — гаснут только его строки (`User::noticesHere`): `/offers/5`
     *                             на сайте не гасит «новое подтверждение» CRM
     */
    public function __invoke(array $userIds, array $subjects, ?Surface $surface = null): int
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (! $userIds || ! $subjects) {
            return 0;
        }
        $read = DB::connection('pgsql_async')->table('notifications')
            ->whereIn('notifiable_id', $userIds)->whereNull('read_at')
            ->where(fn ($q) => $q->whereIn('data->subject', $subjects)->orWhereIn('data->href', $subjects))
            ->when($surface, fn ($q) => match ($surface) {
                Surface::Park => $q->where('data->surface', 'park'),
                Surface::Crm => $q->where(fn ($w) => $w->whereNull('data->surface')->orWhere('data->surface', 'crm')),
                Surface::Site => $q->whereNull('data->surface'),
            })
            ->update(['read_at' => now()]);
        if ($read > 0) {
            $topics = array_map(Topics::user(...), $userIds);
            $this->publish->badges($topics);
            $this->publish->noticesRead($topics, $subjects);
        }

        return $read;
    }
}
