<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueDealInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Offers\Deal;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Счёт «Подбор ТС» сделки «страхователю по ДКП» (05.10.2026): на разницу цены подтверждения и того, что покупатель
 * отдаёт страхователю (`Deal::selectionBase`, не закупочной: взаимозачёт — наш), двумя строками — «Подбор ТС» и
 * «Агентское вознаграждение», которое менеджер удерживает (зачёт). К оплате — подбор (850 − 750 − 20 = 80 000). Выставляется при принятии; правка денег сделки перевыставляет его, пока за него не
 * платили (зачёт вознаграждения — не оплата).
 */
final class IssueSelectionInvoice
{
    public function __construct(private IssueDealInvoice $issue, private VoidInvoice $void) {}

    public function __invoke(Deal $deal, User $by): ?Invoice
    {
        $current = $deal->issuedInvoices()->where('kind', ChargeKind::Selection)->with('payments')->get();
        if ($current->contains(fn (Invoice $i) => self::paidFor($i))) {
            throw ValidationException::withMessages(['commission' => 'За подбор уже платили: деньги сделки не меняются']);
        }
        foreach ($current as $invoice) {
            ($this->void)($invoice, $by, 'Деньги сделки изменены');
        }
        $base = (int) $deal->selectionBase();
        if ($base <= 0) {
            return null;
        }
        $deal->loadMissing(['offer', 'buyer']);

        return ($this->issue)($deal, $by, Party::forUser($deal->buyer), ChargeKind::Selection, $base, now()->addDays(3));
    }

    /** Оплата, кроме зачёта удержанного вознаграждения. */
    private static function paidFor(Invoice $invoice): bool
    {
        return $invoice->payments->contains(fn ($p) => $p->source !== PaymentSource::Offset && $p->amount > 0);
    }
}
