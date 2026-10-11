<?php

namespace App\Http\Logistics;

use App\Logistics\Actions\Cancel;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\PickUp;
use App\Logistics\Actions\SaveTransport;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Notifications\ReadNotices;
use App\Support\Detail;
use App\Support\Sort;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Logistics XCar: перевозки списком, карточка рядом (ПК — колонкой, телефон — листом снизу). Админ и логист видят все и
 * назначают межрегиональные; водитель и партнёр — свои рейсы, «Забрал» и «Сдал» с фото.
 */
class TransportController
{
    public const PRESETS = ['waiting' => 'Ждут логиста', 'work' => 'В работе', 'done' => 'Доставлены', 'all' => 'Все'];

    /** «Когда» — дата строки (`Transport::shownAt`): в работе ближайшие сверху; ещё по названию и по дате заведения. */
    public const SORTS = ['when' => ['Когда', 'asc'], 'title' => ['Марка, модель', 'asc'], 'created' => ['Заведена', 'desc']];

    public function index(Request $request)
    {
        $user = $request->user();
        $detail = Detail::of($request, fn (string $key) => ($t = Transport::visibleTo($user)->find($key)) ? $this->detail($t) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $pills = $user->seesAllTransports() ? self::PRESETS : array_diff_key(self::PRESETS, ['waiting' => 1]);
        $counts = [
            'waiting' => Transport::visibleTo($user)->where('state', TransportState::New)->count(),
            'work' => Transport::visibleTo($user)->whereIn('state', [TransportState::Assigned, TransportState::Moving])->count(),
        ];
        // Логисту — сначала то, что ждёт его; остальным — в работе.
        $home = isset($pills['waiting']) && $counts['waiting'] > 0 ? 'waiting' : 'work';
        $preset = array_key_exists((string) $request->query('preset'), $pills) ? $request->query('preset') : $home;

        $transports = Transport::visibleTo($user)
            ->with(['offer.brand', 'offer.model', 'vehicle.brand', 'vehicle.model', 'executorUser', 'fromRegion', 'toRegion'])
            ->when($preset === 'waiting', fn ($q) => $q->where('state', TransportState::New))
            ->when($preset === 'work', fn ($q) => $q->whereIn('state', [TransportState::Assigned, TransportState::Moving]))
            ->when($preset === 'done', fn ($q) => $q->where('state', TransportState::Delivered))
            ->search(is_string($q = $request->query('q')) ? $q : '')
            // В работе — ближайшие сверху: по дате отправки, потом по назначению; доставленные — свежие сверху.
            ->when(in_array($preset, ['done', 'all'], true), fn ($q) => $q->latest('updated_at'), fn ($q) => $q->orderByRaw('ship_on nulls last')->oldest('id'))
            ->limit(300)->get();
        // Доставленные и «Все» по умолчанию — свежие сверху, в работе — ближайшие.
        $sort = Sort::from($request->query('sort'), self::SORTS, in_array($preset, ['done', 'all'], true) ? '-when' : 'when');
        $transports = $transports->sortBy(fn (Transport $t) => match ($sort->key) {
            'title' => [mb_strtolower($t->title()), $t->id],
            'created' => [$t->created_at?->timestamp ?? 0, $t->id],
            default => [$t->shownAt()?->timestamp ?? PHP_INT_MAX, $t->id],
        }, SORT_REGULAR, $sort->desc)->values();

        return view('logistics.transports.index', [
            'detail' => $detail, 'transports' => $transports, 'preset' => $preset, 'pills' => $pills, 'counts' => array_filter($counts), 'home' => $home, 'sort' => $sort,
        ]);
    }

    /** Ссылка уведомления и Telegram: карточка поверх списка. */
    public function show(Request $request, int $transport)
    {
        abort_unless(Transport::visibleTo($request->user())->whereKey($transport)->exists(), 404);

        return redirect('/transports?preset=all&peek='.$transport);
    }

    public function detail(Transport $transport)
    {
        // Карточку открывают и из списка (`?peek=`), а уведомление о перевозке живёт по адресу `/transports/{id}` —
        // открыл карточку, значит прочитал (`ReadNoticesOnVisit` видит только адрес списка).
        if (($me = request()->user()) && ! request()->prefetch()) {
            app(ReadNotices::class)([$me->id], ['/transports/'.$transport->id], Surface::Logistics);
        }
        $transport->load(['offer.brand', 'offer.model', 'offer.media', 'vehicle.brand', 'vehicle.model', 'vehicle.media', 'executorUser', 'fromRegion', 'toRegion', 'toYard', 'media', 'invoice']);

        return view('logistics.transports.detail', ['t' => $transport, 'me' => request()->user()]);
    }

    public function assign(Request $request, Transport $transport, SaveTransport $save)
    {
        abort_unless($request->user()->seesAllTransports(), 404);
        $data = $request->validate(SaveTransport::rules(), SaveTransport::messages());
        $save($transport, $data, $request->user());

        return back()->with('toast', 'Сохранено');
    }

    public function pickup(Request $request, Transport $transport, PickUp $pickUp)
    {
        $this->visible($request, $transport);
        $pickUp($transport, $request->user(), $this->files($request));

        return back()->with('toast', 'Забрал: '.$transport->title());
    }

    public function deliver(Request $request, Transport $transport, Deliver $deliver)
    {
        $this->visible($request, $transport);
        $deliver($transport, $request->user(), $this->files($request));

        return back()->with('toast', 'Сдал: '.$transport->title());
    }

    public function cancel(Request $request, Transport $transport, Cancel $cancel)
    {
        $this->visible($request, $transport);
        $cancel($transport, $request->user());

        return back()->with('toast', 'Перевозка отменена');
    }

    private function visible(Request $request, Transport $transport): void
    {
        abort_unless(Transport::visibleTo($request->user())->whereKey($transport->id)->exists(), 404);
    }

    private function files(Request $request): array
    {
        $request->validate(['photos' => ['nullable', 'array', 'max:30'], 'photos.*' => ['file', 'max:30720', 'mimes:jpg,jpeg,png,webp,heic,heif']],
            ['photos.*.mimes' => 'Только фото: JPG, PNG, WEBP или HEIC', 'photos.*.max' => 'Фото больше 30 МБ']);

        return array_values($request->file('photos', []));
    }
}
