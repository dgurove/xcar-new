<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Cars\Category;
use App\Http\Park\VehicleInvoiceController;
use App\Mail\Candidate;
use App\Mail\Chains\NodeTitle;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Actions\FillFromDocs;
use App\Support\Docs;
use App\Support\Money;
use App\Users\User;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * Дело ТС одной страницей: всё, что нужно экрану `/cars/{id}` — открытая заявка (текущий этап и его
 * форма), прошедшие фазы, деньги, бумаги, письма. Один сборщик на страницу и карточка строки.
 */
final class CaseView
{
    /** Какая из открытых заявок — текущий этап: приём раньше выдачи, выдача раньше перестановки и осмотра. */
    private const PRIORITY = [RequestType::Intake, RequestType::Release];

    /** Что начисляют руками: хранение считается само, негабарит входит в суточную ставку. */
    private const CHARGES = [ChargeKind::Tow, ChargeKind::Inspection, ChargeKind::Idle, ChargeKind::Loading, ChargeKind::Release, ChargeKind::Other];

    /**
     * Шторка документов дела: письмо вендора, документы ТС, файлы писем, которых среди них нет, фото.
     *
     * @return list<array<string, mixed>>
     */
    private static function docs(Vehicle $vehicle, Collection $threadIds): array
    {
        // Входящие, а не «не наши»: пересылка сотрудника со своего ящика — письмо вендора в цитате.
        $letters = $threadIds->isEmpty() ? collect() : Message::with(['attachments', 'account'])->whereIn('thread_id', $threadIds)->where('direction', Direction::In)->orderBy('date_at')->get();
        $papers = $vehicle->papers();
        $names = $papers->pluck('file_name')->map(fn ($n) => Docs::norm($n))->all();
        ['docs' => $files] = Docs::fromLetters($letters, '/mail');
        $photos = $vehicle->photos();

        return array_values(array_filter([
            $letters->isNotEmpty() ? Docs::letter($letters->first(fn (Message $m) => ! $m->isOurs()) ?? $letters->first(), '/mail') : null,
            ...$papers->map(fn ($m) => Docs::media($m))->all(),
            ...array_filter($files, fn ($d) => ! in_array(Docs::norm($d['name']), $names, true)),
            $photos->isNotEmpty() ? Docs::photos($photos) : null,
        ]));
    }

