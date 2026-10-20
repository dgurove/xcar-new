<?php

namespace App\Http\Logistics;

use App\Cars\Car;
use App\Cars\Region;
use App\Live\Publisher;
use App\Live\Stream;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Backdate;
use App\Logistics\Actions\Call;
use App\Logistics\Actions\Cancel;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\Docs;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\PickUp;
use App\Logistics\Actions\SetRoute;
use App\Logistics\Listeners\NotifyLogistics;
use App\Logistics\Actions\StepBack;
use App\Logistics\Channel;
use App\Offers\Actions\SellAtAuction;
use App\Logistics\Client;
use App\Logistics\FileAccess;
use App\Logistics\Listeners\RefreshLogistics;
use App\Logistics\Reach;
use App\Logistics\SaleState;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Notifications\ReadNotices;
use App\Offers\Binding;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Logistics XCar — вывоз (17.10.2026): задачи списком, карточка рядом (ПК — колонкой, телефон — листом снизу). Админ
 * выбирает, кто вывозит; логист назначает своего исполнителя и цену; исполнитель созванивается с владельцем, получает
 * документы СДЭКом, «Забрал» с фото и ключами, «Привёз».
 */
class TransportController
{
    /**
     * Сортировка заголовками (18.10.2026, владелец: «удобная таблица»): по умолчанию — когда машина поступила, новые сверху
     * (у «Без исполнителя» и «У логиста»), в работе — дата вывоза, готовые — свежие сверху. Всё — в SQL.
     */
    public const SORTS = [
        'created' => ['Поступила', 'desc'], 'title' => ['Марка, модель', 'asc'], 'vendor' => ['Вендор', 'asc'], 'sale' => ['Продажа', 'desc'],
        'city' => ['Город', 'asc'], 'who' => ['Исполнитель', 'asc'], 'when' => ['Когда', 'asc'],
        'binding' => ['Срок обязывающего', 'asc'],
    ];

