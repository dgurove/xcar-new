<?php

namespace App\Http\Park;

use App\Billing\Accrual;
use App\Billing\Cadence;
use App\Billing\Ledger;
use App\Cars\Car;
use App\Cars\Vin\Vin;
use App\Http\Admin\OfferPhotoController;
use App\Live\Rows;
use App\Live\Stream;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Extraction\Patterns;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Mail\Scan\VehicleSubject;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Media\Actions\RotatePhoto;
use App\Media\Hidden;
use App\Media\PhotoBlocks;
use App\Media\PhotoIngest;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Pricing;
use App\Park\Actions\CancelVehicle;
use App\Park\Actions\FillFromDocs;
use App\Park\Actions\MarkDoc;
use App\Park\Actions\MarkSold;
use App\Park\Actions\Move;
use App\Park\Actions\PutOnSale;
use App\Park\Actions\RegisterCandidate;
use App\Park\Actions\RestoreVehicle;
use App\Park\Actions\SetCity;
use App\Park\Actions\SetDates;
use App\Park\Actions\SetYard;
use App\Park\Actions\SwapSpots;
use App\Park\Actions\TakeOffSale;
use App\Park\Actions\Transfer;
use App\Park\Actions\UndoIntake;
use App\Park\Actions\UndoRelease;
use App\Park\Actions\UnwindVehicle;
use App\Park\Actions\UpdateVehicle;
use App\Park\CaseView;
use App\Park\City;
use App\Park\Doc;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\EventType;
use App\Park\OutMoney;
use App\Park\PhotoStage;
use App\Park\Request as ParkRequest;
use App\Park\RequestType;
use App\Park\Tasks;
use App\Park\Vehicle;
use App\Park\VehicleExport;
use App\Park\VehicleFields;
use App\Park\VehicleRate;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class VehicleController
{
    /** «Дней на парковке» по убыванию — дольше всех стоят (принятые раньше) сверху; у выбывших — по дате выбытия. */
    // Поля по столбцам таблицы — заголовок сортирует нажатием (12.10.2026, владелец: «как в CRM»). «Ставка», «Начислено» и
    // «Задачи» — в памяти: таблица целиком на одной странице.
    public const SORTS = ['accepted' => ['Дата принятия', 'asc'], 'days' => ['Дней на парковке', 'desc'], 'name' => ['Марка, модель', 'asc'],
        'vendor' => ['Вендор', 'asc'], 'tasks' => ['Задачи', 'desc'], 'rate' => ['Ставка', 'desc'], 'amount' => ['Начислено', 'desc'],
        'yard' => ['Парковка', 'asc'], 'out' => ['Дата выбытия', 'desc'], 'fresh' => ['Дата заведения', 'desc']];

    /**
     * Пилюли «Наличия»: на парковке и выданы (09.10.2026 — у Питера таблица приёмов и выбытий, сверку с вендором ведут по
     * месяцу выдачи; владелец: «нет способа посмотреть тачки, которые были выданы»). Выданные — архив дел: группами по
     * месяцу выдачи, свежие сверху, свои столбцы (`x-park.table-row out`), чипы «Месяц» и «Деньги» (`OutMoney`).
     */
    // «Все» (29.10.2026) — стоящие и выданные одной книгой, как Excel управляющего: у выданной дата выдачи, строка тусклая.
    public const PRESETS = ['stored' => 'На парковке', 'out' => 'Выданы', 'all' => 'Все'];

    /**
     * «Наличие» — что стоит на парковках сейчас: только stored, пилюли по парковкам.
     * Поиск ищет по всем состояниям (выданную тоже найдёт), `?state=` — явный фильтр для ссылок дайджеста.
     */
    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($vehicle = Vehicle::find($key)) ? $this->detail($request, $vehicle) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        // «Наличие» — таблицей по умолчанию (решение владельца 22.09.2026); вид на парковке не помнится (`ListPrefs`).
        $facets = $this->facets($request);
        // Сортировка не помнится (12.10.2026, владелец: по умолчанию — по дате принятия, а у него открывалось запомненное
        // «Начислено»); «По парковкам» (`flat`) и чипы — помнятся.
        ListPrefs::sync($request, 'park-vehicles', keep: [...$facets->keys(), 'flat'], sort: false);
        if (! $request->query->has(ListView::PARAM)) {
            $request->query->set(ListView::PARAM, ListView::TABLE);
        }
        [$vehicles, $state, $q, $sort] = $this->listQuery($request, $facets);
        $out = $state === VehicleState::Released;
        $yards = $facets->selected('yard');

        // Кадры нужны плиткам и строкам; таблице — нет (миниатюр в ней нет, карточка грузит своё).
        $vehicles->when(! ListView::isTable(ListView::fromRequest($request)), fn ($v) => $v->with('media'));
        // Без выбранной парковки (или с несколькими) и без поиска строки собраны под заголовками парковок, выбывшие — под
        // месяцами выбытия. Плитками пачка — лента вбок, весь список одной страницей, как у таблицы (11.10.2026).
        $flat = $request->query('flat') === '1';
        $grouped = ! $flat && count($yards) !== 1 && $q === '' && ! $state?->isFinal() && count(City::yardIdsOf($request->user())) !== 1;
        $byMonth = $out && $q === '' && $sort->key === 'out';
        // «Лентами» (группы — ленты вбок) — весь список одной страницей; «Плитками» — одна сетка со страницами (15.10.2026).
        Rows::only($vehicles, 'park_vehicles.id');
        $page = ListView::paginate($request, $vehicles, whole: ListView::fromRequest($request, $grouped || $byMonth) === ListView::SHELF);
        // Ставка на сегодня и сколько набежало за всё время стоянки — столбцы «₽/сут» и «Начислено».
        // Приёмщику (`User::parkMoney`, 27.10.2026) денег нет: не считаются вовсе — ни столбцов, ни сумм, ни сортировки по ним.
        $money = $request->user()->parkMoney();
        $totals = $money ? Accrual::cachedTotals($page->getCollection()) : [];
        // «Больше набежало» — по посчитанному: в базе этой суммы нет. Таблица целиком на одной странице,
        // так что сортировка в памяти честная; у карточек — в пределах страницы.
        // Висящие задачи (`Park\Tasks`) — одним запросом на страницу: слово в «Статусе» и сортировка «Задачи».
        $tasks = Tasks::of($page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Vehicle $v) => $v->setAttribute('task_keys', $tasks[$v->id] ?? []));
        $order = array_flip(array_keys(Tasks::LABELS));
        $inMemory = match ($sort->key) {
            'amount' => fn (Vehicle $v) => $totals[$v->id]['amount'] ?? 0,
            'rate' => fn (Vehicle $v) => $totals[$v->id]['rate'] ?? 0,
            // Сначала с делом, важнее — выше (порядок `Tasks::LABELS`), потом по числу задач.
            'tasks' => fn (Vehicle $v) => ($tasks[$v->id] ?? []) ? 100 - $order[$tasks[$v->id][0]] * 10 + count($tasks[$v->id]) : 0,
            default => null,
        };
        if ($inMemory) {
            $page->setCollection($page->getCollection()->sortBy($inMemory, SORT_REGULAR, $sort->desc)->values());
        }
        $data = [
            'vehicles' => $page,
            'sort' => $sort,
            'debts' => $money ? Ledger::debtsByVehicle($page->pluck('id')->all()) : [],
            'money' => $money,
            'totals' => $totals,
            'grouped' => $grouped,
            'flat' => $flat,
            'byMonth' => $byMonth,
            'out' => $out,
            'q' => $q,
            'view' => ListView::pick($request, $page->total(), shelves: $grouped || $byMonth),
        ];
        // Живой поиск просит только список: тот же кусок, что рисует страницу.
        if ($request->header('X-List')) {
            return response()->view('park.vehicles.list', $data);
        }
        $yard = count($yards) === 1 && ctype_digit($yards[0]) ? Yard::find((int) $yards[0]) : null;

        return view('park.vehicles.index', $data + [
            'presets' => self::PRESETS, 'preset' => $out ? 'out' : (self::preset($request) === 'all' && $q === '' ? 'all' : 'stored'),
            'counts' => ['stored' => $stored = Vehicle::where('state', VehicleState::Stored)->count(), 'out' => $released = Vehicle::where('state', VehicleState::Released)->count(), 'all' => $stored + $released],
            'state' => $state,
            'yard' => $yard,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    /**
     * ТС, по которым ставку взять негде: нет типа, нет заявленной стоимости при тарифе по стоимости или у
     * вендора нет прайса. Пилюля «Без ставки» и её фильтр — чтобы дырки правили списком, а не по одной.
     * Считается в границах выбранной парковки, как и остальные числа в ряду пилюль.
     *
     * @return list<int>
     */
    private function noRate(Request $request, array $yards): array
    {
        $ids = Common::ints($yards);

        return Vehicle::where('state', VehicleState::Stored)
            ->when($yards, fn ($q) => $q->where(fn ($w) => $w->whereIn('yard_id', $ids)->when(in_array('none', $yards, true), fn ($n) => $n->orWhereNull('yard_id'))))
            ->with('vendor')->get(['id', 'car_id', 'vendor_id', 'yard_id', 'storage_rate'])
            ->reject(fn (Vehicle $v) => Accrual::hasRate($v))->pluck('id')->all();
    }

    /**
     * Карточка строки таблицы: фото, состояние, стоянка, клиент, сроки; действия — принять, переставить, выдать, заметка;
     * «✨» в полосе и расхождения с документами строками «Взять».
     */
    public function detail(Request $request, Vehicle $vehicle, ?ParkRequest $task = null)
    {
        $vehicle->load(['vendor', 'yard', 'media', 'requests.yard', 'events.user', 'offer.deal'])->loadCount('threads');
        // Задача сверху карточки (владелец 09.10.2026: «жмёшь заявку — „Связались“, а с кем связаться, непонятно»): открытая
        // заявка — та, что открыли в «Заявках», иначе приём, выдача; с ней письмо, по которому она заведена
        // (у выдачи — письмо о продаже).
        $fromRequests = $task !== null;
        // Строка, которую карточка обновит в «Заявках», — та заявка, что открыли (ключ строки — её номер); закрылась — уходит.
        // ТС с её связями и числом веток уже подняты выше — строке заявки догружается только своё.
        $listed = $task?->setRelation('vehicle', $vehicle)->loadMissing(['yard', 'letter', 'creator', 'deal.buyer']);
        if ($listed && ! $listed->isOpen()) {
            session()->now('detail-gone', true);
        }
        $task = $task?->isOpen() ? $task : ($vehicle->openRequest(RequestType::Intake) ?? $vehicle->openRequest(RequestType::Release));
        $letterId = $task?->type === RequestType::Release && $vehicle->sold_message_id ? $vehicle->sold_message_id : $task?->letter_id;
        // Письмо заявки — пометкой среди всех писем ТС (строки, как в «Почте»).
        $letterId ??= $task?->thread_id ? Message::where('thread_id', $task->thread_id)->where('direction', Direction::In)->orderBy('date_at')->value('id') : null;
        // Все письма ТС — и в карточке «Наличия» без заявки (11.10.2026, владелец: «в окошках очень не хватает писем»).
        $messages = Message::whereIn('thread_id', Thread::ofCar($vehicle, $request->user())->select('id'))
            ->with(['author', 'attachments', 'account'])->orderBy('date_at')->orderBy('id')->get();

        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        // «Ждут от нас» сверху карточки (19.10.2026, владелец: «непонятно, что от тебя хотят в этом окне»): письма без ответа
        // — последнее входящее ветки с `needs_reply_at`, то же правило, что шаг «Нужно ответить» в деле и почта.
        $waiting = Thread::ofCar($vehicle, $request->user())->whereNotNull('needs_reply_at')->pluck('id');
        $asks = $messages->whereIn('thread_id', $waiting)->where('direction', Direction::In)->groupBy('thread_id')->map->last()->sortBy('date_at')->values();

        return view('park.vehicles.detail', [
            'asks' => $asks, 'tasks' => Tasks::of([$vehicle->id])[$vehicle->id] ?? [],
            'vehicle' => $vehicle, 'yards' => $yards->pluck('name', 'id'),
            // Свободные места парковок — шторке «Переставить» в карточке (spots_controller); приём и выдача — окном действия.
            'yardRows' => $vehicle->state === VehicleState::Stored ? $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]) : [],
            'total' => $request->user()->parkMoney() ? (Accrual::totals([$vehicle])[$vehicle->id] ?? null) : null,
            // Из «Заявок» (заявку передали явно) главное — задача: кадры одной лентой, без блоков по стадиям над ней.
            'fromRequests' => $fromRequests, 'listed' => $listed,
            // Строка заявки, которую почта завела и документы ещё читаются, остаётся силуэтом с ходом, как в списке.
            'arrive' => $listed ? (AutoScan::states([$vehicle])[$vehicle->id] ?? null) : null,
            'task' => $task, 'messages' => $messages, 'taskLetterId' => $letterId,
            // Висящие отчёты вендору — кнопкой подвала: о приёме — окно действия сразу на письме, о выдаче — окно писем.
            'reportDue' => ! $task && ActWindowController::reportDue($vehicle),
            'releaseReportUrl' => $vehicle->state === VehicleState::Released && ActWindowController::billable($vehicle)
                && ! $vehicle->events->contains(fn ($e) => $e->type === EventType::ReportSent && ($e->payload['what'] ?? null) === 'Акт выдачи')
                ? $vehicle->reportUrl('release', "/cars/{$vehicle->id}") : null,
            // «Долг» в карточке — неоплаченные счета; набежавшее стоит рядом отдельным числом.
            'debt' => $request->user()->parkMoney() ? Ledger::vehicleDebt($vehicle) : 0,
            'debtBlocks' => ! ($vehicle->vendor?->release_without_payment ?? false),
            // «✨» и расхождения с документами — тем, кто правит ТС, и только когда письма есть.
            // Выданной сверять уже нечего: машины на парковке нет.
            'scanFiles' => ($scan = $request->user()->canManagePark() && $vehicle->threads_count > 0 && ! $vehicle->state->isFinal()) ? (new VehicleSubject($vehicle))->files() : collect(),
            'differences' => $scan ? app(FillFromDocs::class)->differences($vehicle) : [],
        ]);
    }

    /** Окно писем ТС (фрейм letters-frame): письма всех веток одной лентой, этапы — из цепочки кандидата этой ТС. */
    public function letters(Request $request, Vehicle $vehicle, MarkThreadRead $markRead)
    {
        $threads = Thread::ofCar($vehicle, $request->user())->with(['messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author'])->get();
        // Открыли со строки почты (?at=) — письма прочитаны, как у окна ветки.
        if ($request->query('at') && ! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $threads->each(fn (Thread $t) => $markRead($t));
        }

        return view('park.vehicles.letters', [
            'vehicle' => $vehicle,
            'messages' => $threads->flatMap->messages,
            'candidate' => Candidate::where('vehicle_id', $vehicle->id)->latest('id')->first(),
        ]);
    }

    /** Дело ТС одной страницей (CaseView); ?window= — открыть окно писем сразу (ветка, письма ТС или черновик вендору). */
    public function show(Request $request, Vehicle $vehicle)
    {
        $window = (string) $request->query('window');
        // Ещё не принята — это заявка: горит таб «Заявки», а не «Наличие» (адрес дела у всех ТС один).
        if ($vehicle->state->isBefore()) {
            $request->attributes->set('nav.tab', '/requests');
        }

        return view('park.vehicles.show', CaseView::for($vehicle, $request->user(), (int) $request->query('req') ?: null) + [
            'window' => preg_match('#^/(mail|cars)/#', $window) ? $window : null,
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, UpdateVehicle $update, LinkThread $link, RegisterCandidate $promote)
    {
        // Поля тождества (VehicleFields) шлёт форма дела; договор — шторка «Договор». Одна дверь на обе.
        VehicleFields::clean($request);
        $data = $request->validate(VehicleFields::rules(identity: $request->hasAny(['brand_id', 'model_id', 'ref', 'vin', 'plate'])) + [
            'contract_kind' => ['nullable', Rule::in(['storage', 'commission'])], 'contract_no' => ['nullable', 'string', 'max:60'], 'contract_at' => ['nullable', 'date'], 'assigned_price' => ['nullable', 'integer', 'min:0'],
            'owner_party_id' => ['nullable', 'exists:billing_parties,id'], 'storage_rate' => ['nullable', 'numeric', 'min:0'], 'storage_rate_note' => ['nullable', 'string', 'max:120'], 'billing_cadence' => ['nullable', Rule::enum(Cadence::class)],
            'back' => ['nullable', 'string', 'max:200', 'regex:#^/(?!/)#'],
        ]);
        $back = $data['back'] ?? null;
        unset($data['back']);
        // Приёмщик правит всё, кроме денег (30.10.2026): ставка, договор и счёт — управляющему (`User::parkMoney`);
        // оценочная стоимость — данные машины, её правят все.
        if (! $request->user()->parkMoney()) {
            $data = array_diff_key($data, array_flip(['storage_rate', 'storage_rate_note', 'billing_cadence', 'contract_kind', 'contract_no', 'contract_at', 'assigned_price', 'owner_party_id']));
        }
        $update($vehicle, $data, $request->user());
        // Вписали номер, VIN или госномер — письма с ними находят дело, а цепочка о той же ТС уходит из «Из писем».
        $link->forVehicle($vehicle->refresh());
        $promote->forVehicle($vehicle);

        return redirect($back ?: "/cars/{$vehicle->id}")->with('toast', 'Сохранено');
    }

    /** Поправить день приёма и выдачи — шторка «Даты» из карточки строки и дела (`SetDates`). */
    public function dates(Request $request, Vehicle $vehicle, SetDates $dates)
    {
        $data = $request->validate(['accepted_at' => ['nullable', 'date'], 'released_at' => ['nullable', 'date']]);
        // Дата — кнопка в строке, полей под ней нет: отказ — тостом, а не ошибкой у поля.
        try {
            $dates($vehicle, $request->user(), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, isset($data['released_at']) ? Carbon::parse($data['released_at']) : null);
        } catch (ValidationException $e) {
            return back()->with('toast', collect($e->errors())->flatten()->first());
        }

        return back()->with('toast', isset($data['released_at']) ? 'Дата выдачи поправлена' : 'Дата приёма поправлена');
    }

    /** Страховая продала ТС: дата и кому выдать — руками или поправить то, что вынул разбор письма. `clear` — не продано. */
    public function sold(Request $request, Vehicle $vehicle, MarkSold $sold)
    {
        // Кто заберёт — и приёмщик (покупатель приехал на ворота); снять выдачу и срок за счёт вендора — управляющий.
        abort_unless($request->user()->parkHandles(), 404);
        if ($request->boolean('clear')) {
            abort_unless($request->user()->canManagePark(), 403);
            // Заявка на выдачу, которую завело «продано», снимается вместе с продажей.
            $sold->clear($vehicle, $request->user(), 'Выдача снята');
            $vehicle->log(EventType::Updated, $request->user(), ['fields' => ['sold_at']]);

            return redirect("/cars/{$vehicle->id}")->with('toast', 'Выдача снята');
        }
        $data = $request->validate(['sold_at' => ['required', 'date'], 'pickup_name' => ['nullable', 'string', 'max:120'], 'pickup_phone' => ['nullable', 'string', 'max:20'],
            'buyer_free_until' => ['nullable', 'date', 'after_or_equal:sold_at']]);
        // Телефон — тем же видом, что из письма: «+7 900 123-45-67», а не набранные подряд цифры.
        $phone = ! empty($data['pickup_phone']) ? Phone::format(Phone::normalize($data['pickup_phone']) ?? $data['pickup_phone']) : null;
        $sold($vehicle, $request->user(), Carbon::parse($data['sold_at']), $data['pickup_name'] ?? null, $phone);
        // Срок хранения за счёт вендора приходит письмом и живёт только руками: пустое поле его снимает.
        if ($vehicle->vendor?->buyer_pays_late && $request->user()->parkMoney()) {
            $vehicle->update(['buyer_free_until' => $data['buyer_free_until'] ?? null]);
        }

        return redirect("/cars/{$vehicle->id}")->with('toast', 'На выдачу');
    }

    public function upload(Request $request, Vehicle $vehicle, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');
        $papers = $request->input('collection') === 'papers';
        // Кадр ложится в ту карточку, откуда его добавили; снято в приложении — `source: app`, из письма кадры приносит импорт ветки.
        $stage = PhotoStage::tryFrom((string) $request->input('stage')) ?? PhotoStage::Intake;
        try {
            if ($papers) {
                $vehicle->addMedia($file)->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $file->getClientOriginalName()) ?: 'dokument')->toMediaCollection('papers');
            } else {
                // Глаз у ТС — показ в продаже: скрыт, только если продажа уже опубликована (`Hidden::incoming`).
                $ingest->fromPhone($vehicle, 'photos', $request, properties: ['stage' => $stage->value, 'source' => 'app'] + Hidden::incoming($vehicle));
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $papers ? $this->papers($vehicle) : $this->gallery($vehicle, $stage->value);
    }

    public function reorder(Request $request, Vehicle $vehicle)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        $photos = $vehicle->photos();
        $ids = array_values(array_intersect($order, $photos->pluck('id')->all()));
        if (! $ids) {
            return $this->gallery($vehicle);
        }
        // Карточка отдаёт порядок только своей стадии: её кадры встают на свои же места в общей ленте, остальные
        // не двигаются — иначе перестановка в одной карточке переставила бы всю галерею и сменила главный кадр ТС.
        Media::setNewOrder(PhotoBlocks::order($photos->pluck('id')->all(), $ids));

        // Переставляли внутри одной карточки — ей и возвращаем кнопку камеры.
        return $this->gallery($vehicle, PhotoStage::of($photos->firstWhere('id', $ids[0]))->value);
    }

    /** Файл машины этой ТС (`Cars\Car`): кадры и документы у ТС и её продажи одни. */
    private function owns(Vehicle $vehicle, Media $media): bool
    {
        return $media->model_type === Car::class && $media->model_id === $vehicle->car_id;
    }

    /** Глаз: показывать кадр в продаже или нет. В деле ТС видны все. */
    public function hideMedia(Vehicle $vehicle, Media $media)
    {
        abort_unless($this->owns($vehicle, $media) && $media->collection_name === 'photos', 404);
        Hidden::toggle($media);

        return $this->gallery($vehicle, PhotoStage::of($media)->value);
    }

    /** «Показать все» / «Скрыть все» — кадры карточки стадии, без стадии (карточка строки) — все. */
    public function visibility(Request $request, Vehicle $vehicle)
    {
        $stage = PhotoStage::tryFrom((string) $request->input('stage'));
        $photos = $vehicle->photos()->filter(fn ($m) => ! $stage || PhotoStage::of($m) === $stage);
        Hidden::set($photos, $request->boolean('hidden'));

        return $this->gallery($vehicle, $stage?->value);
    }

    public function rotateMedia(Request $request, Vehicle $vehicle, Media $media, RotatePhoto $rotate)
    {
        abort_unless($this->owns($vehicle, $media), 404);
        $rotate($media, RotatePhoto::turns($request));

        return $media->collection_name === 'photos' ? $this->gallery($vehicle, PhotoStage::of($media)->value) : $this->papers($vehicle);
    }

    public function destroyMedia(Vehicle $vehicle, Media $media)
    {
        abort_unless($this->owns($vehicle, $media), 404);
        // У документа стадии нет: его удаление обновляет «Документы», а карточки кадров не трогает — иначе
        // читающая карточка «Фото от страховой» вернулась бы с плиткой камеры.
        $photo = $media->collection_name === 'photos' ? PhotoStage::of($media) : null;
        $media->delete();

        return $photo ? $this->gallery($vehicle, $photo->value) : $this->papers($vehicle);
    }

    public function note(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        $vehicle->log(EventType::Note, $request->user(), ['text' => $data['text']]);

        return back()->with('toast', 'Записано');
    }

    /**
     * «Сделано» у письма, которое ждало ответа (осмотр, бумаги, вопрос): вопрос закрыт без письма — позвонили.
     * Метка ложится на ветку (`answered_at`), и её видят одинаково дело, почта и лента; новое письмо вендора
     * снова откроет вопрос (`Threads::refresh` сравнивает даты).
     */
    public function letterDone(Request $request, Vehicle $vehicle, Message $message, Threads $threads)
    {
        abort_unless($message->thread?->vehicle_id === $vehicle->id, 404);
        $threads->answered($message->thread, $request->user());
        app(MarkThreadRead::class)($message->thread);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Сделано');
    }

    /** Бумага вендору: состояние с датой, сканом и письмом. Новая бумага — та же форма без {doc}. */
    public function doc(Request $request, Vehicle $vehicle, MarkDoc $mark, ?Doc $doc = null)
    {
        $data = $request->validate([
            'kind' => [$doc ? 'nullable' : 'required', Rule::enum(DocKind::class)], 'direction' => ['nullable', Rule::in(['out', 'in'])],
            'state' => ['required', Rule::enum(DocState::class)], 'at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:255'],
            'thread_id' => ['nullable', 'exists:mail_threads,id'], 'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,doc,docx'],
        ]);
        $doc ??= Doc::firstOrCreate(['vehicle_id' => $vehicle->id, 'kind' => $data['kind'], 'direction' => $data['direction'] ?? 'out']);
        abort_unless($doc->vehicle_id === $vehicle->id, 404);
        $mediaId = null;
        if ($file = $request->file('file')) {
            $mediaId = $vehicle->addMedia($file)->usingFileName(OfferPhotoController::safeName($file->getClientOriginalName()))->withCustomProperties(['kind' => 'act', 'doc' => $doc->kind->value])->toMediaCollection('papers')->id;
        }
        $mark($doc, $request->user(), DocState::from($data['state']), isset($data['at']) ? Carbon::parse($data['at']) : null, $mediaId, $data['thread_id'] ?? null, $data['note'] ?? null);

        return back()->with('toast', DocState::from($data['state'])->label());
    }

    public function cancel(Request $request, Vehicle $vehicle, CancelVehicle $cancel)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $cancel($vehicle, $request->user(), $data['reason'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Не привезена');
    }

    /**
     * «Отменить заявку» до приёма: ТС и заявки исчезают, письмо возвращается в «Из писем».
     */
    public function destroy(Request $request, Vehicle $vehicle, UnwindVehicle $unwind)
    {
        $stored = $vehicle->state === VehicleState::Stored;
        $candidate = $unwind($vehicle, $request->user());

        return $candidate
            ? redirect('/requests/from-mail')->with('toast', $stored ? 'Заведение отменено, письма снова в «Из писем»' : 'Заявка отменена, письмо снова в «Из писем»')
            : redirect('/requests')->with('toast', $stored ? 'Заведение отменено' : 'Заявка отменена');
    }

    /** «В продажу»: черновик предложения этой ТС в CRM; чип в шапке становится ссылкой на него. */
    /**
     * «В продажу» шторкой (13.10.2026, владелец: «админу важно понимать, что закупочная считается корректно»): оценочная —
     * поправить можно, закупочная — по правилу от оценочной (`Pricing::floorFrom`), равна оценочной или своя.
     */
    public function sell(Request $request, Vehicle $vehicle, PutOnSale $sell, UpdateVehicle $updateVehicle, UpdateOffer $updateOffer)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:20'],
            'floor_mode' => ['nullable', 'in:rule,value,own'],
            'floor' => ['nullable', 'string', 'max:20'],
        ]);
        $rubles = fn (?string $v) => Money::rubles($v) ?: null;
        $value = $rubles($data['value'] ?? null);
        $mode = $data['floor_mode'] ?? null;
        $floor = match ($mode) {
            'rule' => $value ? Pricing::floorFrom($value) : null,
            'value' => $value,
            'own' => $rubles($data['floor'] ?? null),
            default => null,
        };
        if ($mode && ! $floor) {
            throw ValidationException::withMessages([$mode === 'own' ? 'floor' : 'value' => $mode === 'own' ? 'Впишите закупочную' : 'Впишите оценочную']);
        }
        $by = $request->user();
        if ($value && $value !== (int) $vehicle->value) {
            $updateVehicle($vehicle, ['value' => $value], $by);
        }
        $offer = $sell($vehicle->refresh(), $by);
        if ($floor && (int) $offer->floor_price !== $floor) {
            $offer = $updateOffer($offer, ['floor_price' => $floor], $by, ['why' => 'В продажу с парковки']);
        }

        return back()->with('toast', $floor ? 'В продажу за '.Money::rub($floor).', черновик в CRM' : 'Черновик продажи в CRM');
    }

    /** «Убрать из продажи»: черновик удаляется, опубликованное — в архив; ТС с файлами остаётся (`TakeOffSale`). */
    public function unsell(Request $request, Vehicle $vehicle, TakeOffSale $takeOff)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $takeOff($vehicle, $request->user());

        return back()->with('toast', 'Снята с продажи');
    }

    public function move(Request $request, Vehicle $vehicle, Move $move)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        $vehicle = $move($vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null);

        return back()->with('toast', $vehicle->spot ? 'Переставлена на '.$vehicle->spot : 'Переставлена');
    }

    /** Обмен местами со схемы парковки: нажали ТС, потом занятое место (`SwapSpots`). */
    public function swap(Request $request, Vehicle $vehicle, SwapSpots $swap)
    {
        $data = $request->validate(['with' => ['required', 'integer']]);
        $other = Vehicle::findOrFail($data['with']);
        $swap($vehicle, $other, $request->user());

        return back()->with('toast', 'Поменялись местами');
    }

    /** Ставка машины одной шторкой (`x-park.rate`): тип ТС, своя ставка, почему, оценочная (`VehicleRate`). */
    public function rate(Request $request, Vehicle $vehicle, UpdateVehicle $update)
    {
        $update($vehicle, VehicleRate::from($request), $request->user());
        $shown = VehicleRate::shown($vehicle->refresh());

        return back()->with('toast', $shown['text'] ? 'Ставка '.$shown['text'] : 'Сохранено, ставки нет');
    }

    /** Перевод в другой город (`Transfer`): после него управляющий прежнего города ТС не видит — назад в «Наличие». */
    public function transfer(Request $request, Vehicle $vehicle, Transfer $transfer)
    {
        $data = $request->validate(['to' => ['required', Rule::in(array_keys(City::transferTargets($vehicle)))], 'day' => ['required', 'date', 'before_or_equal:today']]);
        [$kind, $id] = explode(':', $data['to']);
        $yard = $kind === 'y' ? Yard::withoutGlobalScope(City::SCOPE)->findOrFail((int) $id) : null;
        $vehicle = $transfer($vehicle, $request->user(), $yard, $kind === 'c' ? (int) $id : null, Carbon::parse($data['day']));
        $toast = 'Переведена: '.City::name($vehicle->city_id);

        return Vehicle::whereKey($vehicle->id)->exists() ? back()->with('toast', $toast) : redirect('/cars')->with('toast', $toast);
    }

    /** «Это Санкт-Петербург»: город ТС, которой система не уверена (`SetCity`). Отвечает туда же — карточка или дело. */
    public function city(Request $request, Vehicle $vehicle, SetCity $setCity)
    {
        $data = $request->validate(['city' => ['required', 'integer']]);
        $setCity($vehicle, $request->user(), (int) $data['city']);

        return back()->with('toast', 'Город: '.City::name((int) $data['city']));
    }

    /** Парковка у ТС, заведённой без неё: со дня приёма, не перестановка. Форма шага несёт и поля ТС. */
    public function yard(Request $request, Vehicle $vehicle, SetYard $setYard)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        $setYard($vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Парковка указана');
    }

    /** «Снова ждём»: отменённая ТС возвращается в ожидание с заявкой на приём. */
    public function restore(Request $request, Vehicle $vehicle, RestoreVehicle $restore)
    {
        $restore($vehicle, $request->user(), $request->input('reason'));

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Снова ждём');
    }

    public function undoIntake(Request $request, Vehicle $vehicle, UndoIntake $undo)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $undo($vehicle, $request->user(), $data['reason'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Приём отменён');
    }

    public function undoRelease(Request $request, Vehicle $vehicle, UndoRelease $undo)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $undo($vehicle, $request->user(), $data['reason'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Выдача отменена');
    }

    /** Подсказка для комбобокса: по номеру, VIN, госномеру, марке. */
    /** Чипы «Наличия»: вендор, парковка, тип ТС и «Без ставки» — одни у списка и у выгрузки. */
    private function facets(Request $request): Facets
    {
        $noRate = null;
        $facets = null;

        // Выданным — месяц выдачи и деньги вместо «Без ставки» (ставка — дело стоящих).
        if (self::preset($request) === 'out') {
            return Facets::for($request, 'park-vehicles',
                Tasks::facet(),
                Common::vendor('park_vehicles.vendor_id'),
                Common::yard('park_vehicles.yard_id'),
                Common::category(Car::sql('park_vehicles', 'category'), 'Без типа'),
                Facet::column('month', 'Месяц', ['месяц', 'месяца', 'месяцев'], "to_char(park_vehicles.released_at, 'YYYY-MM')")->natural()
                    ->labels(fn (array $keys) => collect($keys)->filter(fn ($k) => preg_match('/^\d{4}-\d{2}$/', $k))->sortDesc()
                        ->mapWithKeys(fn ($k) => [$k => new Option($k, mb_convert_case(Carbon::createFromFormat('!Y-m', $k)->translatedFormat('F Y'), MB_CASE_TITLE))])->all()),
                ...($request->user()->parkMoney() ? [Facet::column('money', 'Деньги', ['состояние', 'состояния', 'состояний'], OutMoney::sql())->natural()
                    ->labels(fn (array $keys) => collect(OutMoney::LABELS)->only($keys)->map(fn ($label, $key) => new Option($key, $label))->all())] : []),
            );
        }

        return $facets = Facets::for($request, 'park-vehicles',
            Tasks::facet(),
            Common::vendor('park_vehicles.vendor_id'),
            Common::yard('park_vehicles.yard_id'),
            Common::category(Car::sql('park_vehicles', 'category'), 'Без типа'),
            // «Без ставки» — пока такие ТС есть: у них не заполнены тип или стоимость, либо у вендора нет прайса.
            // Правят их в карточке строки. Число — в границах выбранных парковок, как и прочие числа.
            // Приёмщику ставок нет — и чипа о них тоже.
            ...($request->user()->parkMoney() ? [Facet::toggle('gap', 'Без ставки', function ($v) use ($request, &$noRate, &$facets) {
                $v->whereIn('park_vehicles.id', $noRate ??= $this->noRate($request, $facets->selected('yard')));
            })->tone('pill-danger')->counted(function () use ($request, &$noRate, &$facets) {
                return count($noRate ??= $this->noRate($request, $facets->selected('yard')));
            })] : []),
        );
    }

    /**
     * Выборка «Наличия» по адресу: пилюля (стоят или выбыли), `?state=`, поиск по всем состояниям, чипы, сортировка.
     *
     * @return array{0: Builder, 1: ?VehicleState, 2: string, 3: Sort}
     */
    private static function preset(Request $request): string
    {
        return array_key_exists((string) $request->query('preset'), self::PRESETS) ? $request->query('preset') : 'stored';
    }

    private function listQuery(Request $request, Facets $facets): array
    {
        $q = trim((string) $request->query('q'));
        $preset = self::preset($request);
        $state = VehicleState::tryFrom((string) $request->query('state')) ?? ($q !== '' || $preset === 'all' ? null : ($preset === 'out' ? VehicleState::Released : VehicleState::Stored));
        // Лента площадок и сделка — заранее: ставку и набежавшее считаем по каждой строке списка.
        $vehicles = Vehicle::withCount(['threads', 'threads as waiting_count' => fn ($t) => $t->whereNotNull('needs_reply_at')])
            ->with(['vendor', 'yard', 'offer.deal.buyer', 'requests',
                'events' => fn ($e) => $e->whereIn('type', [EventType::Accepted, EventType::Moved, EventType::Departed])])
            ->when($state, fn ($v, $s) => $v->where('state', $s))
            ->when(! $state && $q === '' && $preset === 'all', fn ($v) => $v->whereIn('state', [VehicleState::Stored, VehicleState::Released]))
            // Деньги выданной словом — в строке, карточке и выгрузке, тем же выражением, что чип «Деньги».
            ->when($state === VehicleState::Released, fn ($v) => $v->addSelect(DB::raw(OutMoney::sql().' as money_state')))
            ->search($q);
        $facets->apply($vehicles);
        $sorts = $request->user()?->parkMoney() ? self::SORTS : array_diff_key(self::SORTS, array_flip(['rate', 'amount']));
        $sort = Sort::from($request->query('sort'), $sorts, $state === VehicleState::Released ? '-out' : 'accepted');
        $dir = $sort->dir();
        match ($sort->key) {
            'fresh' => $vehicles->orderBy('created_at', $sort->dir())->orderBy('id', $sort->dir()),
            'accepted' => $vehicles->orderByRaw("accepted_at {$dir} nulls last")->orderBy('id', $dir),
            'name' => $vehicles->orderByRaw("(select b.name from cars c join brands b on b.id = c.brand_id where c.id = park_vehicles.car_id) {$dir} nulls last")
                ->orderByRaw("(select m.name from cars c join car_models m on m.id = c.model_id where c.id = park_vehicles.car_id) {$dir} nulls last")
                ->orderByRaw(Car::sql('park_vehicles', 'year')." {$dir}"),
            'vendor' => $vehicles->orderByRaw("(select vd.name from vendors vd where vd.id = park_vehicles.vendor_id) {$dir} nulls last")->orderByRaw(Car::sql('park_vehicles', 'claim_ref')." {$dir}"),
            'yard' => $vehicles->orderByRaw("(select y.name from park_yards y where y.id = park_vehicles.yard_id) {$dir} nulls last")->orderByRaw('accepted_at asc nulls last'),
            'out' => $vehicles->orderByRaw('released_at '.$sort->dir().' nulls last')->orderBy('id', $sort->dir()),
            default => $vehicles->orderByRaw('accepted_at '.($sort->key === 'days' && ! $sort->desc ? 'desc' : 'asc').' nulls last')->latest(),
        };

        return [$vehicles, $state, $q, $sort];
    }

    /**
     * «Скачать» в «Наличии»: тот же список файлом с колонками таблицы парковки — Питер вёл учёт в Excel и сверяет
     * приёмы и выбытия с вендором по месяцам (09.10.2026).
     */
    public function export(Request $request)
    {
        [$vehicles, $state] = $this->listQuery($request, $this->facets($request));
        $name = ($state === VehicleState::Released ? 'Выданы ' : 'Наличие ').now()->format('d.m.Y').'.xlsx';
        $vehicles = $vehicles->get();
        $totals = Accrual::cachedTotals($vehicles);

        return response()->streamDownload(fn () => VehicleExport::write($vehicles, 'php://output', $totals), $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function suggest(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $vehicles = Vehicle::with([])->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])
            ->search($q)
            ->latest()->limit(20)->get();

        return response()->json($vehicles->map(fn ($v) => ['id' => $v->id, 'label' => $v->titleWithYear(), 'hint' => implode(', ', array_filter([$v->ref, $v->plate, $v->vin]))]));
    }

    /**
     * Такая ТС уже есть? Номер убытка, полный VIN или госномер из формы (twins_controller) — живые ТС строками,
     * кроме этой (`except`). На разборе письма у строки «Это она»: форма открывается на найденную ТС.
     */
    public function twins(Request $request)
    {
        $ref = mb_strlen((string) $request->query('ref')) >= 4 ? Vehicle::keyFor((string) $request->query('ref')) : '';
        $plate = mb_strlen((string) $request->query('plate')) >= 6 ? Patterns::plateKey($request->query('plate')) : null;
        $vin = Vin::full((string) $request->query('vin')) ?? '';
        $vehicles = $ref === '' && $vin === '' && ! $plate ? collect() : Vehicle::with(['yard', 'media'])
            ->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])
            ->when($request->query('except'), fn ($q, $id) => $q->whereKeyNot((int) $id))
            ->whereCar(fn ($q) => $q->when($ref !== '', fn ($w) => $w->orWhere('claim_ref_key', $ref))->when($vin !== '', fn ($w) => $w->orWhere('vin', $vin))->when($plate, fn ($w) => $w->orWhere('plate', $plate)))
            ->latest()->limit(3)->get();

        return view('park.vehicles.twins', ['vehicles' => $vehicles, 'candidate' => (int) $request->query('candidate') ?: null]);
    }

    /** Карточки кадров заново; `$stage` — та, из которой пришёл запрос: только ей возвращается кнопка камеры. */
    private function gallery(Vehicle $vehicle, ?string $stage = null)
    {
        $vehicle->unsetRelation('media');

        return Stream::view('park.vehicles.photos-stream', ['vehicle' => $vehicle, 'stage' => $stage]);
    }

    /** Список документов заново: бумаги живут своей карточкой, к стадиям кадров отношения не имеют. */
    private function papers(Vehicle $vehicle)
    {
        $vehicle->unsetRelation('media');

        return Stream::view('park.vehicles.papers-stream', ['vehicle' => $vehicle]);
    }

    /**
     * Таблица «Наличия» — одно описание на шапку, строки (`x-park.table-row`, кэш `TableRows`) и заголовки парковок и
     * месяцев. Выданные — свои столбцы (архив дела); приёмщику (`User::parkMoney`) денежных нет.
     * Парковка — своим столбцом всегда (29.10.2026, управляющий вёл Excel: там место ТС в каждой строке, у нас оно было
     * заголовком группы или обрезанным хвостом названия). Подробная таблица (`wide`) — ещё и документы ТС, как его Excel и
     * выгрузка: цвет, VIN, СТС, ПТС, полис, стоимость; набор задаём мы, человек его не настраивает. Порядок — по роли
     * столбца, парковочный (`Table::park`, 30.10.2026): принята → дней → ставка → начислено вытекают друг из друга, парковка
     * — самой правой.
     */
    public static function table(bool $out, bool $money, bool $wide = false): Table
    {
        $title = new Column('title', 'Марка, модель', grow: true, sort: 'name');
        $yard = new Column('yard', 'Парковка', from: 'sm', sort: 'yard', role: ColRole::Place, rem: 8);
        $vendor = new Column('vendor', 'Вендор, № убытка', from: 'sm', sort: 'vendor', role: ColRole::Code, rem: 10);
        $accepted = new Column('accepted', 'Принята', from: 'lg', aside: true, sort: 'accepted', cell: 'nums', role: ColRole::When, rem: 6.5);
        $docs = $wide ? [new Column('vin', 'VIN', cell: 'text-sm', role: ColRole::Code, rem: 11), new Column('sts', 'СТС', cell: 'text-sm', role: ColRole::Code, rem: 7),
            new Column('pts', 'ПТС', cell: 'text-sm', role: ColRole::Code, rem: 8), new Column('policy', '№ полиса', cell: 'text-sm', role: ColRole::Code, rem: 9),
            new Column('color', 'Цвет', role: ColRole::State, rem: 6), new Column('value', 'Стоимость', num: true, cell: 'nums', rem: 6)] : [];

        return $out
            ? Table::park($title, $yard, $vendor, ...$docs, ...[$accepted, new Column('released', 'Выдана', from: 'sm', sort: 'out', cell: 'nums', role: ColRole::When, rem: 5),
                $money ? new Column('storage', 'Хранение', num: true, sort: 'amount', cell: 'nums', rem: 7) : null])
            : Table::park($title, $yard, $vendor, ...$docs, ...[new Column('status', 'Статус', from: 'sm', sort: 'tasks', role: ColRole::State, rem: 9), $accepted,
                new Column('days', 'Дней', num: true, sort: 'days', cell: 'nums', role: ColRole::When, rem: 4),
                $money ? new Column('rate', 'Ставка', from: 'sm', num: true, sort: 'rate', cell: 'nums', rem: 5.5) : null,
                $money ? new Column('amount', 'Начислено', num: true, sort: 'amount', cell: 'nums', rem: 7) : null]);
    }
}
