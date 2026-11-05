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

    public function __invoke(CarbonInterface $month, string $group, User $by, ?Invoice $replaces = null): Invoice
    {
        $this->skipped = [];

        return DB::transaction(function () use ($month, $group, $by, $replaces) {
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
            $code = $replaces?->code;
            $revision = $replaces ? $replaces->revision + 1 : 0;
            $due = now()->addWeekdays($v->vendor?->payment_days ?? 5);
            $issue = $this->issue;
            $invoice = match (true) {
                $first['tow'] => $issue($party, $by, 'issued', ChargeKind::Tow, $due, month: $month, code: $code, revision: $revision,
                    registry: $rows->map(fn ($i) => ['vehicle' => $i['vehicle']->fresh(), 'until' => null, 'charges' => $i['charges']])->values()->all()),
                $first['payer'] === 'buyer' => $issue($party, $by, 'issued', ChargeKind::Storage, now(), $first['to'], $first['charges'], vehicle: $v->fresh(), code: $code, revision: $revision),
                default => $issue($party, $by, 'issued', ChargeKind::Storage, $due, month: $month, code: $code, revision: $revision,
                    registry: $rows->map(fn ($i) => ['vehicle' => $i['vehicle']->fresh(), 'until' => $i['to'], 'charges' => $i['charges']])->values()->all()),
            };
            $this->skipped = $issue->skipped;
            if ($only) {
                $this->restoreLater($only);
            }
            $this->act->attach($invoice);
            $invoice->update(['sent_at' => now(), 'sent_by' => $by->id]);

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
