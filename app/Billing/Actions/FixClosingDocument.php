<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Park\Vehicle;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Зафиксировать живой документ месяца — он ушёл (письмом, `MailController::send`, или «Отправили сами»): счёт с номером,
 * строки, `storage_billed_until`, файлы акта, `sent_at` (событие — `InvoiceIssued` из `IssueInvoice`). `$replaces` —
 * исправленный: прежний аннулируется, новый — с его номером и следующей редакцией, из тех же ТС; у ТС, чьё хранение
 * выставлено и позже (октябрь после исправленного сентября), «выставлено по» возвращается к концу более позднего счёта.
 * Документа уже нет — ошибка «уже отправлен».
 */
final class FixClosingDocument
{
    /** @var list<string> ТС, не вошедшие в документ, с причиной (чужой отрезок раньше своего) */
    public array $skipped = [];

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private StorageActPdf $act) {}

    /**
     * `$code` и `$at` — «Отправили сами» за прошлые месяцы (05.11.2026, владелец: «сверю со старыми актами и прожму, что
     * отправлено»): документ ушёл раньше по-старому — со своим номером из архива и в свой день; срок оплаты — от этого дня.
     */
    public function __invoke(CarbonInterface $month, string $group, User $by, ?Invoice $replaces = null, ?string $code = null, ?CarbonInterface $at = null): Invoice
    {
        $this->skipped = [];
        try {
            return $this->fix($month, $group, $by, $replaces, $code, $at);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Номер '.$code.' уже есть']);
        }
    }

    private function fix(CarbonInterface $month, string $group, User $by, ?Invoice $replaces, ?string $code, ?CarbonInterface $at): Invoice
    {
        return DB::transaction(function () use ($month, $group, $by, $replaces, $code, $at) {
            $only = null;
            if ($replaces) {
                $only = $replaces->registryVehicles()->pluck('id')->all();
                ($this->void)($replaces, $by, 'Исправлен', revising: true);
                $replaces->refresh();
            }
            $rows = Closing::rowsOf($month, $group, null, $only);
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['closing' => 'Документ уже отправлен']);
            }
            $first = $rows->first();
            $v = $first['vehicle'];
            $party = Closing::payer($first);
            $code = $replaces?->code ?? ($code !== null && trim($code) !== '' ? trim($code) : null);
            $revision = $replaces ? $replaces->revision + 1 : 0;
            $day = $at ? Carbon::instance($at)->startOfDay() : now();
            $due = $day->copy()->addWeekdays($v->vendor?->payment_days ?? 5);
            $issue = $this->issue;
            $invoice = match (true) {
                $first['tow'] => $issue($party, $by, 'issued', ChargeKind::Tow, $due, month: $month, code: $code, revision: $revision,
                    registry: $rows->map(fn ($i) => ['vehicle' => $i['vehicle']->fresh(), 'until' => null, 'charges' => $i['charges']])->values()->all()),
                $first['payer'] === 'buyer' => $issue($party, $by, 'issued', ChargeKind::Storage, $day, $first['to'], $first['charges'], vehicle: $v->fresh(), code: $code, revision: $revision),
                default => $issue($party, $by, 'issued', ChargeKind::Storage, $due, month: $month, code: $code, revision: $revision,
                    registry: $rows->map(fn ($i) => ['vehicle' => $i['vehicle']->fresh(), 'until' => $i['to'], 'charges' => $i['charges']])->values()->all()),
            };
            $this->skipped = $issue->skipped;
            if ($only) {
                $this->restoreLater($only);
            }
            $this->act->attach($invoice);
            // Прошлый документ (свой день отправки) с давно прошедшим сроком — просрочка отмечена сразу и молча: иначе часы
            // (`TickInvoices`) прислали бы «просрочен» на каждый отмеченный за всю историю.
            $invoice->update(['sent_at' => $at ? $day : now(), 'sent_by' => $by->id] + ($at ? ['issued_at' => $day->toDateString()] : [])
                + ($at && $due->lt(now()->startOfDay()) ? ['overdue_at' => now()] : []));

            return $invoice;
        });
    }

    /** «Выставлено по» — не раньше конца живого счёта с хранением ТС (аннулирование исправленного его откатило). */
    private function restoreLater(array $ids): void
    {
        foreach ($ids as $id) {
            $to = Charge::where('vehicle_id', $id)->where('kind', ChargeKind::Storage)->whereNull('voided_at')
                ->whereHas('invoice', fn ($q) => $q->where('state', '!=', InvoiceState::Void->value))->max('period_to');
            $vehicle = Vehicle::whereKey($id)->lockForUpdate()->first();
            if ($to && $vehicle && (! $vehicle->storage_billed_until || $vehicle->storage_billed_until->lt($to))) {
                $vehicle->update(['storage_billed_until' => $to]);
            }
        }
    }
}