    /** Пилюли — шаги работы. Кому какие — `pills`. */
    private const PRESETS = [
        'new' => 'Без исполнителя',
        'logist' => 'У логиста',
        'work' => 'В работе',
        'moving' => 'В пути',
        'done' => 'Готово',
        'all' => 'Все',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $detail = Detail::of($request, fn (string $key) => ($t = Transport::visibleTo($user)->find($key)) ? $this->detail($t) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = self::facets($request);
        ListPrefs::sync($request, 'transports', keep: [...$facets->keys(), 'sort'], view: false);
        $pills = self::pills($user);
        $counts = collect($pills)->keys()->reject(fn ($k) => in_array($k, ['done', 'all'], true))
            ->mapWithKeys(fn ($k) => [$k => $facets->applyTo(Transport::visibleTo($user)->step($k))->count()])->filter()->all();
        $home = self::homeOf($user, $pills, fn (string $k) => ($counts[$k] ?? 0) > 0);
        $preset = array_key_exists((string) $request->query('preset'), $pills) ? $request->query('preset') : $home;
        $q = is_string($q = $request->query('q')) ? $q : '';
        $sort = Sort::from($request->query('sort'), self::SORTS, self::defaultSort($preset));

        $query = $facets->apply(Transport::visibleTo($user)->step($q !== '' ? 'all' : $preset))
            ->with(['region', 'car', ...SaleState::WITH, 'executor', 'logist', 'toYard', 'client', 'depot', 'fromDepot'])
            ->search($q, crm: $user->leadsLogistics());
        self::sorted($query, $sort);
        // Открытые вкладки — целиком (таблицу листают и выбирают галочками); «Готово» и «Все» растут без конца — страницами.
        $transports = in_array($preset, ['done', 'all'], true) && $q === ''
            ? $query->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString()
            : $query->get();

        return view('logistics.transports.index', [
            'detail' => $detail, 'transports' => $transports, 'preset' => $preset, 'pills' => $pills, 'counts' => $counts, 'home' => $home, 'sort' => $sort,
            'facets' => $facets,
            'pick' => self::pickable($user, $preset),
            'cols' => self::columns($user, $preset),
        ]);
    }

    /**
     * Галочки — ведущим логистику на «Без исполнителя»: «Назначить (N)» и «Отменить (N)». Одна дверь списка и свежей строки
     * карточки — иначе открытая строка приходила без галочки и столбцы съезжали (18.10.2026). От поиска не зависит:
     * лупа адрес не меняет, и карточка о ней не знает; в выдаче поиска галочка — только у задач без исполнителя.
     */
    public static function pickable(User $user, string $preset): bool
    {
        return $user->leadsLogistics() && $preset === 'new';
    }

    /** Вкладка по умолчанию: ведущим — что ждёт их выбора; логисту — его; исполнителю — в работе. */
    private static function homeOf(User $user, array $pills, \Closure $has): string
    {
        return match (true) {
            isset($pills['new']) && $has('new') => 'new',
            isset($pills['logist']) && ! $user->leadsLogistics() && $has('logist') => 'logist',
            default => 'work',
        };
    }

    /** Вкладка запроса — и для карточки строки (`detail`): адрес без `preset` — та же, что список выбрал сам. */
    public static function presetOf(Request $request, User $user): string
    {
        $pills = self::pills($user);
        if (array_key_exists((string) $request->query('preset'), $pills)) {
            return $request->query('preset');
        }
        $facets = self::facets($request);

        return self::homeOf($user, $pills, fn (string $k) => $facets->applyTo(Transport::visibleTo($user)->step($k))->exists());
    }

    private static function defaultSort(string $preset): string
    {
        return match ($preset) {
            'new', 'logist' => '-created',
            'done', 'all' => '-when',
            default => 'when',
        };
    }

    /** Порядок в SQL: по полю, при равенстве — новые задачи выше. */
    private static function sorted($q, Sort $sort): void
    {
        $dir = $sort->dir();
        match ($sort->key) {
            'created' => $q->orderBy('transports.created_at', $dir),
            'title' => $q->orderByRaw("(select b.name from cars c join brands b on b.id = c.brand_id where c.id = transports.car_id) {$dir} nulls last")
                ->orderByRaw("(select m.name from cars c join car_models m on m.id = c.model_id where c.id = transports.car_id) {$dir} nulls last"),
            'vendor' => $q->orderByRaw('(select vendors.name from vendors where vendors.id = '.SaleState::VENDOR_SQL.") {$dir} nulls last"),
            'sale' => $q->orderByRaw(SaleState::RANK_SQL." {$dir}")->orderBy('transports.created_at'),
            'city' => $q->orderByRaw("(select regions.name from regions where regions.id = transports.region_id) {$dir} nulls last"),
            'who' => $q->orderByRaw("(select users.name from users where users.id = coalesce(transports.executor_id, transports.logist_id)) {$dir} nulls last"),
            'binding' => $q->orderByRaw(Binding::UNTIL_SQL." {$dir} nulls last"),
            // «Когда»: просроченный вывоз — первым.
            default => $q->orderByRaw("(transports.state = 'agreed' and transports.pickup_at < now()) desc")->orderByRaw(Transport::SHOWN_SQL." {$dir} nulls last"),
        };
        $q->orderByDesc('transports.id');
    }

    /**
     * Столбцы таблицы — по человеку и вкладке (18.10.2026, Катя и Лиза: «не хватает номера убытка, логотипа страховой,
     * менеджера — кому везти»). Вендор и номер убытка — только сотрудникам XCar (внешний логист и исполнители к страховой
     * напрямую не ходят); продажа и менеджер — ведущим логистику; «Кто вывозит» — тем, кто видит чужие задачи.
     *
     * @return list<string>
     */
    public static function columns(User $user, string $preset): array
    {
        $lead = $user->leadsLogistics();
        $deciding = in_array($preset, ['new', 'logist'], true);

        return array_values(array_filter([
            self::pickable($user, $preset) ? 'pick' : null,
            'title',
            // Логисту — чей вывоз: XCar или его заказчик (18.10.2026).
            $user->isLogist() && ! $lead ? 'client' : null,
            $user->isStaff() ? 'vendor' : null,
            // Продажа — где решают, кому отдать (и в «Все», поиске); в работе решение уже принято.
            $lead && ! in_array($preset, ['work', 'moving', 'done'], true) ? 'sale' : null,
            // Срок обязывающего предложения (19.10.2026, логистика: «хотим сортировать и фильтровать по нему») — только сотрудникам
            // XCar: это сведения Совкомбанка, логисту и исполнителям их не видно.
            $lead && $preset !== 'done' ? 'binding' : null,
            'to',
            'city',
            // Что дальше — своим столбцом там, где шаги разные (на «Без исполнителя» и «У логиста» он у всех один).
            $deciding ? null : 'step',
            ($lead || $user->isLogist()) && $preset !== 'new' ? 'who' : null,
            // Поступила — тем, кто решает: ведущим и логисту (на «У логиста» это единственная дата).
            $lead || $user->isLogist() ? 'created' : null,
            $deciding ? null : 'when',
        ]));
    }

    /**
     * Чипы: «Срочно» и продажа (ведущим — кому отдавать в первую очередь), вендор (сотрудникам), менеджер — кому везти,
     * регион (Татьяне — регионы: «Самарская → Выбрать все → Назначить»), кто вывозит (исполнитель, у логиста без исполнителя
     * — логист), тип ТС.
     */
    private static function facets(Request $request): Facets
    {
        $user = $request->user();
        $lead = $user->leadsLogistics();

        return Facets::for($request, 'transports', ...array_filter([
            $lead ? Facet::column('sale', 'Продажа', ['вид', 'вида', 'видов'], SaleState::BUCKET_SQL)->natural()
                ->labels(fn (array $keys) => collect(['buyer' => 'Есть покупатель', 'open' => 'Ищем покупателя', 'draft' => 'Не опубликовано', 'off' => 'Снята с продажи', 'park' => 'Перевод парковки'])
                    ->only($keys)->map(fn ($label, $key) => new Option($key, $label))->all()) : null,
            $user->isStaff() ? Common::vendor(SaleState::VENDOR_SQL)->none('Без вендора') : null,
            $lead ? Common::manager(SaleState::MANAGER_SQL)->none('Без менеджера') : null,
            // Срок обязывающего предложения (19.10.2026): горит без покупателя, вышел, есть, нет.
            $lead ? Facet::column('binding', 'Срок обязывающего', ['вид', 'вида', 'видов'], Binding::bucketSql())->natural()
                ->labels(fn (array $keys) => collect(Binding::BUCKETS)
                    ->only($keys)->map(fn ($label, $key) => new Option($key, $label))->all()) : null,
            // Межрегион (18.10.2026, начальник: «важно») — переключателем, как «Срочно».
            Facet::toggle('far', 'Между регионами', fn ($q) => $q->where('transports.reach', Reach::Intercity->value)),
            Facet::column('region', 'Регион', ['регион', 'региона', 'регионов'], 'transports.region_id')
                ->numeric()->none('Без региона')
                ->labels(fn (array $ids) => Region::whereIn('id', Common::ints($ids))->orderBy('name')->get(['id', 'name'])
                    ->mapWithKeys(fn (Region $r) => [(string) $r->id => new Option((string) $r->id, $r->name)])->all()),
            $lead || $user->isLogist()
                ? Common::manager('coalesce(transports.executor_id, transports.logist_id)', 'who', 'Исполнитель')->none('Без исполнителя')
                : null,
            // Заказчик логиста: XCar (вывоз продажи и парковки) или его заказчики; «Заявки N ›» заказчика ведёт сюда.
            $user->isLogist() ? Facet::column('client', 'Заказчик', ['заказчик', 'заказчика', 'заказчиков'], 'transports.client_id')
                ->numeric()->none('XCar')
                ->labels(fn (array $ids) => Client::of($user)->whereIn('id', Common::ints($ids))->orderBy('name')->get(['id', 'name'])
                    ->mapWithKeys(fn (Client $c) => [(string) $c->id => new Option((string) $c->id, $c->name)])->all()) : null,
            Common::category(Car::sql('transports', 'category')),
        ]));
    }

    /** «Назначить (N)» — ведущие пачкой: логист или наш сотрудник; менеджер — только по одной (у каждой сделки свой). */
    public function executorMany(Request $request, AssignExecutor $assign, SetRoute $route)
    {
        abort_unless($request->user()->leadsLogistics(), 404);
        $request->validate(['channel' => ['required', Rule::in([Channel::Logist->value, Channel::Staff->value, Channel::Partner->value])]], ['channel.required' => 'Кто вывозит']);
        [$kind, $who, $cdek] = AssignExecutor::fromRequest($request);
        $reach = $request->validate(['reach' => SetRoute::rules()['reach']], SetRoute::messages());
        // Человек не годится — одна ошибка у поля, а не «не вышло 50».
        AssignExecutor::guard($kind, $who);
        $done = collect();
        $back = $this->many($request, function (Transport $t) use ($done, $assign, $kind, $who, $request, $cdek, $route, $reach) {
            $done->push($assign($t, $kind, $who, $request->user(), $cdek, batch: true));
            $route($t->fresh(), $reach, $request->user());
        }, 'Назначено', 'Вывозит '.$who->shortName());
        // По задаче не шлём (`TransportAssigned::batch`) — одна строка исполнителю или логисту и одна модераторам.
        if ($done->isNotEmpty()) {
            $who->notify(TransportNotice::assignedMany($done->count(), $kind === Channel::Logist));
            if ($cdek = $done->filter(fn (Transport $t) => $t->docs_by_cdek && ! $t->docs_sent_at)->count()) {
                Notification::send(User::withRole(Role::Moderator)->get(), TransportNotice::docsNeededMany($cdek));
            }
        }

        return $back;
    }

    /** «Отменить (N)» — ведущие пачкой, с причиной. */
    public function cancelMany(Request $request, Cancel $cancel)
    {
        abort_unless($request->user()->leadsLogistics(), 404);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $n = 0;
        $own = [];
        $back = $this->many($request, function (Transport $t) use ($cancel, $request, $reason, &$n, &$own) {
            $cancel($t, $request->user(), $reason, batch: true);
            $n++;
            foreach (NotifyLogistics::workers($t, $request->user()) as $u) {
                $own[$u->id] = [$u, ($own[$u->id][1] ?? 0) + 1];
            }
        }, 'Отменено', 'Вывоз отменён');
        if ($n) {
            Notification::send(User::logisticsLeads()->whereKeyNot($request->user()->id)->whereKeyNot(array_keys($own))->get(), TransportNotice::cancelledMany($n, $reason));
        }
        // Логисту и исполнителю снятых задач — своё число в Telegram (20.10.2026).
        foreach ($own as [$u, $count]) {
            $u->notify(TransportNotice::cancelledYoursMany($count, $reason));
        }

        return $back;
    }

    /** Действие на каждую отмеченную задачу: что не прошло (уже забрали, закрыта), остальное не роняет — тост считает. */
    private function many(Request $request, \Closure $act, string $done, string $one)
    {
        $ids = $request->validate(['transports' => ['required', 'array', 'max:500'], 'transports.*' => ['integer']], ['transports.required' => 'Отметьте задачи'])['transports'];
        $ok = 0;
        $failed = [];
        foreach (Transport::whereKey($ids)->get() as $t) {
            try {
                $act($t);
                $ok++;
            } catch (ValidationException $e) {
                $failed[] = $t->title().': '.mb_strtolower(collect($e->errors())->flatten()->first());
            }
        }
        $toast = match (true) {
            $ok === 1 && ! $failed => $one,
            count($failed) === 1 => implode(', ', array_filter([$ok ? $done.' '.$ok : null, $failed[0]])),
            default => implode(', ', array_filter([$ok ? $done.' '.$ok : null, $failed ? 'не вышло '.count($failed) : null])),
        };

        return back()->with('toast', $toast);
    }

    /** @return array<string, string> */
    private static function pills(User $user): array
    {
        return array_filter(self::PRESETS, fn ($label, $key) => match ($key) {
            'new' => $user->leadsLogistics(),
            'logist' => $user->leadsLogistics() || $user->isLogist(),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Ссылка уведомления и Telegram: карточка поверх списка. */
    public function show(Request $request, int $transport)
    {
        abort_unless(Transport::visibleTo($request->user())->whereKey($transport)->exists(), 404);

        return redirect('/transports?preset=all&peek='.$transport);
    }

    /** `list` — чей список открыл карточку: её строка должна быть той же, что в списке (`documents` — своя строка). */
    public function detail(Transport $transport, string $list = 'transports')
    {
        // Карточку открывают и из списка (`?peek=`), а уведомление о задаче живёт по адресу `/transports/{id}` — открыл
        // карточку, значит прочитал (`ReadNoticesOnVisit` видит только адрес списка).
        if (($me = request()->user()) && ! request()->prefetch()) {
            app(ReadNotices::class)([$me->id], ['/transports/'.$transport->id], Surface::Logistics);
        }
        $transport->load(['car.media', 'offer.media', 'offer.positions.stage.block', 'vehicle.media', ...SaleState::WITH, 'executor', 'logist', 'region', 'toYard', 'invoice', 'docsSender', 'client']);
        $me = request()->user();
        $preset = $list === 'transports' ? self::presetOf(request(), $me) : null;

        return view('logistics.transports.detail', ['t' => $transport, 'me' => $me, 'list' => $list, 'preset' => $preset,
            'pick' => $preset !== null && self::pickable($me, $preset), 'cols' => $preset !== null ? self::columns($me, $preset) : []]);
    }

    /** «Кто вывозит» — ведущие логистику. */
    public function executor(Request $request, Transport $transport, AssignExecutor $assign, SetRoute $route)
    {
        abort_unless($request->user()->leadsLogistics(), 404);
        $request->validate(['channel' => ['required', Rule::in([Channel::Logist->value, Channel::Staff->value, Channel::Partner->value])]], ['channel.required' => 'Кто вывозит']);
        [$kind, $who, $cdek] = AssignExecutor::fromRequest($request);
        // Куда, дальность и комментарий — в том же окне (18.10.2026); у перевода парковки точка решена при отправке.
        $data = $transport->isPickup() ? $request->validate(SetRoute::rules(), SetRoute::messages()) : null;
        DB::transaction(function () use ($transport, $assign, $kind, $who, $request, $cdek, $route, $data) {
            $assign($transport, $kind, $who, $request->user(), $cdek);
            if ($data) {
                $route($transport->fresh(), $data, $request->user());
            }
        });

        return back()->with('toast', Str::ucfirst($transport->fresh()->carrier() ?? 'ждёт исполнителя'));
    }

    /** «Аукцион» в «Кто вывозит» (20.10.2026): машину забрал покупатель аукциона — та же «Продана на аукционе», что в CRM. */
    public function auction(Request $request, Transport $transport, SellAtAuction $sell)
    {
        abort_unless($request->user()->canManageCrm() && ($offer = $transport->saleOffer()), 404);
        $sell($offer, $request->user(), $request->validate(SellAtAuction::rules(), SellAtAuction::messages()));

        return back()->with('toast', 'Продана на аукционе, забрал аукцион');
    }

    /** «Куда везти» строкой карточки: точку правит любой, кто ведёт задачу, и исполнитель; дальность и комментарий — ведущие и логист. */
    public function route(Request $request, Transport $transport, SetRoute $route)
    {
        $this->visible($request, $transport);
        $was = $transport->routeLabel();
        $route($transport, $request->validate(SetRoute::rules(SetRoute::full($transport, $request->user())), SetRoute::messages()), $request->user());
        // Точку поменял не тот, кто везёт, — логисту и исполнителю, у кого задача уже в работе (20.10.2026).
        if ($transport->routeLabel() !== $was && $transport->isOpen()) {
            Notification::send(NotifyLogistics::workers($transport, $request->user()), TransportNotice::rerouted($transport));
        }

        return back()->with('toast', 'Куда: '.($transport->fresh()->routeLabel() ?? 'пока неизвестно'));
    }

    /** «Документы СДЭКом» — переключают ведущие логистику. */
    public function cdek(Request $request, Transport $transport, AssignExecutor $assign)
    {
        abort_unless($request->user()->leadsLogistics(), 404);
        $assign->cdek($transport, $request->boolean('docs_by_cdek'), $request->user());

        return back()->with('toast', $transport->docs_by_cdek ? 'Документы СДЭКом' : 'Документы без СДЭКа');
    }

    /** Логист: исполнитель, расстояние, цена. */
    public function logist(Request $request, Transport $transport, LogistAssign $assign)
    {
        $this->visible($request, $transport);
        $assign($transport, $request->validate(LogistAssign::rules(), ['executor_id.required' => 'Кто вывозит', 'cost.required' => 'Стоимость вывоза']), $request->user());

        return back()->with('toast', Str::ucfirst($transport->fresh()->carrier() ?? 'ждёт исполнителя'));
    }

    public function call(Request $request, Transport $transport, Call $call)
    {
        $this->visible($request, $transport);
        $call->agree($transport, $request->validate(Call::rules(), Call::messages()), $request->user());

        return back()->with('toast', 'Созвонились')->with('toast-undo', 'transport');
    }

    public function missed(Request $request, Transport $transport, Call $call)
    {
        $this->visible($request, $transport);
        $call->missed($transport, $request->user());

        return back()->with('toast', 'Не дозвонились');
    }

    /** Трек СДЭК — модератор (Лиза) или админ. */
    public function docs(Request $request, Transport $transport, Docs $docs)
    {
        $this->visible($request, $transport);
        $docs->send($transport, (string) $request->validate(['docs_track' => ['required', 'string', 'max:60']], ['docs_track.required' => 'Трек-номер СДЭК'])['docs_track'], $request->user());

        return back()->with('toast', 'Документы отправлены')->with('toast-undo', 'transport');
    }

    public function received(Request $request, Transport $transport, Docs $docs)
    {
        $this->visible($request, $transport);
        $docs->receive($transport, $request->user());

        return back()->with('toast', 'Документы у вас')->with('toast-undo', 'transport');
    }

    public function pickup(Request $request, Transport $transport, PickUp $pickUp)
    {
        $this->visible($request, $transport);
        $pickUp($transport, $transport->isPickup() ? $request->validate(PickUp::rules(), PickUp::messages()) : [], $request->user());

        return back()->with('toast', 'Вывезена: '.$transport->title())->with('toast-undo', 'transport');
    }

    /** «Уже вывезли» — задним числом (ведущие логистику). */
    public function backdate(Request $request, Transport $transport, Backdate $backdate)
    {
        $this->visible($request, $transport);
        $backdate($transport, $request->validate(Backdate::rules(), Backdate::messages()), $request->user());

        return back()->with('toast', 'Отмечена вывезенной: '.$transport->title());
    }

    /** «Уже вывезли (N)» — ведущие пачкой, одна дата и точка на всех; что не прошло (нет менеджера), остальное не роняет. */
    public function backdateMany(Request $request, Backdate $backdate)
    {
        abort_unless($request->user()->leadsLogistics(), 404);
        $data = $request->validate(Backdate::rules(), Backdate::messages());
        $n = 0;
        $back = $this->many($request, function (Transport $t) use ($backdate, $request, $data, &$n) {
            $backdate($t, $data, $request->user(), batch: true);
            $n++;
        }, 'Отмечено вывезенными', 'Отмечена вывезенной');
        if ($n) {
            Notification::send(User::logisticsLeads()->whereKeyNot($request->user()->id)->get(), TransportNotice::backdatedMany($n));
        }

        return $back;
    }

    public function deliver(Request $request, Transport $transport, Deliver $deliver)
    {
        $this->visible($request, $transport);
        $deliver($transport, $request->user());

        return back()->with('toast', 'Привёз: '.$transport->title())->with('toast-undo', 'transport');
    }

    /** «Отменить «шаг»» — последний шаг задачи назад (`StepBack`): из «···» шага и тостом сразу после него. */
    public function back(Request $request, Transport $transport, StepBack $back)
    {
        $this->visible($request, $transport);
        $step = StepBack::last($transport);
        $back($transport, $request->user());

        return back()->with('toast', 'Отменено: «'.(TransportEvent::STEPS[$step] ?? 'шаг').'»');
    }

    public function cancel(Request $request, Transport $transport, Cancel $cancel)
    {
        $this->visible($request, $transport);
        $cancel($transport, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null);

        return back()->with('toast', 'Вывоз отменён');
    }

    /**
     * Кадры с вывоза — по одному с телефона (`photos_controller`: ужатие, прогресс, HEIC), до «Забрал». Ложатся машине
     * (`stage = pickup`, скрыты с витрины — открывает админ), `at` — «Забрал» или «Привёз».
     */
    public function upload(Request $request, Transport $transport, PhotoIngest $ingest)
    {
        $this->acts($request, $transport);
        // Закрытая задача — кадры уже факт: добавляет только админ.
        abort_if(in_array($transport->state, [TransportState::Delivered, TransportState::Cancelled], true) && ! $request->user()->leadsLogistics(), 404);
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $at = $request->input('stage') === 'delivery' ? 'delivery' : 'pickup';
        try {
            $ingest->fromPhone($transport->car, 'photos', $request, properties: ['stage' => 'pickup', 'source' => 'app', 'transport' => $transport->id, 'at' => $at, 'hidden' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->photos($transport, $at);
    }

    /** Порядок кадров приёма — как поставили пальцем. */
    public function order(Request $request, Transport $transport)
    {
        $this->acts($request, $transport);
        $ids = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        $mine = $transport->photos()->pluck('id')->all();
        Media::setNewOrder(array_values(array_intersect($ids, $mine)));

        return $this->photos($transport, $request->input('stage') === 'delivery' ? 'delivery' : 'pickup');
    }

    public function rotate(Request $request, Transport $transport, Media $media, RotatePhoto $rotate)
    {
        $this->acts($request, $transport);
        abort_unless($this->owns($transport, $media), 404);
        $rotate($media, RotatePhoto::turns($request));

        return $this->photos($transport, (string) $media->getCustomProperty('at'));
    }

    public function destroyMedia(Request $request, Transport $transport, Media $media)
    {
        $this->acts($request, $transport);
        // Удалить кадр — пока машину не забрали; потом это факт приёма (админ может всегда).
        abort_unless($this->owns($transport, $media) && (! $transport->state->picked() || $request->user()->leadsLogistics()), 404);
        $at = (string) $media->getCustomProperty('at');
        $media->delete();

        return $this->photos($transport, $at);
    }

    /**
     * Глаз «кто видит» у фото и документа машины (`Logistics\FileAccess`, 19.10.2026): ведущий решает за логиста (без него —
     * за исполнителя), логист — за своего исполнителя. Кадр шлёт `photos#hide`, документ — форма в строке; ответ — блок
     * целиком, у остальных задача перечитывается.
     */
    public function fileEye(Request $request, Transport $transport, Media $media, Publisher $publish)
    {
        $this->acts($request, $transport);
        $me = $request->user();
        $who = FileAccess::toggleFor($transport, $me);
        abort_unless($who && FileAccess::belongs($transport, $media) && FileAccess::visible($transport, $me, $media), 404);
        FileAccess::toggle($media, $who);
        $publish->refresh(RefreshLogistics::topics($transport), ["/transports/{$transport->id}"]);
        $transport->unsetRelation('car');

        return Stream::view('logistics.transports.files-stream', ['t' => $transport]);
    }

    private function photos(Transport $transport, string $at)
    {
        $transport->unsetRelation('car');

        return Stream::view('logistics.transports.photos-stream', ['t' => $transport, 'at' => $at]);
    }

    private function owns(Transport $transport, Media $media): bool
    {
        return $media->model_type === $transport->car?->getMorphClass() && $media->model_id === $transport->car_id
            && (int) $media->getCustomProperty('transport') === $transport->id;
    }

    private function visible(Request $request, Transport $transport): void
    {
        abort_unless(Transport::visibleTo($request->user())->whereKey($transport->id)->exists(), 404);
    }

    /** Действует по задаче: исполнитель, её логист, админ. */
    private function acts(Request $request, Transport $transport): void
    {
        abort_unless($transport->isHandledBy($request->user()) && Transport::visibleTo($request->user())->whereKey($transport->id)->exists(), 404);
    }
}
