<?php

namespace App\Http\Admin;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\VehicleState;
use App\Support\Surface;
use App\Vendors\DealFormat;
use App\Vendors\Kind;
use App\Vendors\RewardKind;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Вендоры в CRM — только продажа предложений: условия сделки (формат, вознаграждение, срок ответа, «держим»,
 * «молчание = покупка») и маршруты. Всё парковочное — реквизиты, договор, хранение, контакты, прайс, деньги,
 * почта — на парковке (`App\Http\Park\VendorController`, `/vendors/{id}`).
 */
class VendorController
{
    public const PILLS = ['overview' => 'Обзор', 'routes' => 'Маршруты'];

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
            'park' => Surface::Park->url("/vendors/{$vendor->id}"),
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
        } else {
            $data += [
                'offers' => Offer::where('vendor_id', $vendor->id)->whereNotIn('state', [OfferState::Archived])->with(['brand', 'model'])->latest()->limit(12)->get(),
                'offersTotal' => Offer::where('vendor_id', $vendor->id)->count(),
            ];
        }

        return view('admin.vendors.show', $data);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $data = $request->validate([
            'deal_format' => ['required', Rule::enum(DealFormat::class)],
            'reward_kind' => ['nullable', Rule::enum(RewardKind::class)],
            'reward_value' => ['nullable', 'integer', 'min:0'],
            'answer_hours' => ['nullable', 'integer', 'between:1,720'],
            'binding_days' => ['nullable', 'integer', 'between:1,365'],
            'silence_means_buy' => ['boolean'],
        ]);
        $vendor->update(array_merge($data, ['silence_means_buy' => $request->boolean('silence_means_buy')]));

        return back()->with('toast', 'Сохранено');
    }
}