    public static function for(Vehicle $vehicle, User $user, ?int $requestId = null): array
    {
        $vehicle->load(['vendor.contacts', 'yard', 'media', 'requests.yard', 'requests.doneBy', 'events.user', 'inspections.user', 'docs.media', 'docs.thread', 'offer', 'invoices.party'])->loadCount(['threads' => fn ($q) => $q->park()]);
        // Заявки — этой же ТС: «Выдать» спрашивает её состояние, без связи — запрос на каждую заявку.
        $vehicle->requests->each->setRelation('vehicle', $vehicle);
        $open = ($requestId ? $vehicle->requests->first(fn (Request $r) => $r->id === $requestId && $r->isOpen()) : null) ?? self::current($vehicle);
        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        $release = $open?->type === RequestType::Release;
        // Письма машины — только ящика парковки (`Thread::ofCar`).
        $threads = Thread::ofCar($vehicle, $user)->orderByDesc('last_message_at')->get(['id', 'subject', 'needs_reply_at', 'account_id']);
        // Выдача по QR: живой пропуск (анкета покупателя) — один запрос на экран и таймлайн.
        $pass = $vehicle->releasesByQr() ? $vehicle->pass() : null;
        $ids = $threads->pluck('id');
        // Ждёт ответа — одно правило на почту, дело и ленту: последнее письмо ветки их, с вопросом, и после него
        // мы не писали и не нажимали «Сделано» (`Threads::refresh` → `needs_reply_at`). Само письмо — последнее входящее.
        $waiting = $threads->whereNotNull('needs_reply_at')->pluck('id');
        $asks = $waiting->isEmpty() ? collect() : Message::whereIn('thread_id', $waiting)->where('direction', Direction::In)
            ->orderBy('date_at')->orderBy('id')->get(['id', 'thread_id', 'subject', 'from_name', 'from_email', 'date_at', 'text_body', 'html_body', 'intent'])
            ->groupBy('thread_id')->map->last()->sortBy('date_at')->values();
        // Блок «Письма» над таймлайном — все письма строками, как в «Почте»; этапы — из цепочки кандидата этой ТС.
        $messages = $ids->isEmpty() ? collect() : Message::whereIn('thread_id', $ids)->with(['author', 'attachments', 'account'])->orderBy('date_at')->orderBy('id')->get();

        return [
            'vehicle' => $vehicle,
            'req' => $open,
            'verb' => $open?->verb(),
            'phases' => $vehicle->requests->filter(fn (Request $r) => $r->state === RequestState::Done)->sortBy(fn (Request $r) => ($r->done_at ?? $r->updated_at)->getTimestamp())->values(),
            'others' => $vehicle->requests->filter(fn (Request $r) => $r->isOpen() && $open && $r->isNot($open))->values(),
            'letters' => NodeTitle::count($messages),
            'steps' => Timeline::for($vehicle, $open, $messages->isNotEmpty() || $vehicle->requests->contains(fn (Request $r) => $r->thread_id), $vehicle->events, $asks, $pass),
            'asks' => $asks,
            'threads' => $threads,
            'messages' => $messages,
            'docs' => self::docs($vehicle, $ids),
            'candidate' => $threads->isEmpty() ? null : Candidate::where('vehicle_id', $vehicle->id)->latest('id')->first(),
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'vendors' => Vendor::where(fn ($q) => $q->onPark()->where('is_active', true))->orWhere('vendors.id', $vehicle->vendor_id)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            // Персональная ставка важнее прайса: `Accrual` считает по ней, а чип показывал бы лестницу вендора.
            'storageRate' => $vehicle->storage_rate !== null ? 'своя '.Money::rub($vehicle->storage_rate).'/сут' : Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
            'accrued' => Accrual::summary($vehicle),
            'buyerFrom' => $vehicle->sold_at ? Accrual::buyerFrom($vehicle) : null,
            'buyerRate' => $vehicle->sold_at ? Accrual::buyerRate($vehicle) : 0,
            'owners' => Party::where('kind', 'person')->orderBy('name')->pluck('name', 'id'),
            // «Долг» — только неоплаченные счета: набежавшее вендору идёт отдельной строкой и выдачу не держит.
            'debt' => Ledger::vehicleDebt($vehicle),
            // Выдачу держит только долг покупателя: страховая сторона платит по счёту раз в месяц.
            'releaseDebt' => $release ? Ledger::releaseDebt($vehicle) : 0,
            'cashDue' => $release ? Ledger::buyerUnbilled($vehicle) : 0,
            'vendorDue' => $release ? Ledger::vendorUnbilled($vehicle) : 0,
            'debtBlocks' => $release && ! ($vehicle->vendor?->release_without_payment ?? false),
            'payers' => Ledger::payersOf($vehicle),
            'pendingCharges' => $vehicle->charges()->whereNull('invoice_id')->whereNull('voided_at')->get(),
            // Реестры за месяц, где есть эта ТС: в «Оплатах» дела — строкой с её долей.
            'registries' => Invoice::forVehicle($vehicle->id)->whereNull('vehicle_id')->whereNotNull('month')->with(['party', 'charges'])->latest('issued_at')->get(),
            'chargeKinds' => collect(self::CHARGES)->mapWithKeys(fn ($k) => [$k->value => $k->label().(($price = VehicleInvoiceController::priceFor($vehicle, $k)) ? ' '.Money::rub($price) : '')]),
            // Цена из прайса подставляется в поле при выборе вида: суммы, которые есть в прайсе, руками не набирают.
            'chargePrices' => collect(self::CHARGES)->mapWithKeys(fn ($k) => [$k->value => VehicleInvoiceController::priceFor($vehicle, $k)])->filter()->all(),
            'canManage' => $user->canManagePark(),
            // Карточка и документы дела расходятся — чип «в документе …» у полей (FillFromDocs, в кеше на день).
            'differences' => $user->canManagePark() ? app(FillFromDocs::class)->differences($vehicle) : [],
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
