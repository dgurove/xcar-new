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

    public static function for(Vehicle $vehicle, User $user, ?int $requestId = null, bool $callAgain = false): array
    {
        $vehicle->load(['brand', 'model', 'vendor.contacts', 'yard', 'media', 'requests.yard', 'requests.assignee', 'requests.doneBy', 'events.user', 'inspections.user', 'docs.media', 'docs.thread', 'offer', 'invoices.party']);
        $open = ($requestId ? $vehicle->requests->first(fn (Request $r) => $r->id === $requestId && $r->isOpen()) : null) ?? self::current($vehicle);
        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        $release = $open?->type === RequestType::Release;
        $threads = Thread::where('vehicle_id', $vehicle->id)->select('id')->pluck('id');
        // Письма, которые ждут ответа (осмотр, бумаги, вопрос, «не вывезено»): без нашего письма после них и без «Сделано».
        $asks = $threads->isEmpty() ? collect() : Message::whereIn('thread_id', $threads)->where('direction', Direction::In)
            ->whereIn('intent', array_map(fn (Intent $i) => $i->value, array_filter(Intent::cases(), fn (Intent $i) => $i->needsReply())))
            ->orderBy('date_at')->get(['id', 'thread_id', 'subject', 'from_name', 'from_email', 'date_at', 'text_body', 'html_body', 'intent']);
        $answeredIds = $vehicle->events->where('type', EventType::LetterAnswered)->pluck('payload.message')->filter()->all();
        // Ответом считается и письмо сотрудника с личного ящика (Message::ownEmails).
        $replied = $asks->isEmpty() ? collect() : Message::whereIn('thread_id', $threads)
            ->where(fn ($q) => $q->where('direction', Direction::Out)->orWhereIn('from_email', Message::ownEmails()))->get(['thread_id', 'date_at']);
        $asks->each(fn (Message $m) => $m->answered = in_array($m->id, $answeredIds, true) || $replied->contains(fn ($r) => $r->thread_id === $m->thread_id && $r->date_at?->gt($m->date_at)));

        return [
            'vehicle' => $vehicle,
            'req' => $open,
            'verb' => $open?->verb(),
            'phases' => $vehicle->requests->filter(fn (Request $r) => $r->state === RequestState::Done)->sortBy(fn (Request $r) => ($r->done_at ?? $r->updated_at)->getTimestamp())->values(),
            'others' => $vehicle->requests->filter(fn (Request $r) => $r->isOpen() && $open && $r->isNot($open))->values(),
            'letters' => $letters = $threads->isEmpty() ? 0 : Message::whereIn('thread_id', $threads)->count(),
            'steps' => Timeline::for($vehicle, $open, $letters > 0 || $vehicle->requests->contains(fn (Request $r) => $r->thread_id), $vehicle->events, $callAgain, $asks),
            'ask' => $asks->last(fn (Message $m) => ! $m->answered),
            // Текст письма о приёме — в шаг «Нужно позвонить»: «клиент сам свяжется», «документы в офисе СК», «со СТОА по адресу…».
            'letterText' => $open?->thread_id ? Intent::excerpt(Message::where('thread_id', $open->thread_id)->where('direction', Direction::In)->orderBy('date_at')->value('text_body')) : null,
            'threads' => Thread::where('vehicle_id', $vehicle->id)->orderByDesc('last_message_at')->get(['id', 'subject']),
            // Блок «Письма» над таймлайном: последнее письмо словами, этапы — из цепочки кандидата этой ТС.
            'lastLetter' => $threads->isEmpty() ? null : Message::whereIn('thread_id', $threads)->with(['author', 'attachments', 'account'])->orderByDesc('date_at')->first(),
            'candidate' => $threads->isEmpty() ? null : Candidate::where('vehicle_id', $vehicle->id)->latest('id')->first(),
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'slots' => PhotoSlot::cases(),
            'staff' => User::where(fn ($q) => $q->whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin'))->whereNotNull('approved_at')->whereNull('rejected_at')->orderBy('name')->get(),
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
            'spots' => $vehicle->yard?->freeSpots() ?? [],
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
