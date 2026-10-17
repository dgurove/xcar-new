<?php

namespace App\Http\Logistics;

use App\Cars\Region;
use App\Live\Stream;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Call;
use App\Logistics\Actions\Cancel;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\Docs;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\PickUp;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Notifications\ReadNotices;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
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
    /** «Когда» — дата строки (`Transport::shownAt`): в работе ближайшие сверху; ещё по названию и по дате заведения. */
    public const SORTS = ['when' => ['Когда', 'asc'], 'title' => ['Марка, модель', 'asc'], 'created' => ['Заведена', 'desc']];

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
        // Админу — сначала то, что ждёт его выбора; логисту — его; исполнителю — в работе.
        $home = match (true) {
            isset($pills['new']) && ($counts['new'] ?? 0) > 0 => 'new',
            isset($pills['logist']) && ! $user->isAdmin() && ($counts['logist'] ?? 0) > 0 => 'logist',
            default => 'work',
        };
        $preset = array_key_exists((string) $request->query('preset'), $pills) ? $request->query('preset') : $home;
        $q = is_string($q = $request->query('q')) ? $q : '';

        $transports = $facets->apply(Transport::visibleTo($user)->step($q !== '' ? 'all' : $preset))
            ->with(['region', 'car', 'offer', 'offer.settlement', 'vehicle', 'executorUser', 'coordinator', 'toYard'])
            ->search($q)
            ->latest('id')->limit(300)->get();
        $sort = Sort::from($request->query('sort'), self::SORTS, in_array($preset, ['done', 'all'], true) ? '-when' : 'when');
        $transports = $transports->sortBy(fn (Transport $t) => match ($sort->key) {
            'title' => [mb_strtolower($t->title()), $t->id],
            'created' => [$t->created_at?->timestamp ?? 0, $t->id],
            // Просроченные — первыми.
            default => [$t->isLate() ? 0 : 1, $t->shownAt()?->timestamp ?? PHP_INT_MAX, $t->id],
        }, SORT_REGULAR, $sort->desc)->values();

        return view('logistics.transports.index', [
            'detail' => $detail, 'transports' => $transports, 'preset' => $preset, 'pills' => $pills, 'counts' => $counts, 'home' => $home, 'sort' => $sort,
            'facets' => $facets,
            // Галочки — админу на «Без исполнителя»: назначить и отменить пачкой (118 задач по одной не назначить).
            'pick' => $user->isAdmin() && $preset === 'new' && $q === '',
        ]);
    }

    /**
     * Чипы: регион машины (Татьяне — регионы: «Самарская → Выбрать все → Назначить») и кто вывозит (исполнитель, у логиста
     * без исполнителя — логист). Исполнитель — только тем, кто видит чужие задачи.
     */
    private static function facets(Request $request): Facets
    {
        $user = $request->user();

        return Facets::for($request, 'transports', ...array_filter([
            Facet::column('region', 'Регион', ['регион', 'региона', 'регионов'], 'transports.region_id')
                ->numeric()->none('Без региона')
                ->labels(fn (array $ids) => Region::whereIn('id', Common::ints($ids))->orderBy('name')->get(['id', 'name'])
                    ->mapWithKeys(fn (Region $r) => [(string) $r->id => new Option((string) $r->id, $r->name)])->all()),
            $user->isAdmin() || $user->isLogist()
                ? Common::manager('coalesce(transports.executor_id, transports.coordinator_id)', 'who', 'Кто вывозит')->none('Без исполнителя')
                : null,
        ]));
    }

    /** «Назначить (N)» — админ пачкой: логист или наш сотрудник; менеджер — только по одной (у каждой сделки свой). */
    public function executorMany(Request $request, AssignExecutor $assign)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $request->validate(['executor' => ['required', Rule::in([Executor::Logist->value, Executor::Staff->value, Executor::Partner->value])]], ['executor.required' => 'Кто вывозит']);
        [$kind, $who, $cdek] = AssignExecutor::fromRequest($request);
        // Человек не годится — одна ошибка у поля, а не «не вышло 50».
        AssignExecutor::guard($kind, $who);
        $done = collect();
        $back = $this->many($request, fn (Transport $t) => $done->push($assign($t, $kind, $who, $request->user(), $cdek, batch: true)), 'Назначено', 'Вывозит '.$who->shortName());
        // По задаче не шлём (`TransportAssigned::batch`) — одна строка исполнителю или логисту и одна модераторам.
        if ($done->isNotEmpty()) {
            $who->notify(TransportNotice::assignedMany($done->count(), $kind === Executor::Logist));
            if ($cdek = $done->filter(fn (Transport $t) => $t->docs_by_cdek && ! $t->docs_sent_at)->count()) {
                Notification::send(User::withRole(Role::Moderator)->get(), TransportNotice::docsNeededMany($cdek));
            }
        }

        return $back;
    }

    /** «Отменить (N)» — админ пачкой, с причиной. */
    public function cancelMany(Request $request, Cancel $cancel)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $n = 0;
        $back = $this->many($request, function (Transport $t) use ($cancel, $request, $reason, &$n) {
            $cancel($t, $request->user(), $reason, batch: true);
            $n++;
        }, 'Отменено', 'Вывоз отменён');
        if ($n) {
            Notification::send(User::withRole(Role::Admin)->whereKeyNot($request->user()->id)->get(), TransportNotice::cancelledMany($n, $reason));
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
            'new' => $user->isAdmin(),
            'logist' => $user->isAdmin() || $user->isLogist(),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Ссылка уведомления и Telegram: карточка поверх списка. */
    public function show(Request $request, int $transport)
    {
        abort_unless(Transport::visibleTo($request->user())->whereKey($transport)->exists(), 404);

        return redirect('/transports?preset=all&peek='.$transport);
    }

    public function detail(Transport $transport)
    {
        // Карточку открывают и из списка (`?peek=`), а уведомление о задаче живёт по адресу `/transports/{id}` — открыл
        // карточку, значит прочитал (`ReadNoticesOnVisit` видит только адрес списка).
        if (($me = request()->user()) && ! request()->prefetch()) {
            app(ReadNotices::class)([$me->id], ['/transports/'.$transport->id], Surface::Logistics);
        }
        $transport->load(['car.media', 'offer', 'offer.media', 'offer.settlement', 'vehicle', 'vehicle.media', 'executorUser', 'coordinator', 'toYard', 'invoice', 'docsSender']);

        return view('logistics.transports.detail', ['t' => $transport, 'me' => request()->user()]);
    }

    /** «Кто вывозит» — админ. */
    public function executor(Request $request, Transport $transport, AssignExecutor $assign)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $request->validate(['executor' => ['required', Rule::in([Executor::Logist->value, Executor::Staff->value, Executor::Partner->value])]], ['executor.required' => 'Кто вывозит']);
        [$kind, $who, $cdek] = AssignExecutor::fromRequest($request);
        $assign($transport, $kind, $who, $request->user(), $cdek);

        return back()->with('toast', 'Вывозит '.($transport->fresh()->executorName() ?? ''));
    }

    /** «Документы СДЭКом» — переключает админ. */
    public function cdek(Request $request, Transport $transport, AssignExecutor $assign)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $assign->cdek($transport, $request->boolean('docs_by_cdek'), $request->user());

        return back()->with('toast', $transport->docs_by_cdek ? 'Документы СДЭКом' : 'Документы без СДЭКа');
    }

    /** Логист: исполнитель, расстояние, цена. */
    public function logist(Request $request, Transport $transport, LogistAssign $assign)
    {
        $this->visible($request, $transport);
        $assign($transport, $request->validate(LogistAssign::rules(), ['executor_id.required' => 'Кто вывозит', 'cost.required' => 'Стоимость вывоза']), $request->user());

        return back()->with('toast', 'Вывозит '.$transport->fresh()->executorName());
    }

    public function call(Request $request, Transport $transport, Call $call)
    {
        $this->visible($request, $transport);
        $call->agree($transport, $request->validate(Call::rules(), Call::messages()), $request->user());

        return back()->with('toast', 'Созвонились');
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

        return back()->with('toast', 'Документы отправлены');
    }

    public function received(Request $request, Transport $transport, Docs $docs)
    {
        $this->visible($request, $transport);
        $docs->receive($transport, $request->user());

        return back()->with('toast', 'Документы у вас');
    }

    public function pickup(Request $request, Transport $transport, PickUp $pickUp)
    {
        $this->visible($request, $transport);
        $pickUp($transport, $transport->isPickup() ? $request->validate(PickUp::rules(), PickUp::messages()) : [], $request->user());

        return back()->with('toast', 'Вывезена: '.$transport->title());
    }

    public function deliver(Request $request, Transport $transport, Deliver $deliver)
    {
        $this->visible($request, $transport);
        $deliver($transport, $request->user());

        return back()->with('toast', 'Привёз: '.$transport->title());
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
        abort_if(in_array($transport->state, [TransportState::Delivered, TransportState::Cancelled], true) && ! $request->user()->isAdmin(), 404);
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
        abort_unless($this->owns($transport, $media) && (! $transport->state->picked() || $request->user()->isAdmin()), 404);
        $at = (string) $media->getCustomProperty('at');
        $media->delete();

        return $this->photos($transport, $at);
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
        abort_unless($transport->isHandledBy($request->user()), 404);
    }
}
