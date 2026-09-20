<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Billing\ChargeKind;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Http\Park\RequestController;
use App\Http\Park\VehicleInvoiceController;
use App\Mail\Message;
use App\Mail\Scope as MailScope;
use App\Mail\Template;
use App\Mail\Thread;
use App\Park\Actions\LinkOffer;
use App\Support\Money;
use App\Users\Section;
use App\Users\User;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;

/**
 * Дело ТС одной страницей: всё, что нужно экрану `/cars/{id}` — открытая заявка (текущий этап и его
 * форма), прошедшие фазы, деньги, бумаги, письма. Один сборщик на страницу и окошко строки.
 */
final class CaseView
{
    /** Какая из открытых заявок — текущий этап: эвакуация и приём раньше выдачи, выдача раньше перестановки и осмотра. */
    private const PRIORITY = [RequestType::Tow, RequestType::Intake, RequestType::Release, RequestType::Move, RequestType::Inspection];

    public static function for(Vehicle $vehicle, User $user, ?int $requestId = null, bool $callAgain = false): array
    {
        $vehicle->load(['brand', 'model', 'vendor.contacts', 'yard', 'media', 'requests.yard', 'requests.assignee', 'requests.doneBy', 'events.user', 'inspections.user', 'docs.media', 'docs.thread', 'offer', 'invoices.party']);
        $open = ($requestId ? $vehicle->requests->first(fn (Request $r) => $r->id === $requestId && $r->isOpen()) : null) ?? self::current($vehicle);
        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        $release = $open?->type === RequestType::Release;
        $threads = Thread::where('vehicle_id', $vehicle->id)->select('id')->pluck('id');

        return [
            'vehicle' => $vehicle,
            'req' => $open,
            'verb' => $open?->verb(),
            'phases' => $vehicle->requests->filter(fn (Request $r) => $r->state === RequestState::Done)->sortBy(fn (Request $r) => ($r->done_at ?? $r->updated_at)->getTimestamp())->values(),
            'others' => $vehicle->requests->filter(fn (Request $r) => $r->isOpen() && $open && $r->isNot($open))->values(),
            'letters' => $letters = $threads->isEmpty() ? 0 : Message::whereIn('thread_id', $threads)->count(),
            'steps' => Timeline::for($vehicle, $open, $letters > 0 || $vehicle->requests->contains(fn (Request $r) => $r->thread_id), $vehicle->events, $callAgain),
            'threads' => Thread::where('vehicle_id', $vehicle->id)->orderByDesc('last_message_at')->get(['id', 'subject']),
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'slots' => PhotoSlot::cases(),
            'staff' => User::where(fn ($q) => $q->whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin'))->whereNotNull('approved_at')->whereNull('rejected_at')->orderBy('name')->get(),
            'towCost' => $open?->isTow() ? RequestController::towCost($vehicle, $open->distance_km) : null,
            'carriers' => $open?->isTow() ? Request::whereNotNull('carrier')->where('carrier', '!=', '')->selectRaw('carrier, count(*) as n')->groupBy('carrier')->orderByDesc('n')->limit(20)->pluck('carrier') : collect(),
            'vendors' => Vendor::where('is_active', true)->orWhere('id', $vehicle->vendor_id)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'storageRate' => Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
            'accrued' => Accrual::summary($vehicle),
            'buyerFrom' => $vehicle->sold_at ? Accrual::buyerFrom($vehicle) : null,
            'buyerRate' => $vehicle->sold_at ? Accrual::buyerRate($vehicle) : 0,
            'owners' => Party::where('kind', 'person')->orderBy('name')->pluck('name', 'id'),
            'debt' => Ledger::vehicleDebt($vehicle) + ($release ? Ledger::vehicleUnbilled($vehicle) : 0),
            'buyerDebt' => $release ? Ledger::buyerDebt($vehicle) : 0,
            'debtBlocks' => $release && ! ($vehicle->vendor?->release_without_payment ?? false),
            'payers' => Ledger::payersOf($vehicle),
            'pendingCharges' => $vehicle->charges()->whereNull('invoice_id')->whereNull('voided_at')->get(),
            'chargeKinds' => collect([ChargeKind::Tow, ChargeKind::Inspection, ChargeKind::Idle, ChargeKind::Loading, ChargeKind::Release, ChargeKind::Other])->mapWithKeys(fn ($k) => [$k->value => $k->label().(($price = VehicleInvoiceController::priceFor($vehicle, $k)) ? ' — '.Money::rub($price) : '')]),
            'templates' => Template::where('scope', MailScope::Park)->orderBy('name')->get(),
            'spots' => $vehicle->yard?->freeSpots() ?? [],
            'offerGuess' => $vehicle->offer_id ? null : LinkOffer::guess($vehicle),
            'canManage' => $user->canManagePark(),
        ];
    }

    public static function current(Vehicle $vehicle): ?Request
    {
        $open = $vehicle->requests->filter(fn (Request $r) => $r->isOpen());
        foreach (self::PRIORITY as $type) {
            if ($r = $open->first(fn (Request $r) => $r->type === $type)) {
                return $r;
            }
        }

        return $open->first();
    }
}
