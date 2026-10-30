<?php

namespace App\Http\Admin;

use App\Notifications\Delivery;
use App\Support\Detail;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Настройки → Доставка» (30.10.2026, владелец: «статистику пушей обычно ведут»): что за период ушло людям и открыли ли —
 * по журналу `notice_deliveries` (`Delivery`). «По видам» — строка на вид уведомления: скольким ушло, пушей (принято
 * устройствами), в Telegram, письмом, не ушло, открыли; под названием — кому пуш слать некуда и сколько людей выключили
 * категорию. «По людям» — строка на человека: сколько в день, пушей, открыто, подписки на пуши; карточка строки — его
 * последние уведомления по каналам. Только админ.
 */
final class DeliveryController
{
    public const VIEWS = ['kinds' => 'По видам', 'people' => 'По людям'];

    public const PERIODS = ['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней'];

    private const DAYS = ['today' => 1, '7d' => 7, '30d' => 30];

    public const SORTS = [
        'kinds' => ['sent' => ['Получили', 'desc'], 'push' => ['Пушей', 'desc'], 'opened' => ['Открыли', 'desc'], 'label' => ['Вид', 'asc']],
        'people' => ['sent' => ['Получили', 'desc'], 'push' => ['Пушей', 'desc'], 'opened' => ['Открыли', 'desc'], 'name' => ['Имя', 'asc']],
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $period = array_key_exists($p = (string) $request->query('period'), self::PERIODS) ? $p : '7d';
        $from = now()->startOfDay()->subDays(self::DAYS[$period] - 1);
        $detail = Detail::of($request, fn (string $key) => ($user = User::find($key)) ? $this->detail($user, $from, $period) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $view = $request->query('view') === 'people' ? 'people' : 'kinds';
        $sort = Sort::from($request->query('sort'), self::SORTS[$view], 'sent');
        $rows = $view === 'people' ? $this->people($from, $sort) : $this->kinds($from, $sort);

        return view('admin.deliveries.index', [
            'view' => $view, 'period' => $period, 'days' => self::DAYS[$period], 'sort' => $sort, 'rows' => $rows,
            'table' => $view === 'people' ? self::peopleTable() : self::kindsTable(), 'detail' => $view === 'people' ? $detail : null,
        ]);
    }

    /** Строка на вид: получатели — уведомления (`notice_id` у каждого получателя свой), открыли — доля открытых из них. */
    private function kinds($from, Sort $sort): Collection
    {
        $rows = DB::table('notice_deliveries')->where('created_at', '>=', $from)->groupBy('kind')
            ->selectRaw("kind, max(label) as label, max(category) as category, bool_or(important) as important,
                count(distinct notice_id) as sent, count(distinct user_id) as people,
                count(*) filter (where channel = 'push' and status = 'sent') as push,
                coalesce(sum(devices) filter (where channel = 'push' and status = 'sent'), 0) as devices,
                count(*) filter (where channel = 'push' and status = 'skipped') as nowhere,
                count(*) filter (where channel = 'telegram' and status = 'sent') as telegram,
                count(*) filter (where channel = 'mail' and status = 'sent') as mail,
                count(*) filter (where status = 'failed') as failed,
                count(distinct notice_id) filter (where opened_at is not null) as opened")
            ->get();
        $off = $this->mutedByCategory();
        $rows->each(fn ($r) => $r->muted = $off[$r->category] ?? 0);

        return $this->sorted($rows, $sort, 'label');
    }

    /** Строка на человека: всего, пушей, открыто; подписки на пуши — из `push_subscriptions` (есть и у тех, кому ничего не ушло). */
    private function people($from, Sort $sort): Collection
    {
        $stats = DB::table('notice_deliveries')->where('created_at', '>=', $from)->groupBy('user_id')
            ->selectRaw("user_id, count(distinct notice_id) as sent,
                count(*) filter (where channel = 'push' and status = 'sent') as push,
                count(*) filter (where channel = 'telegram' and status = 'sent') as telegram,
                count(*) filter (where status = 'failed') as failed,
                count(distinct notice_id) filter (where opened_at is not null) as opened")
            ->get()->keyBy('user_id');
        $subs = DB::table('push_subscriptions')->groupBy('user_id')
            ->selectRaw('user_id, count(*) as subs, count(*) filter (where failed_at is not null) as broken, max(last_sent_at) as last_push')
            ->get()->keyBy('user_id');
        $users = User::whereIn('id', $stats->keys()->merge($subs->keys())->unique())->get();
        $rows = $users->map(function (User $u) use ($stats, $subs) {
            $s = $stats[$u->id] ?? null;
            $p = $subs[$u->id] ?? null;

            return (object) [
                'user' => $u, 'name' => $u->name, 'sent' => (int) ($s->sent ?? 0), 'push' => (int) ($s->push ?? 0),
                'telegram' => (int) ($s->telegram ?? 0), 'failed' => (int) ($s->failed ?? 0), 'opened' => (int) ($s->opened ?? 0),
                'subs' => (int) ($p->subs ?? 0), 'broken' => (int) ($p->broken ?? 0), 'last_push' => $p?->last_push ? Carbon::parse($p->last_push) : null,
            ];
        });

        return $this->sorted($rows, $sort, 'name');
    }

    /** Сортировка в памяти: строк — десятки. `opened` — по доле открытых. */
    private function sorted(Collection $rows, Sort $sort, string $text): Collection
    {
        $value = match ($sort->key) {
            'opened' => fn ($r) => $r->sent ? $r->opened / $r->sent : 0,
            $text => fn ($r) => mb_strtolower($r->{$text}),
            default => fn ($r) => (int) $r->{$sort->key},
        };

        return $rows->sortBy($value, SORT_REGULAR, $sort->desc)->values();
    }

    /** Сколько людей выключили категорию в настройках (`notification_settings.off`). @return array<string, int> */
    private function mutedByCategory(): array
    {
        return DB::table('users')->crossJoin(DB::raw("jsonb_array_elements_text(coalesce(notification_settings->'off', '[]'::jsonb)) as off(category)"))
            ->groupBy('off.category')->selectRaw('off.category, count(*) as n')->pluck('n', 'category')->map(fn ($n) => (int) $n)->all();
    }

    /** Карточка человека: последние 60 уведомлений за период, каналы строкой, открыто ли и откуда. */
    private function detail(User $user, $from, string $period)
    {
        $items = Delivery::where('user_id', $user->id)->where('created_at', '>=', $from)->orderByDesc('created_at')->limit(300)->get()
            ->groupBy('notice_id')->take(60);
        $subs = DB::table('push_subscriptions')->where('user_id', $user->id)->get();

        return view('admin.deliveries.detail', ['person' => $user, 'items' => $items, 'subs' => $subs, 'period' => self::PERIODS[$period]]);
    }

    public static function kindsTable(): Table
    {
        return Table::of(
            new Column('title', 'Вид', grow: true, sort: 'label'),
            new Column('sent', 'Получили', role: ColRole::Amount, sort: 'sent', rem: 5.5),
            new Column('push', 'Пушей', from: 'sm', role: ColRole::Amount, sort: 'push', rem: 5),
            new Column('telegram', 'Telegram', from: 'md', role: ColRole::Amount, rem: 5.5),
            new Column('mail', 'Почта', from: 'md', role: ColRole::Amount, rem: 4.5),
            new Column('failed', 'Не ушло', from: 'lg', role: ColRole::Amount, rem: 5),
            new Column('opened', 'Открыли', from: 'sm', role: ColRole::Amount, sort: 'opened', rem: 5.5),
        );
    }

    public static function peopleTable(): Table
    {
        return Table::of(
            new Column('title', 'Кто', grow: true, sort: 'name'),
            new Column('sent', 'Получил', role: ColRole::Amount, sort: 'sent', rem: 5.5),
            new Column('day', 'В день', from: 'sm', role: ColRole::Amount, rem: 4.5),
            new Column('push', 'Пушей', from: 'sm', role: ColRole::Amount, sort: 'push', rem: 5),
            new Column('telegram', 'Telegram', from: 'md', role: ColRole::Amount, rem: 5.5),
            new Column('opened', 'Открыл', from: 'sm', role: ColRole::Amount, sort: 'opened', rem: 5),
            new Column('subs', 'Подписки', from: 'md', role: ColRole::Amount, rem: 5.5, aside: true),
            new Column('last', 'Пуш принят', from: 'lg', role: ColRole::When, rem: 7, aside: true),
        );
    }
}
