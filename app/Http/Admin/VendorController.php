<?php

namespace App\Http\Admin;

use App\Mail\Account;
use App\Mail\Scope;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
use App\Users\Section;
use App\Vendors\Actions\SetLogo;
use App\Vendors\DealFormat;
use App\Vendors\Kind;
use App\Vendors\Parser;
use App\Vendors\RewardKind;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Вендоры в CRM — продажа предложений: условия сделки (формат, вознаграждение, срок ответа, «держим», «молчание =
 * покупка», НДС цен предложений), почта продажи (ящик offer@/deal@, адреса и разбор писем с предложениями),
 * контакты «Реализация» и маршруты. Имя, тип и «работаем» — общие, правятся на обеих сторонах. Всё парковочное —
 * реквизиты, договор, хранение, прайс, деньги, заявки на приёмку — на парковке (`App\Http\Park\VendorController`).
 */
class VendorController
{
    public const PILLS = ['overview' => 'Обзор', 'contacts' => 'Контакты', 'routes' => 'Маршруты'];

    /** Пилюли карточки до разделения, уехавшие на парковку: старые ссылки ведут туда. */
    private const PARK_PILLS = ['tariffs', 'money'];

    public function index(Request $request)
    {
        $kind = Kind::tryFrom($request->query('kind', ''));
        $off = $request->boolean('off');
        $q = Vendor::with('workflows')->withCount('offers')
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
        if (in_array($request->query('pill'), self::PARK_PILLS, true)) {
            return redirect()->away(Surface::Park->url("/vendors/{$vendor->id}?pill={$request->query('pill')}"), 301);
        }
        $pill = array_key_exists($request->query('pill', ''), self::PILLS) ? $request->query('pill') : 'overview';
        $data = [
            'vendor' => $vendor,
            'pill' => $pill,
            'pills' => self::PILLS,
            'base' => "/settings/vendors/{$vendor->id}",
            // Ссылка на парковочную карточку — только тем, кого туда пустят.
            'park' => $request->user()->canAccess(Section::Park) ? Surface::Park->url("/vendors/{$vendor->id}") : null,
            'accounts' => Account::where('scope', Scope::Offers)->where('is_active', true)->orderBy('title')->get()->mapWithKeys(fn ($a) => [$a->id => $a->title.' ('.$a->email.')']),
        ];

        if ($pill === 'contacts') {
            $vendor->load('contacts');
        } elseif ($pill === 'routes') {
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
            $vendor->load('mailAccount');
            $data += [
                'offers' => Offer::where('vendor_id', $vendor->id)->whereNotIn('state', [OfferState::Archived])->with(['brand', 'model'])->latest()->limit(12)->get(),
                'offersTotal' => Offer::where('vendor_id', $vendor->id)->count(),
            ];
        }

        return view('admin.vendors.show', $data);
    }

    public function update(Request $request, Vendor $vendor, SetLogo $setLogo)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:vendors,name,'.$vendor->id],
            'kind' => ['required', Rule::enum(Kind::class)],
            'is_active' => ['boolean'],
            'deal_format' => ['required', Rule::enum(DealFormat::class)],
            'reward_kind' => ['nullable', Rule::enum(RewardKind::class)],
            'reward_value' => ['nullable', 'integer', 'min:0'],
            'answer_hours' => ['nullable', 'integer', 'between:1,720'],
            'binding_days' => ['nullable', 'integer', 'between:1,365'],
            'silence_means_buy' => ['boolean'],
            'offers_include_vat' => ['boolean'],
            // Ящик продажи — только из ящиков CRM: парковочный отсюда не выбрать.
            'mail_account_id' => ['nullable', Rule::exists('mail_accounts', 'id')->where('scope', Scope::Offers->value)],
            'senders' => ['nullable', 'string', 'max:2000'],
            'parser' => ['required', Rule::enum(Parser::class)],
        ] + SetLogo::RULES);
        $senders = Vendor::parseSenders($data['senders'] ?? null);
        if ($taken = Vendor::takenSender($senders, Scope::Offers, $vendor)) {
            return back()->withInput()->withErrors(['senders' => $taken]);
        }
        $vendor->update(array_merge($data, [
            'senders' => $senders,
            'is_active' => $request->boolean('is_active'),
            'silence_means_buy' => $request->boolean('silence_means_buy'),
            'offers_include_vat' => $request->boolean('offers_include_vat'),
        ]));

        $setLogo($vendor, $request);

        return back()->with('toast', 'Сохранено');
    }
}
