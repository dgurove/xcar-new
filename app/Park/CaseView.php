<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Billing\ChargeKind;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Http\Park\VehicleInvoiceController;
use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
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

    /** Что начисляют руками: хранение считается само, негабарит входит в суточную ставку. */
    private const CHARGES = [ChargeKind::Tow, ChargeKind::Inspection, ChargeKind::Idle, ChargeKind::Loading, ChargeKind::Release, ChargeKind::Other];

    public static function for(Vehicle $vehicle, User $user, ?int $requestId = null, bool $callAgain = false): array
    {
        $vehicle->load(['brand', 'model', 'vendor.contacts', 'yard', 'media', 'requests.yard', 'requests.assignee', 'requests.doneBy', 'events.user', 'inspections.user', 'docs.media', 'docs.thread', 'offer', 'invoices.party'])->loadCount(['threads' => fn ($q) => $q->park()]);
        $open = ($requestId ? $vehicle->requests->first(fn (Request $r) => $r->id === $requestId && $r->isOpen()) : null) ?? self::current($vehicle);
        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        $release = $open?->type === RequestType::Release;
        $threads = Thread::where('vehicle_id', $vehicle->id)->park()->get(['id', 'needs_reply_at']);
        // Выдача по QR: живой пропуск (анкета покупателя) — один запрос на экран и таймлайн.
        $pass = $vehicle->releasesByQr() ? $vehicle->pass() : null;
        $ids = $threads->pluck('id');
        // Ждёт ответа — одно правило на почту, дело и ленту: последнее письмо ветки их, с вопросом, и после него
        // мы не писали и не нажимали «Сделано» (`Threads::refresh` → `needs_reply_at`). Само письмо — последнее входящее.
        $waiting = $threads->whereNotNull('needs_reply_at')->pluck('id');
        $asks = $waiting->isEmpty() ? collect() : Message::whereIn('thread_id', $waiting)->where('direction', Direction::In)
            ->orderBy('date_at')->orderBy('id')->get(['id', 'thread_id', 'subject', 'from_name', 'from_email', 'date_at', 'text_body', 'html_body', 'intent'])
            ->groupBy('thread_id')->map->last()->sortBy('date_at')->values();

        return [
            'vehicle' => $vehicle,
            'req' => $open,
            'verb' => $open?->verb(),
            'phases' => $vehicle->requests->filter(fn (Request $r) => $r->state === RequestState::Done)->sortBy(fn (Request $r) => ($r->done_at ?? $r->updated_at)->getTimestamp())->values(),
            'others' => $vehicle->requests->filter(fn (Request $r) => $r->isOpen() && $open && $r->isNot($open))->values(),
            'letters' => $letters = $ids->isEmpty() ? 0 : Message::whereIn('thread_id', $ids)->count(),
            'steps' => Timeline::for($vehicle, $open, $letters > 0 || $vehicle->requests->contains(fn (Request $r) => $r->thread_id), $vehicle->events, $callAgain, $asks, $pass),
            'asks' => $asks,
            // Текст письма о приёме — в шаг «Нужно позвонить»: «клиент сам свяжется», «документы в офисе СК», «со СТОА по адресу…».
            'letterText' => $open?->thread_id ? Intent::excerpt(Message::where('thread_id', $open->thread_id)->where('direction', Direction::In)->orderBy('date_at')->value('text_body')) : null,
            'threads' => Thread::where('vehicle_id', $vehicle->id)->park()->orderByDesc('last_message_at')->get(['id', 'subject']),
            // Блок «Письма» над таймлайном: последнее письмо словами, этапы — из цепочки кандидата этой ТС.
            'lastLetter' => $ids->isEmpty() ? null : Message::whereIn('thread_id', $ids)->with(['author', 'attachments', 'account'])->orderByDesc('date_at')->first(),
            'candidate' => $threads->isEmpty() ? null : Candidate::where('vehicle_id', $vehicle->id)->latest('id')->first(),
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'slots' => PhotoSlot::cases(),
            'staff' => User::where(fn ($q) => $q->whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin'))->whereNotNull('approved_at')->whereNull('rejected_at')->orderBy('name')->get(),
            'vendors' => Vendor::where('is_active', true)->orWhere('id', $vehicle->vendor_id)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            // Персональная ставка важнее прайса: `Accrual` считает по ней, а чип показывал бы лестницу вендора.
            'storageRate' => $vehicle->storage_rate !== null ? 'своя '.Money::rub($vehicle->storage_rate).'/сут' : Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
            'accrued' => Accrual::summary($vehicle),
            'buyerFrom' => $vehicle->sold_at ? Accrual::buyerFrom($vehicle) : null,
            'buyerRate' => $vehicle->sold_at ? Accrual::buyerRate($vehicle) : 0,
            'owners' => Party::where('kind', 'person')->orderBy('name')->pluck('name', 'id'),
            // «Долг» — только неоплаченные счета: набежавшее вендору идёт отдельной строкой и выдачу не держит.
            'debt' => Ledger::vehicleDebt($vehicle),
            'cashDue' => $release ? Ledger::buyerUnbilled($vehicle) : 0,
            'vendorDue' => $release ? Ledger::vendorUnbilled($vehicle) : 0,
            'debtBlocks' => $release && ! ($vehicle->vendor?->release_without_payment ?? false),
            'payers' => Ledger::payersOf($vehicle),
            'pendingCharges' => $vehicle->charges()->whereNull('invoice_id')->whereNull('voided_at')->get(),
            'chargeKinds' => collect(self::CHARGES)->mapWithKeys(fn ($k) => [$k->value => $k->label().(($price = VehicleInvoiceController::priceFor($vehicle, $k)) ? ' '.Money::rub($price) : '')]),
            // Цена из прайса подставляется в поле при выборе вида: суммы, которые есть в прайсе, руками не набирают.
            'chargePrices' => collect(self::CHARGES)->mapWithKeys(fn ($k) => [$k->value => VehicleInvoiceController::priceFor($vehicle, $k)])->filter()->all(),
            'spots' => $vehicle->yard?->freeSpots() ?? [],
            'canManage' => $user->canManagePark(),
            // Выдача по QR: живой пропуск (анкета покупателя), код из адреса — отсканировали камерой телефона.
            'byQr' => $vehicle->releasesByQr(),
            'pass' => $pass,
            'scannedPass' => $vehicle->releasesByQr() && $release ? Pass::codeFrom((string) request()->query('pass')) : null,
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
