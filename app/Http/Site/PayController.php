<?php

namespace App\Http\Site;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\SettleAcquiring;
use App\Billing\Acquiring\Actions\SettleRefund;
use App\Billing\Acquiring\Actions\StartCheckout;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLink;
use App\Notifications\MoneyNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

        // Вернулся с ЮKassa, а последняя попытка отменена — банк отказал или время вышло: экран «Не прошла» со словами.
        $last = $link->isOpen() && $request->boolean('back') ? $link->attempts()->latest('id')->first() : null;

        return view('site.pay.show', [
            'link' => $link, 'invoice' => $link->invoice, 'self' => $link->invoice->seller->party(),
            'processing' => $request->boolean('back') && $pending?->isPending(), 'failed' => $request->boolean('failed'),
            'declined' => $last?->status === 'canceled' ? $last : null,
        ]);
    }

    public function go(Request $request, string $code, StartCheckout $start)
    {
        $link = $this->link($code);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:120'],
        ], ['email.email' => 'Проверьте почту']);
        try {
            $url = $start($link, $data['name'] ?? null, $data['email'] ?? null);
            Cache::forget('acquiring:failures');

            return redirect()->away($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('acquiring: «Оплатить» по '.$code.' — '.$e->getMessage());
            $link->update(['error' => Str::limit($e->getMessage(), 250, ''), 'error_at' => now()]);
            // Два отказа шлюза подряд — ЮKassa не отвечает или ключи не те: сотрудникам в ленту, не чаще раза в час.
            if (Cache::increment('acquiring:failures') >= 2 && Cache::add('acquiring:down-told', 1, now()->addHour())) {
                Notification::send(User::withRole(Role::Admin)->get(), MoneyNotice::gatewayDown($e->getMessage()));
            }

            // Признак в адресе, а не во flash: страницу перечитывает ещё и воркер, и flash доставался ему.
            return redirect('/pay/'.$code.'?failed=1');
        }
    }

    /**
     * Уведомление ЮKassa. Телу не верим: берём из него только id, статус перечитываем по API своим ключом —
     * подделать «succeeded» так нельзя. Платёж, которого у нас нет (ответ на создание потерялся), заводится по
     * `metadata.link` перечитанного платежа; чужой — 204 и тишина. `refund.*` — статус возврата.
     */
    public function hook(Request $request, Gateway $gateway, SettleAcquiring $settle, SettleRefund $refund)
    {
        $id = (string) $request->input('object.id');
        $event = (string) $request->input('event');
        if ($id === '' || ! $gateway->configured()) {
            return response()->noContent();
        }
        try {
            if (str_starts_with($event, 'refund.')) {
                $refund($gateway->fetchRefund($id));
            } elseif (str_starts_with($event, 'payment.')) {
                $attempt = AcquiringPayment::where('external_id', $id)->first();
                $fresh = $attempt ? null : $gateway->fetch($id);
                $attempt ??= AcquiringPayment::adopt($fresh, $gateway->name());
                if ($attempt) {
                    $settle($attempt, $fresh);
                }
            }
        } catch (Throwable $e) {
            // 500 — ЮKassa повторит уведомление; опрос `acquiring:sync` подберёт в любом случае.
            Log::warning('acquiring: уведомление '.$event.' '.$id.' — '.$e->getMessage());

            return response()->noContent(500);
        }

        return response()->noContent();
    }

    private function link(string $code): PayLink
    {
        // Демо-ссылка открывается и без входа: её счёт (`PayLink::invoice`) и предложение область `demo` не прячет.
        return PayLink::where('code', $code)->with(['invoice.offer' => fn ($q) => $q->withoutGlobalScope('demo')->with('brand', 'model'), 'creator', 'payerUser'])->firstOrFail();
    }
}
