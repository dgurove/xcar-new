<?php

namespace App\Http\Site;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\SettleAcquiring;
use App\Billing\Acquiring\Actions\StartCheckout;
use App\Billing\Acquiring\PayLink;
use App\Billing\Party;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Оплата по ссылке, сторона плательщика (без входа): сумма, за что, кому и «Оплатить» — дальше страница ЮKassa.
 * Строк счёта, вознаграждения и PDF здесь нет: платить может покупатель менеджера.
 * Вернулся с ЮKassa (`?back=1`) — статус сверяется сразу, не дожидаясь уведомления.
 */
class PayController
{
    public function show(Request $request, string $code, SettleAcquiring $settle)
    {
        $link = $this->link($code);
        $pending = $link->isOpen() ? $link->attempts()->whereIn('status', ['pending', 'waiting_for_capture'])->latest('id')->first() : null;
        if ($pending && $request->boolean('back')) {
            try {
                $settle($pending);
                $link->refresh();
                $pending = $link->isOpen() ? $pending->fresh() : null;
            } catch (Throwable $e) {
                Log::warning('acquiring: возврат на /pay — '.$e->getMessage());
            }
        }

        return view('site.pay.show', [
            'link' => $link, 'invoice' => $link->invoice, 'self' => Party::self(),
            'processing' => $request->boolean('back') && $pending?->isPending(), 'failed' => $request->boolean('failed'),
        ]);
    }

    public function go(string $code, StartCheckout $start)
    {
        try {
            return redirect()->away($start($this->link($code)));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('acquiring: «Оплатить» по '.$code.' — '.$e->getMessage());

            // Признак в адресе, а не во flash: страницу перечитывает ещё и воркер, и flash доставался ему.
            return redirect('/pay/'.$code.'?failed=1');
        }
    }

    /**
     * Уведомление ЮKassa. Телу не верим: берём из него только id платежа, статус перечитываем по API
     * своим ключом — подделать «succeeded» так нельзя. Незнакомый платёж — 200 и тишина.
     */
    public function hook(Request $request, SettleAcquiring $settle)
    {
        $id = (string) $request->input('object.id');
        $attempt = $id !== '' && str_starts_with((string) $request->input('event'), 'payment.') ? AcquiringPayment::where('external_id', $id)->first() : null;
        if ($attempt) {
            try {
                $settle($attempt);
            } catch (Throwable $e) {
                // 500 — ЮKassa повторит уведомление; опрос `acquiring:sync` подберёт в любом случае.
                Log::warning('acquiring: уведомление '.$id.' — '.$e->getMessage());

                return response()->noContent(500);
            }
        }

        return response()->noContent();
    }

    private function link(string $code): PayLink
    {
        return PayLink::where('code', $code)->with(['invoice.offer.brand', 'invoice.offer.model', 'creator', 'payerUser'])->firstOrFail();
    }
}
