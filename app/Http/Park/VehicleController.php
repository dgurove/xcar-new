<?php

namespace App\Http\Park;

use App\Billing\Accrual;
use App\Billing\Cadence;
use App\Billing\Ledger;
use App\Http\Admin\OfferPhotoController;
use App\Live\Stream;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Candidate;
use App\Mail\Message;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Offers\Offer;
use App\Park\Actions\CancelVehicle;
use App\Park\Actions\CloseRequest;
use App\Park\Actions\LinkOffer;
use App\Park\Actions\MarkDoc;
use App\Park\Actions\MarkSold;
use App\Park\Actions\Move;
use App\Park\Actions\PromoteCandidate;
use App\Park\Actions\RestoreVehicle;
use App\Park\Actions\SetYard;
use App\Park\Actions\UndoIntake;
use App\Park\Actions\UndoRelease;
use App\Park\Actions\UnwindVehicle;
use App\Park\Actions\UpdateVehicle;
use App\Park\CaseView;
use App\Park\Doc;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\EventType;
use App\Park\PhotoSlot;
use App\Park\PhotoStage;
use App\Park\RequestType;
use App\Park\Scope;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class VehicleController
{
    public const SORTS = ['longest' => 'Дольше всех стоят', 'fresh' => 'Сначала новые'];

    /**
     * «Наличие» — что стоит на парковках сейчас: только stored, пилюли по парковкам.
     * Поиск ищет по всем состояниям (выданную тоже найдёт), `?state=` — явный фильтр для ссылок дайджеста.
     */
    public function index(Request $request)
    {
        // «Наличие» — таблицей по умолчанию (решение владельца 22.09.2026); выбор строк или плиток помнится.
        ListPrefs::sync($request, 'park-vehicles', rememberTable: true);
        if (! $request->query->has(ListView::PARAM)) {
            $request->query->set(ListView::PARAM, ListView::TABLE);
        }
        $q = trim((string) $request->query('q'));
        $state = VehicleState::tryFrom((string) $request->query('state')) ?? ($q === '' ? VehicleState::Stored : null);
        $yards = Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $yardId = $yards->has((int) $request->query('yard')) ? (int) $request->query('yard') : null;
        // «Без парковки» — заведены по письмам или по факту, где стоят, ещё не сказали.
        $noYard = $request->query('yard') === 'none';
        $gap = $request->query('gap') === 'rate';
        // «Без ставки» считаем, только когда она нужна: для фильтра или для пилюли на странице, но не в живом поиске.
        $noRate = $gap ? $this->noRate($request, $yardId, $noYard) : null;
        // Лента площадок и сделка — заранее: ставку и набежавшее считаем по каждой строке списка.
        $vehicles = Scope::vehicles($request->user())->withCount('threads')
            ->with(['brand', 'model', 'vendor', 'yard', 'media', 'offer.deal', 'requests',
                'events' => fn ($e) => $e->whereIn('type', [EventType::Accepted, EventType::Moved, EventType::Departed])])
            ->when($gap, fn ($v) => $v->whereIn('id', $noRate))
            ->when($state, fn ($v, $s) => $v->where('state', $s))
            ->when($yardId, fn ($v, $y) => $v->where('yard_id', $y))
            ->when($noYard, fn ($v) => $v->whereNull('yard_id'))
            ->when($request->query('vendor'), fn ($v, $id) => $v->where('vendor_id', $id))
            ->when($q !== '', fn ($v) => $v->where(fn ($w) => $w->where('ref_key', 'like', '%'.Vehicle::keyFor($q).'%')->orWhere('vin', 'like', '%'.strtoupper($q).'%')
                ->orWhere('plate', 'like', '%'.mb_strtoupper(preg_replace('/\s+/', '', $q)).'%')->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']))));
        $request->query('sort') === 'fresh' ? $vehicles->latest() : $vehicles->orderByRaw('accepted_at asc nulls last')->latest();

        $page = ListView::paginate($request, $vehicles);
        $data = [
            'vehicles' => $page,
            'debts' => Ledger::debtsByVehicle($page->pluck('id')->all()),
            // Ставка на сегодня и сколько набежало за всё время стоянки — столбцы «₽/сут» и «Начислено».
            'totals' => Accrual::totals($page->getCollection()),
            'q' => $q,
            'view' => ListView::pick($request, $page->total()),
            // ?peek=id — открыть окошко этой строки сразу: так ведут клетки карты парковки.
            'peek' => $request->query('peek') && $request->query('vid') === ListView::TABLE ? 'vehicle-'.(int) $request->query('peek') : null,
        ];
        // Живой поиск просит только список: тот же кусок, что рисует страницу.
        if ($request->header('X-List')) {
            return response()->view('park.vehicles.list', $data);
        }
        $counts = Scope::vehicles($request->user())->where('state', VehicleState::Stored)->selectRaw('yard_id, count(*) as n')->groupBy('yard_id')->pluck('n', 'yard_id');

        return view('park.vehicles.index', $data + [
            'state' => $state,
            'sort' => $request->query('sort', 'longest'),
            // Пилюли — парковки; при одной парковке пилюль нет.
            'pills' => ($yards->count() > 1 ? ['' => 'Все'] + $yards->all() : []) + ($counts->has('') ? ['none' => 'Без парковки'] : []),
            'pill' => $noYard ? 'none' : ($yardId ?? ''),
            'counts' => ['' => $counts->sum(), 'none' => $counts[''] ?? 0] + $counts->all(),
            'yard' => $yardId ? Yard::find($yardId) : null,
            // Пилюля «Без ставки» — пока такие ТС есть: считать по ним нечем, пока не заполнят тип, стоимость или прайс.
            'noRate' => count($noRate ??= $this->noRate($request, $yardId, $noYard)),
            'gap' => $gap ? 'rate' : null,
            'vendors' => Vendor::whereIn('id', Vehicle::whereNotNull('vendor_id')->distinct()->pluck('vendor_id'))->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * ТС, по которым ставку взять негде: нет типа, нет заявленной стоимости при тарифе по стоимости или у
     * вендора нет прайса. Пилюля «Без ставки» и её фильтр — чтобы дырки правили списком, а не по одной.
     * Считается в границах выбранной парковки, как и остальные числа в ряду пилюль.
     *
     * @return list<int>
     */
    private function noRate(Request $request, ?int $yardId, bool $noYard): array
    {
        return Scope::vehicles($request->user())->where('state', VehicleState::Stored)
            ->when($yardId, fn ($q, $y) => $q->where('yard_id', $y))
            ->when($noYard, fn ($q) => $q->whereNull('yard_id'))
            ->with('vendor')->get(['id', 'vendor_id', 'yard_id', 'category', 'value', 'storage_rate'])
            ->reject(fn (Vehicle $v) => Accrual::hasRate($v))->pluck('id')->all();
    }

    /** Окошко строки таблицы: фото, состояние, стоянка, клиент, сроки; действия — принять, переставить, выдать, заметка. */
    public function peek(Vehicle $vehicle)
    {
        $vehicle->load(['brand', 'model', 'vendor', 'yard', 'media', 'requests.yard', 'events.user', 'offer.deal'])->loadCount('threads');

        return view('park.vehicles.peek', [
            'vehicle' => $vehicle, 'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'total' => Accrual::totals([$vehicle])[$vehicle->id] ?? null,
            'debt' => $vehicle->state === VehicleState::Stored ? Ledger::vehicleDebt($vehicle) + Ledger::vehicleUnbilled($vehicle) : 0,
            'debtBlocks' => ! ($vehicle->vendor?->release_without_payment ?? false),
        ]);
    }

    /** Окно писем ТС (фрейм letters-frame): письма всех веток одной лентой, этапы — из цепочки кандидата этой ТС. */
    public function letters(Request $request, Vehicle $vehicle)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $threads = Thread::where('vehicle_id', $vehicle->id)->with(['messages.attachments', 'messages.addresses', 'messages.author'])->get();

        return view('park.vehicles.letters', [
            'vehicle' => $vehicle,
            'messages' => $threads->flatMap->messages,
            'candidate' => Candidate::where('vehicle_id', $vehicle->id)->latest('id')->first(),
        ]);
    }

    /** Дело ТС одной страницей (CaseView); ?window= — открыть окно писем сразу (ветка, письма ТС или черновик вендору). */
    public function show(Request $request, Vehicle $vehicle)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $window = (string) $request->query('window');

        return view('park.vehicles.show', CaseView::for($vehicle, $request->user(), (int) $request->query('req') ?: null, $request->boolean('call')) + [
            'window' => preg_match('#^/(mail|cars)/#', $window) ? $window : null,
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, UpdateVehicle $update, LinkThread $link, PromoteCandidate $promote)
    {
        // Поля тождества (VehicleFields) шлёт форма дела; договор — шторка «Договор». Одна дверь на обе.
        $data = $request->validate(VehicleFields::rules(identity: $request->hasAny(['brand_id', 'model_id', 'ref', 'vin', 'plate'])) + [
            'contract_kind' => ['nullable', Rule::in(['storage', 'commission'])], 'contract_no' => ['nullable', 'string', 'max:60'], 'contract_at' => ['nullable', 'date'], 'assigned_price' => ['nullable', 'integer', 'min:0'],
            'pts' => ['nullable', 'string', 'max:40'], 'sts' => ['nullable', 'string', 'max:40'], 'owner_party_id' => ['nullable', 'exists:billing_parties,id'], 'storage_rate' => ['nullable', 'numeric', 'min:0'], 'storage_rate_note' => ['nullable', 'string', 'max:120'], 'billing_cadence' => ['nullable', Rule::enum(Cadence::class)],
            'back' => ['nullable', 'string', 'max:200', 'regex:#^/(?!/)#'],
            'accepted_at' => ['nullable', 'date'], 'released_at' => ['nullable', 'date', 'after_or_equal:accepted_at'],
        ]);
        // Даты приёма и выдачи — только пока хранение по ним не выставлено: на них считаются сутки.
        foreach (['accepted_at', 'released_at'] as $key) {
            if (! array_key_exists($key, $data) || ! $vehicle->{$key}) {
                unset($data[$key]);

                continue;
            }
            if ($data[$key] && ! $vehicle->{$key}->equalTo(Carbon::parse($data[$key])) && $vehicle->storage_billed_until) {
                return back()->withErrors([$key => 'Хранение уже выставлено, сначала аннулируйте счёт'])->withInput();
            }
            if (! $data[$key]) {
                unset($data[$key]);
            }
        }
        $back = $data['back'] ?? null;
        unset($data['back']);
        $update($vehicle, $data, $request->user());
        // Вписали номер, VIN или госномер — письма с ними находят дело, а цепочка о той же ТС уходит из «Из писем».
        $link->forVehicle($vehicle->refresh());
        $promote->forVehicle($vehicle);

        return redirect($back ?: "/cars/{$vehicle->id}")->with('toast', 'Сохранено');
    }

    /** Страховая продала ТС: дата и кому выдать — руками или поправить то, что вынул разбор письма. `clear` — не продано. */
    public function sold(Request $request, Vehicle $vehicle, MarkSold $sold)
    {
        abort_unless(Scope::allows($request->user(), $vehicle) && $request->user()->canManagePark(), 404);
        if ($request->boolean('clear')) {
            // Заявка на выдачу, которую завело «продано», снимается вместе с продажей.
            $sold->clear($vehicle, $request->user(), 'Не продано');
            $vehicle->log(EventType::Updated, $request->user(), ['fields' => ['sold_at']]);

            return redirect("/cars/{$vehicle->id}")->with('toast', 'Не продано');
        }
        $data = $request->validate(['sold_at' => ['required', 'date'], 'pickup_name' => ['nullable', 'string', 'max:120'], 'pickup_phone' => ['nullable', 'string', 'max:20']]);
        $sold($vehicle, $request->user(), Carbon::parse($data['sold_at']), $data['pickup_name'] ?? null, $data['pickup_phone'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Продано');
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
                $slot = PhotoSlot::tryFrom((string) $request->input('slot'))?->value;
                $ingest->fromPhone($vehicle, 'photos', $request, properties: array_filter(['stage' => $stage->value, 'slot' => $slot, 'source' => 'app']));
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
        $moving = array_flip($ids);
        $queue = $ids;
        $full = [];
        foreach ($photos->pluck('id') as $id) {
            $full[] = isset($moving[$id]) ? array_shift($queue) : $id;
        }
        Media::setNewOrder($full);

        // Переставляли внутри одной карточки — ей и возвращаем кнопку камеры.
        return $this->gallery($vehicle, PhotoStage::of($photos->firstWhere('id', $ids[0]))->value);
    }

    public function rotateMedia(Vehicle $vehicle, Media $media, RotatePhoto $rotate)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
        $rotate($media);

        return $media->collection_name === 'photos' ? $this->gallery($vehicle, PhotoStage::of($media)->value) : $this->papers($vehicle);
    }

    public function destroyMedia(Vehicle $vehicle, Media $media)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
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
        $vehicle->log(EventType::LetterAnswered, $request->user(), ['message' => $message->id, 'subject' => $message->subject, 'thread' => $message->thread_id]);
        $message->thread->forceFill(['answered_at' => now()])->save();
        $threads->refresh($message->thread);
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
     * ТС в пути — сначала снять эвакуацию (ТС снова ожидается), потом отменить заведение.
     */
    public function destroy(Request $request, Vehicle $vehicle, UnwindVehicle $unwind, CloseRequest $close)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        if ($vehicle->state === VehicleState::InTransit && ($tow = $vehicle->openRequest(RequestType::Tow))) {
            $close($tow, $request->user(), false);
            $vehicle->refresh();
            // Перегон между площадками: ТС вернулась на прежнюю площадку, заводить заново нечего.
            if ($vehicle->state !== VehicleState::Expected) {
                return redirect("/cars/{$vehicle->id}")->with('toast', 'Перегон отменён');
            }
        }
        $stored = $vehicle->state === VehicleState::Stored;
        $candidate = $unwind($vehicle, $request->user());

        return $candidate
            ? redirect('/requests/from-mail')->with('toast', $stored ? 'Заведение отменено, письма снова в «Из писем»' : 'Заявка отменена, письмо снова в «Из писем»')
            : redirect('/requests')->with('toast', $stored ? 'Заведение отменено' : 'Заявка отменена');
    }

    /** Связать с предложением CRM: по номеру, руками. */
    public function link(Request $request, Vehicle $vehicle, LinkOffer $link)
    {
        $data = $request->validate(['number' => ['nullable', 'integer']]);
        if (empty($data['number'])) {
            $vehicle->update(['offer_id' => null]);

            return back()->with('toast', 'Связь снята');
        }
        $offer = Offer::where('number', $data['number'])->first();
        if (! $offer) {
            return back()->withErrors(['number' => 'Предложения № '.$data['number'].' нет']);
        }
        $linked = $link($vehicle, $offer, $request->user());

        return back()->with('toast', $linked ? 'Связана с № '.$offer->number : 'Это предложение уже связано с другой ТС');
    }

    public function move(Request $request, Vehicle $vehicle, Move $move)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        $move($vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null);

        return back()->with('toast', 'Переставлена');
    }

    /** Парковка у ТС, заведённой без неё: со дня приёма, не перестановка. Форма шага несёт и поля ТС. */
    public function yard(Request $request, Vehicle $vehicle, SetYard $setYard)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        if ($request->has('vehicle_form')) {
            app(UpdateVehicle::class)($vehicle, VehicleFields::only($request->validate(VehicleFields::rules())), $request->user());
        }
        $setYard($vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Парковка указана');
    }

    /** «Снова ждём»: отменённая ТС возвращается в ожидание с заявкой на приём. */
    public function restore(Request $request, Vehicle $vehicle, RestoreVehicle $restore)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $restore($vehicle, $request->user(), $request->input('reason'));

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Снова ждём');
    }

    public function undoIntake(Request $request, Vehicle $vehicle, UndoIntake $undo)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $undo($vehicle, $request->user(), $data['reason'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Приём отменён');
    }

    public function undoRelease(Request $request, Vehicle $vehicle, UndoRelease $undo)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $undo($vehicle, $request->user(), $data['reason'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Выдача отменена');
    }

    /** Подсказка для комбобокса: по номеру, VIN, госномеру, марке. */
    public function suggest(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $vehicles = Vehicle::with(['brand', 'model'])->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])
            ->when($q !== '', fn ($v) => $v->where(fn ($w) => $w->where('ref_key', 'like', '%'.Vehicle::keyFor($q).'%')->orWhere('vin', 'like', '%'.strtoupper($q).'%')->orWhere('plate', 'like', '%'.mb_strtoupper($q).'%')))
            ->latest()->limit(20)->get();

        return response()->json($vehicles->map(fn ($v) => ['id' => $v->id, 'label' => $v->titleWithYear(), 'hint' => implode(', ', array_filter([$v->ref, $v->plate, $v->vin]))]));
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
}
