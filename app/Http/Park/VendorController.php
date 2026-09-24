<?php

namespace App\Http\Park;

use App\Billing\Accrual;
use App\Billing\Cadence;
use App\Billing\Invoice;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Http\Admin\OfferPhotoController;
use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Template;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Vendors\ContactRole;
use App\Vendors\DocRequirement;
use App\Vendors\Kind;
use App\Vendors\Parser;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Вендоры на парковке (с 24.09.2026 — решение владельца: «настройка вендоров должна быть в park»): список, карточка
 * с пилюлями Обзор · Контакты · Тарифы · Деньги и «Изменить» шторкой — реквизиты, договор, хранение, почта, что
 * присылаем после приёма. В CRM у вендора остались только условия продажи предложений и маршруты.
 */
class VendorController
{
    public const PRESETS = ['active' => 'Работаем', 'stored' => 'С ТС на парковке', 'inactive' => 'Не работаем'];

    public const PILLS = ['overview' => 'Обзор', 'contacts' => 'Контакты', 'tariffs' => 'Тарифы', 'money' => 'Деньги'];

    public function index(Request $request)
    {
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'active';
        $q = trim((string) $request->query('q'));
        $kind = Kind::tryFrom((string) $request->query('kind'));
        $vendors = Vendor::with(['contacts', 'party'])->when($kind, fn ($w) => $w->where('kind', $kind))->withCount(['vehicles as stored_count' => fn ($q) => $q->where('state', VehicleState::Stored)])
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'ilike', "%{$q}%")->orWhere('legal_name', 'ilike', "%{$q}%")->orWhere('inn', 'like', "%{$q}%")))
            ->orderBy('name')->get();
        $counts = ['active' => $vendors->where('is_active', true)->count(), 'stored' => $vendors->where('stored_count', '>', 0)->count(), 'inactive' => $vendors->where('is_active', false)->count()];

        return view('park.vendors.index', [
            'vendors' => match ($preset) {
                'stored' => $vendors->where('stored_count', '>', 0), 'inactive' => $vendors->where('is_active', false), default => $vendors->where('is_active', true)
            },
            'preset' => $preset, 'presets' => self::PRESETS, 'counts' => $counts, 'q' => $q, 'kind' => $kind,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:vendors,name'], 'kind' => ['required', Rule::enum(Kind::class)]]);
        $vendor = Vendor::create($data);

        return redirect("/vendors/{$vendor->id}")->with('toast', 'Добавлен');
    }

    public function show(Request $request, Vendor $vendor)
    {
        $pill = array_key_exists($request->query('pill', ''), self::PILLS) ? $request->query('pill') : 'overview';
        $vendor->load(['contacts.yard', 'media', 'mailAccount']);
        $data = [
            'vendor' => $vendor,
            'pill' => $pill,
            'pills' => self::PILLS,
            'base' => "/vendors/{$vendor->id}",
            'crm' => Surface::Crm->url("/settings/vendors/{$vendor->id}"),
            'accounts' => Account::where('is_active', true)->orderBy('title')->get()->mapWithKeys(fn ($a) => [$a->id => $a->title.' ('.$a->email.')']),
            'templates' => Template::where('scope', Scope::Park)->orderBy('name')->pluck('name', 'id'),
            'docs' => DocRequirement::cases(),
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'claims' => $vendor->defaultContact(ContactRole::Storage, ContactRole::Claims),
        ];

        if ($pill === 'tariffs') {
            // Прайсу площадки нужны моделями (id и имя), а в $data они уже списком для форм — берём его версию.
            $data = self::tariffData($request, $vendor) + $data;
        } elseif ($pill === 'money') {
            // Контрагент на чтение не создаётся: без счетов долг — только не выставленное хранение по ТС вендора.
            $party = Party::forVendor($vendor, false);
            $stored = Vehicle::where('vendor_id', $vendor->id)->whereIn('state', [VehicleState::Stored, VehicleState::InTransit])->with(['brand', 'model', 'yard'])->orderBy('accepted_at')->get();
            $data += [
                'party' => $party->id ? $party : null,
                'debt' => $party->id ? Ledger::debtOf($party) : ['owed_to_us' => 0, 'we_owe' => 0, 'overdue' => 0],
                'unbilled' => $stored->mapWithKeys(fn (Vehicle $v) => [$v->id => round(collect(Accrual::storage($v))->where('payer', 'vendor')->sum('amount') + (float) $v->charges()->whereNull('invoice_id')->whereNull('voided_at')->when($party->id, fn ($q) => $q->where('party_id', $party->id))->sum('amount'), 2)]),
                'stored' => $stored,
                'invoices' => $party->id ? Invoice::where('party_id', $party->id)->with(['vehicle.brand', 'vehicle.model'])->latest('issued_at')->latest('id')->get() : collect(),
            ];
        } elseif ($pill === 'overview') {
            $data += [
                'vehicles' => Vehicle::where('vendor_id', $vendor->id)->where('state', VehicleState::Stored)->with(['brand', 'model', 'yard'])->orderBy('accepted_at')->get(),
                'contract' => $vendor->getFirstMedia('contract'),
            ];
        }

        return view('park.vendors.show', $data);
    }

    /** Прайс: базовые строки и, если есть вендор, его строки поверх; площадка — из адреса. */
    public static function tariffData(Request $request, ?Vendor $vendor): array
    {
        $yards = Yard::orderBy('name')->get();
        $yardId = $request->query('yard') ? (int) $request->query('yard') : null;
        $rows = Tariff::query()->activeOn()->where(fn ($q) => $q->whereNull('vendor_id')->when($vendor, fn ($q) => $q->orWhere('vendor_id', $vendor->id)))
            ->where(fn ($q) => $q->whereNull('yard_id')->when($yardId, fn ($q) => $q->orWhere('yard_id', $yardId)))
            ->orderBy('from_day')->orderBy('valid_from')->get();

        return [
            'yards' => $yards,
            'yardId' => $yardId,
            'rows' => $rows,
            'categories' => Category::cases(),
            'services' => TariffService::cases(),
            'tariffVendor' => $vendor,
        ];
    }

    public function update(Request $request, Vendor $vendor)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:vendors,name,'.$vendor->id],
            'kind' => ['required', Rule::enum(Kind::class)],
            'is_active' => ['boolean'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'inn' => ['nullable', 'digits_between:10,12'],
            'kpp' => ['nullable', 'digits:9'],
            'legal_address' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'digits:20'],
            'bank_corr' => ['nullable', 'digits:20'],
            'bank_bic' => ['nullable', 'digits:9'],
            'payment_purpose' => ['nullable', 'string', 'max:255'],
            'agreement_number' => ['nullable', 'string', 'max:60'],
            'agreement_date' => ['nullable', 'date'],
            'agreement_until' => ['nullable', 'date'],
            'payment_days' => ['nullable', 'integer', 'between:0,365'],
            'vat_included' => ['boolean'],
            'storage_payer' => ['required', Rule::in(['vendor', 'owner', 'nobody'])],
            'buyer_pays_late' => ['boolean'],
            'release_without_payment' => ['boolean'],
            'release_by_qr' => ['boolean'],
            'buyer_rate_multiplier' => ['required', 'numeric', 'between:0,20'],
            'billing_cadence' => ['required', Rule::enum(Cadence::class)],
            'report_template_id' => ['nullable', 'exists:mail_templates,id'],
            'refusal_template_id' => ['nullable', 'exists:mail_templates,id'],
            'senders' => ['nullable', 'string', 'max:2000'],
            'parser' => ['required', Rule::enum(Parser::class)],
            'mail_account_id' => ['nullable', 'exists:mail_accounts,id'],
            'intake_docs' => ['nullable', 'array'],
            'intake_docs.*' => [Rule::enum(DocRequirement::class)],
            'intake_note' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $senders = Vendor::parseSenders($data['senders'] ?? null);
        foreach ($senders as $sender) {
            $other = Vendor::whereJsonContains('senders', $sender)->where('id', '!=', $vendor->id)->first();
            if ($other) {
                return back()->withInput()->withErrors(['senders' => "{$sender} уже у «{$other->name}»"]);
            }
        }
        $vendor->update(array_merge($data, [
            'senders' => $senders,
            'intake_docs' => array_values($data['intake_docs'] ?? []),
            'is_active' => $request->boolean('is_active'),
            'vat_included' => $request->boolean('vat_included'),
            'buyer_pays_late' => $request->boolean('buyer_pays_late'),
            'release_without_payment' => $request->boolean('release_without_payment'),
            'release_by_qr' => $request->boolean('release_by_qr'),
        ]));

        return back()->with('toast', 'Сохранено');
    }

    public function contract(Request $request, Vendor $vendor)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx']]);
        $vendor->clearMediaCollection('contract');
        $vendor->addMediaFromRequest('file')->usingFileName(OfferPhotoController::safeName($request->file('file')->getClientOriginalName()))->toMediaCollection('contract');

        return back()->with('toast', 'Договор приложен');
    }

    public function dropContract(Vendor $vendor)
    {
        $vendor->clearMediaCollection('contract');

        return back()->with('toast', 'Договор убран');
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->offers()->exists() || $vendor->vehicles()->exists()) {
            return back()->withErrors(['vendor' => 'У вендора есть предложения или ТС на парковке']);
        }
        $vendor->delete();

        return redirect('/vendors')->with('toast', 'Удалён');
    }
}
