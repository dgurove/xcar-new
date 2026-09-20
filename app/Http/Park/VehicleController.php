<?php

namespace App\Http\Park;

use App\Billing\Accrual;
use App\Billing\Cadence;
use App\Billing\ChargeKind;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Cars\DamageZone;
use App\Http\Admin\OfferPhotoController;
use App\Live\Stream;
use App\Mail\Scope as MailScope;
use App\Mail\Template;
use App\Mail\Thread;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Offers\Offer;
use App\Park\Actions\CancelVehicle;
use App\Park\Actions\DestroyVehicle;
use App\Park\Actions\LinkOffer;
use App\Park\Actions\MarkDoc;
use App\Park\Actions\MarkSold;
use App\Park\Actions\Move;
use App\Park\Actions\Release;
use App\Park\Actions\UpdateVehicle;
use App\Park\Doc;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\EventType;
use App\Park\Idle;
use App\Park\PhotoSlot;
use App\Park\ReleasedTo;
use App\Park\Scope;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class VehicleController
{
    public const PRESETS = ['stored' => 'На стоянке', 'idle' => 'Стоят долго', 'expected' => 'Ожидаются', 'in_transit' => 'В пути', 'released' => 'Выданы', 'cancelled' => 'Не привезены', 'all' => 'Все'];

    public const SORTS = ['longest' => 'Дольше всех стоят', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'park-vehicles');
        $preset = $request->query('preset', 'stored');
        $q = trim((string) $request->query('q'));
        $vehicles = Scope::vehicles($request->user())->with(['brand', 'model', 'vendor', 'yard', 'media', 'offer'])
            ->when(VehicleState::tryFrom($preset), fn ($v, $s) => $v->where('state', $s))
            ->when($preset === 'idle', fn ($v) => $v->where('state', VehicleState::Stored)->where('accepted_at', '<=', now()->subDays(Idle::warn())->startOfDay()))
            ->when($request->query('docs') === 'due', fn ($v) => $v->whereHas('docs', fn ($d) => $d->where('direction', 'out')->where('state', 'pending')))
            ->when($request->query('yard'), fn ($v, $y) => $v->where('yard_id', $y))
            ->when($request->query('vendor'), fn ($v, $id) => $v->where('vendor_id', $id))
            ->when($q !== '', fn ($v) => $v->where(fn ($w) => $w->where('ref_key', 'like', '%'.Vehicle::keyFor($q).'%')->orWhere('vin', 'like', '%'.strtoupper($q).'%')
                ->orWhere('plate', 'like', '%'.mb_strtoupper(preg_replace('/\s+/', '', $q)).'%')->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']))));
        $request->query('sort') === 'fresh' ? $vehicles->latest() : $vehicles->orderByRaw('accepted_at asc nulls last')->latest();

        return view('park.vehicles.index', [
            'vehicles' => ListView::paginate($request, $vehicles),
            'preset' => $preset,
            'q' => $q,
            'sort' => $request->query('sort', 'longest'),
            'counts' => Scope::vehicles($request->user())->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all()
                + ['idle' => Scope::vehicles($request->user())->where('state', VehicleState::Stored)->where('accepted_at', '<=', now()->subDays(Idle::warn())->startOfDay())->count()],
            'yard' => $request->query('yard') ? Yard::find($request->query('yard')) : null,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'vendors' => Vendor::whereIn('id', Vehicle::whereNotNull('vendor_id')->distinct()->pluck('vendor_id'))->orderBy('name')->pluck('name', 'id'),
            'docsDue' => Scope::vehicles($request->user())->whereHas('docs', fn ($d) => $d->where('direction', 'out')->where('state', 'pending'))->count(),
            // ?peek=id — открыть окошко этой строки сразу: так ведут клетки карты площадки.
            'peek' => $request->query('peek') && $request->query('vid') === ListView::TABLE ? 'vehicle-'.(int) $request->query('peek') : null,
        ]);
    }

    /** Окошко строки таблицы: фото, состояние, стоянка, клиент, сроки; действия — принять, переставить, выдать, заметка. */
    public function peek(Vehicle $vehicle)
    {
        $vehicle->load(['brand', 'model', 'vendor', 'yard', 'media', 'requests.yard', 'events.user']);

        return view('park.vehicles.peek', [
            'vehicle' => $vehicle, 'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'debt' => $vehicle->state === VehicleState::Stored ? Ledger::vehicleDebt($vehicle) + Ledger::vehicleUnbilled($vehicle) : 0,
            'debtBlocks' => ! ($vehicle->vendor?->release_without_payment ?? false),
        ]);
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        abort_unless(Scope::allows($request->user(), $vehicle), 404);
        $vehicle->load(['brand', 'model', 'vendor.contacts', 'yard', 'media', 'requests.yard', 'events.user', 'inspections.user', 'docs.media', 'docs.thread', 'offer', 'invoices.party']);

        return view('park.vehicles.show', [
            'vehicle' => $vehicle,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'vendors' => Vendor::where('is_active', true)->orWhere('id', $vehicle->vendor_id)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'storageRate' => Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
            'accrued' => Accrual::summary($vehicle),
            'buyerFrom' => $vehicle->sold_at ? Accrual::buyerFrom($vehicle) : null,
            'buyerRate' => $vehicle->sold_at ? Accrual::buyerRate($vehicle) : 0,
            'owners' => Party::where('kind', 'person')->orderBy('name')->pluck('name', 'id'),
            'debt' => Ledger::vehicleDebt($vehicle),
            'buyerDebt' => Ledger::buyerDebt($vehicle),
            'payers' => Ledger::payersOf($vehicle),
            'pendingCharges' => $vehicle->charges()->whereNull('invoice_id')->whereNull('voided_at')->get(),
            'chargeKinds' => collect([ChargeKind::Tow, ChargeKind::Inspection, ChargeKind::Idle, ChargeKind::Loading, ChargeKind::Release, ChargeKind::Other])->mapWithKeys(fn ($k) => [$k->value => $k->label().(($price = VehicleInvoiceController::priceFor($vehicle, $k)) ? ' — '.Money::rub($price) : '')]),
            'threads' => Thread::where('vehicle_id', $vehicle->id)->with('messages')->orderByDesc('last_message_at')->get(),
            'templates' => Template::where('scope', MailScope::Park)->orderBy('name')->get(),
            'zones' => DamageZone::cases(),
            'spots' => $vehicle->yard?->freeSpots() ?? [],
            'offerGuess' => $vehicle->offer_id ? null : LinkOffer::guess($vehicle),
            'canManage' => $request->user()->canManagePark(),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, UpdateVehicle $update)
    {
        $data = $request->validate([
            'ref' => ['nullable', 'string', 'max:60'], 'vin' => ['nullable', 'string', 'max:17'], 'plate' => ['nullable', 'string', 'max:12'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)], 'color' => ['nullable', 'string', 'max:32'],
            'brand_id' => ['nullable', 'exists:brands,id'], 'model_id' => ['nullable', 'exists:car_models,id'], 'vendor_id' => ['nullable', 'exists:vendors,id'],
            'category' => ['nullable', Rule::enum(Category::class)], 'oversize' => ['boolean'],
            'contact_name' => ['nullable', 'string', 'max:80'], 'contact_phone' => ['nullable', 'string', 'max:20'], 'value' => ['nullable', 'integer', 'min:0'],
            'contract_kind' => ['nullable', Rule::in(['storage', 'commission'])], 'contract_no' => ['nullable', 'string', 'max:60'], 'contract_at' => ['nullable', 'date'], 'assigned_price' => ['nullable', 'integer', 'min:0'],
            'pts' => ['nullable', 'string', 'max:40'], 'sts' => ['nullable', 'string', 'max:40'], 'owner_party_id' => ['nullable', 'exists:billing_parties,id'], 'storage_rate' => ['nullable', 'numeric', 'min:0'], 'storage_rate_note' => ['nullable', 'string', 'max:120'], 'billing_cadence' => ['nullable', Rule::enum(Cadence::class)],
            'damage_zones' => ['nullable', 'array'], 'damage_zones.*' => [Rule::enum(DamageZone::class)], 'damage_note' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'back' => ['nullable', 'string', 'max:200', 'regex:#^/(?!/)#'],
        ]);
        // Компактная карточка ТС на заявке шлёт только свои поля: галочки повреждений и негабарит трогаем,
        // лишь когда форма их присылала (`damage_form`, `oversize_form`).
        if ($request->has('damage_form')) {
            $data['damage_zones'] = $data['damage_zones'] ?? [];
        } else {
            unset($data['damage_zones']);
        }
        if ($request->has('oversize_form')) {
            $data['oversize'] = $request->boolean('oversize');
        }
        $back = $data['back'] ?? null;
        unset($data['back']);
        $update($vehicle, $data, $request->user());

        return redirect($back ?: "/cars/{$vehicle->id}")->with('toast', 'Сохранено');
    }

    /** Страховая продала ТС: дата и кому выдать — руками или поправить то, что вынул разбор письма. `clear` — не продано. */
    public function sold(Request $request, Vehicle $vehicle, MarkSold $sold)
    {
        abort_unless(Scope::allows($request->user(), $vehicle) && $request->user()->canManagePark(), 404);
        if ($request->boolean('clear')) {
            $vehicle->update(['sold_at' => null, 'sold_message_id' => null, 'pickup_name' => null, 'pickup_phone' => null, 'pickup_note' => null]);
            $vehicle->log(EventType::Updated, $request->user(), ['fields' => ['sold_at']]);

            return redirect("/cars/{$vehicle->id}")->with('toast', 'Не продано');
        }
        $data = $request->validate(['sold_at' => ['required', 'date'], 'pickup_name' => ['nullable', 'string', 'max:120'], 'pickup_phone' => ['nullable', 'string', 'max:20'], 'pickup_note' => ['nullable', 'string', 'max:255']]);
        $sold($vehicle, $request->user(), Carbon::parse($data['sold_at']), $data['pickup_name'] ?? null, $data['pickup_phone'] ?? null, $data['pickup_note'] ?? null);

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Продано');
    }

    public function upload(Request $request, Vehicle $vehicle, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');
        try {
            if ($request->input('collection') === 'papers') {
                $vehicle->addMedia($file)->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $file->getClientOriginalName()) ?: 'dokument')->toMediaCollection('papers');
            } else {
                // Без стадии — снято на стоянке; «из письма» ставит только импорт ветки.
                $stage = in_array($request->input('stage'), ['intake', 'release', 'pickup', 'storage'], true) ? $request->input('stage') : 'storage';
                $slot = PhotoSlot::tryFrom((string) $request->input('slot'))?->value;
                $ingest->fromPhone($vehicle, 'photos', $request, properties: array_filter(['stage' => $stage, 'slot' => $slot]));
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($vehicle, $request->input('stage'));
    }

    public function reorder(Request $request, Vehicle $vehicle)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($order, $vehicle->photos()->pluck('id')->all())));

        return $this->gallery($vehicle);
    }

    public function rotateMedia(Vehicle $vehicle, Media $media, RotatePhoto $rotate)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
        $rotate($media);

        return $this->gallery($vehicle);
    }

    public function destroyMedia(Vehicle $vehicle, Media $media)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
        $media->delete();

        return $this->gallery($vehicle);
    }

    public function note(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        $vehicle->log(EventType::Note, $request->user(), ['text' => $data['text']]);

        return back()->with('toast', 'Записано');
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

    public function destroy(Vehicle $vehicle, DestroyVehicle $destroy)
    {
        $destroy($vehicle);

        return redirect('/cars?preset=expected')->with('toast', 'Удалена');
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

    public function release(Request $request, Vehicle $vehicle, Release $release)
    {
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000'], 'to' => ['nullable', Rule::enum(ReleasedTo::class)]]);
        $release($vehicle, $request->user(), isset($data['released_at']) ? Carbon::parse($data['released_at']) : null, $data['note'] ?? null, ReleasedTo::tryFrom($data['to'] ?? ''), force: $request->boolean('force'), cash: $request->boolean('cash'));

        return back()->with('toast', 'Выдана');
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

    private function gallery(Vehicle $vehicle, ?string $stage = null)
    {
        $vehicle->unsetRelation('media');

        return Stream::view('park.vehicles.gallery-stream', ['vehicle' => $vehicle, 'stage' => $stage]);
    }
}
