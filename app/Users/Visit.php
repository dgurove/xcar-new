<?php

namespace App\Users;

use App\Http\Middleware\MarkInstalled;
use App\Support\FastAttributes;
use App\Support\Surface;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Посещение (30.10.2026, владелец: «когда заходил, что делал и с какого устройства»): заход человека с одного устройства на
 * один хост. Продлевает его отметка «в сети» (`TouchSeen`, раз в минуту), пауза больше `GAP` минут или другое устройство —
 * новая строка; вход (`Login`) — новая строка с `login`. Под «Войти как» не пишется. Экран — «Активность» на странице
 * человека (`Users\Activity`).
 */
class Visit extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /** С какого дня журнал полный: раньше «не заходил» видно только по отметке «в сети». */
    public const SINCE = '2026-10-31';

    /** Пауза, после которой заход — новый, минут. */
    public const GAP = 30;

    protected $table = 'user_visits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_at' => 'datetime', 'login' => 'bool', 'app' => 'bool', 'surface' => Surface::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Отметить заход: продлить идущий (тот же хост и браузер, касание не дальше `GAP`) или начать новый. Соединение без
     * ожидания диска, как у `seen_at`: один запрос раз в минуту на человека.
     */
    public static function mark(User $user, Request $request, bool $login = false): void
    {
        $db = DB::connection('pgsql_async');
        $row = [
            'surface' => Surface::current()->value,
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'ip' => $request->ip(),
            'app' => MarkInstalled::installed($request),
        ];
        $now = now();
        if (! $login) {
            $hit = $db->update('update user_visits set last_at = ?, hits = hits + 1, ip = ?
                where id = (select id from user_visits where user_id = ? and surface = ? and user_agent is not distinct from ? and last_at >= ?
                    order by last_at desc limit 1)', [$now, $row['ip'], $user->id, $row['surface'], $row['user_agent'], $now->copy()->subMinutes(self::GAP)]);
            if ($hit) {
                return;
            }
        }
        // Журнал — не повод уронить страницу: не записалось — в лог, страница идёт дальше (в тестах, где `pgsql_async` не
        // видит транзакцию теста, человека для внешнего ключа там нет).
        try {
            $db->table('user_visits')->insert($row + ['user_id' => $user->id, 'started_at' => $now, 'last_at' => $now, 'login' => $login]);
        } catch (QueryException $e) {
            report($e);
        }
    }

    public static function prune(): void
    {
        self::where('last_at', '<', now()->subDays(180))->delete();
    }

    /**
     * Устройство словами: «Safari, iPhone», у установленного — «Приложение, iPhone» (по cookie, а у старых строк — по
     * браузеру: приложение с экрана «Домой» на iPhone не называет себя Safari).
     */
    public function device(): string
    {
        $label = UserAgent::label($this->user_agent);
        $app = $this->app || (preg_match('/iPhone|iPad/', (string) $this->user_agent) && ! str_contains((string) $this->user_agent, 'Safari'));

        return $app ? 'Приложение'.(str_contains($label, ', ') ? ', '.Str::after($label, ', ') : '') : $label;
    }

    /**
     * Сколько длился, секунд: от первого касания до последнего и минута на последнее. Стартовая строка из сессии (без
     * хоста) — одно касание, длительности у неё нет.
     */
    public function seconds(): int
    {
        return $this->surface ? (int) $this->started_at->diffInSeconds($this->last_at) + 60 : 0;
    }
}
