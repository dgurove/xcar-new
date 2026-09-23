<?php

namespace App\Http\Admin;

use App\Billing\Accrual;
use App\Billing\Cadence;
use App\Billing\Invoice;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Vendors\DealFormat;
use App\Vendors\DocRequirement;
use App\Vendors\Kind;
use App\Vendors\Parser;
use App\Vendors\RewardKind;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController
{
    public const PILLS = ['overview' => 'Обзор', 'routes' => 'Маршруты', 'contacts' => 'Контакты', 'tariffs' => 'Тарифы', 'money' => 'Деньги'];

    public function index(Request $request)
    {
        $kind = Kind::tryFrom($request->query('kind', ''));
        $off = $request->boolean('off');
        $q = Vendor::with('workflows')->withCount(['offers', 'vehicles as stored_count' => fn ($q) => $q->where('state', VehicleState::Stored)])
            ->orderByDesc('is_active')->orderBy('name');
        if ($off) {
            $q->where('is_active', false);
        } elseif ($kind) {
            $q->where('kind', $kind)->where('is_active', true);
        }

        return view('admin.vendors.index', [
            'vendors' => $q->get(),
            'kind' => $kind,
            'off' => $off,
            'counts' => Vendor::where('is_active', true)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'offCount' => Vendor::where('is_active', false)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:vendors,name'],
            'kind' => ['nullable', Rule::enum(Kind::class)],
        ]);
        $vendor = Vendor::create($data);

        return redirect("/settings/vendors/{$vendor->id}");
    }

    public function show(Request $request, Vendor $vendor)
    {
        $pill = array_key_exists($request->query('pill', ''), self::PILLS) ? $request->query('pill') : 'overview';
        $vendor->load(['contacts.yard', 'media']);
        $data = [
            'vendor' => $vendor,
            'pill' => $pill,
            'pills' => self::PILLS,
            'base' => "/settings/vendors/{$vendor->id}",
            'accounts' => Account::where('is_active', true)->orderBy('title')->get()->mapWithKeys(fn ($a) => [$a->id => $a->title.' ('.$a->email.')']),
            'templates' => Template::where('scope', Scope::Park)->orderBy('name')->pluck('name', 'id'),
            'docs' => DocRequirement::cases(),
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ];

        if ($pill === 'routes') {
            $track = Track::tryFrom($request->query('track', '')) ?? Track::Sale;
            $workflow = $vendor->workflowOrNew($track);
            $workflow->load(['blocks.stages.exits.to', 'blocks.stages.block']);
            $data += [
                'track' => $track,
                'workflow' => $workflow,
                'problems' => $workflow->problems(),
                'occupied' => Position::whereIn('stage_id', $workflow->stages()->pluck('workflow_stages.id'))
                    ->selectRaw('stage_id, count(*) as n')->groupBy('stage_id')->pluck('n', 'stage_id'),
            ];
        } elseif ($pill === 'tariffs') {
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
        } else {
            $data += [
                'vehicles' => Vehicle::where('vendor_id', $vendor->id)->where('state', VehicleState::Stored)->with(['brand', 'model', 'yard.settlement'])->orderBy('accepted_at')->get(),
                'offers' => Offer::where('vendor_id', $vendor->id)->whereNotIn('state', [OfferState::Archived])->with(['brand', 'model'])->latest()->limit(12)->get(),
                'offersTotal' => Offer::where('vendor_id', $vendor->id)->count(),
                'contract' => $vendor->getFirstMedia('contract'),
            ];
        }

        return view('admin.vendors.show', $data);
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
            'deal_format' => ['required', Rule::enum(DealFormat::class)],
            'reward_kind' => ['nullable', Rule::enum(RewardKind::class)],
            'reward_value' => ['nullable', 'integer', 'min:0'],
            'payment_days' => ['nullable', 'integer', 'between:0,365'],
            'vat_included' => ['boolean'],
            'answer_hours' => ['nullable', 'integer', 'between:1,720'],
            'silence_means_buy' => ['boolean'],
            'binding_days' => ['nullable', 'integer', 'between:1,365'],
            'storage_payer' => ['required', Rule::in(['vendor', 'owner', 'nobody'])],
            'buyer_storage_after_days' => ['nullable', 'integer', 'between:0,365'],
            'release_without_payment' => ['boolean'],
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
            'silence_means_buy' => $request->boolean('silence_means_buy'),
            'release_without_payment' => $request->boolean('release_without_payment'),
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

        return redirect('/settings/vendors')->with('toast', 'Удалён');
    }
}
