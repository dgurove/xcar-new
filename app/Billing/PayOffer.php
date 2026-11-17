<?php

namespace App\Billing;

use App\Billing\Acquiring\PayLink;
use App\Offers\Deal;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Что уходит менеджеру по счёту сделки — одной дверью для уведомлений, хода сделки, CRM и тоста (17.11.2026, владелец про
 * «Отправить счёт менеджеру … Цена подтверждения 1 750 000 ₽»: «кажется, что это счёт на машину, а там счёт за подбор ПРАЙМ»;
 * «писать везде, что это ссылка на оплату на такую-то сумму»; «счёт за подбор — лишь альтернативный способ оплаты подбора, чаще
 * всего по счёту либо наличкой платят»).
 * - Счёт со ссылкой (подбор, доля ПРАЙМ — `PayLink::linkable`) — «ссылка на оплату подбора 88 190 ₽». Счёта за подбор нет
 *   (18.11.2026, владелец: «счёт на комиссию мы больше не формируем, только либо по ссылке либо наличкой») — разве что запросили
 *   (`Invoice::isDocument`); без шлюза — «оплата подбора 88 190 ₽».
 * - Без ссылки (машина от ПРАЙМ, Р.О.С.) — «счёт № 197 за ТС на 1 750 000 ₽».
 * Цены сделки здесь нет никогда: менеджер платит сумму счёта.
 */
final class PayOffer
{
    /** Будет ли у счёта ссылка на оплату, когда его отправят (сейчас или после отправки). */
    public static function linked(Invoice $i): bool
    {
        return PayLink::linkable($i);
    }

    /** За что платят — родительным, после «оплату»: «подбора», «ТС». */
    public static function what(Invoice $i): string
    {
        return match ($i->kind) {
            ChargeKind::Selection => 'подбора',
            ChargeKind::AgentFee => 'вознаграждения',
            ChargeKind::Sale => 'ТС',
            ChargeKind::Storage => 'хранения',
            ChargeKind::Service => 'услуг',
            default => 'по счёту',
        };
    }

    /** За что счёт — после «за»: «подбор», «ТС». */
    private static function forWhat(Invoice $i): string
    {
        return match ($i->kind) {
            ChargeKind::Selection => 'подбор',
            ChargeKind::AgentFee => 'вознаграждение',
            ChargeKind::Sale => 'ТС',
            ChargeKind::Storage => 'хранение',
            ChargeKind::Service => 'услуги',
            default => '',
        };
    }

    /** Сколько платить: остаток без заявленных менеджером оплат (ссылка — на столько же, `PayLink::defaultAmount`). */
    public static function amount(Invoice $i): float
    {
        return max(0, round($i->remaining() - $i->claimed(), 2));
    }

    /** «Ссылка на оплату подбора» / «Оплата подбора» / «Счёт за ТС № 197» — без суммы, с заглавной. */
    public static function name(Invoice $i): string
    {
        return match (true) {
            self::linked($i) => 'Ссылка на оплату '.self::what($i),
            ! $i->isDocument() => 'Оплата '.self::what($i),
            default => self::invoiceName($i),
        };
    }

    /** Не счёт, а сумма к оплате: ссылка или комиссия без счёта («Ссылка на оплату подбора 88 190 ₽», без «на»). */
    private static function bare(Invoice $i): bool
    {
        return self::linked($i) || ! $i->isDocument();
    }

    /** «Счёт за подбор № 197» — счёт с тем, за что он: «счёт № 197» читается как счёт за машину. */
    public static function invoiceName(Invoice $i): string
    {
        $for = self::forWhat($i);
        // До отправки у счёта сделки номера нет — «без номера» не пишем: номер встанет при отправке (`Numbering::assign`).
        $no = $i->label() === 'без номера' ? '' : ' '.$i->label();

        return 'Счёт'.($for !== '' ? ' за '.$for : '').$no;
    }

    /** «Ссылка на оплату подбора 88 190 ₽» / «Счёт за ТС № 197 на 1 750 000 ₽». */
    public static function line(Invoice $i): string
    {
        return self::name($i).(self::bare($i) ? ' ' : ' на ').Money::rub(self::amount($i));
    }

    /** Подпись кнопки отправки: «Отправить ссылку на оплату» / «Отправить счёт». */
    public static function sendLabel(Invoice $i): string
    {
        return match (true) {
            self::linked($i) => 'Отправить ссылку на оплату',
            ! $i->isDocument() => 'Отправить менеджеру',
            default => 'Отправить счёт',
        };
    }

    /** Тост и «История» после отправки: «Ссылка на оплату подбора 88 190 ₽ отправлена менеджеру». */
    public static function sent(Invoice $i): string
    {
        return self::line($i).(self::bare($i) ? ' отправлена' : ' отправлен').' менеджеру';
    }

    /**
     * Счета сделки, что ещё не отправлены менеджеру (`Deal::invoiceGap` = `send`): живые наши, не вознаграждение поставщика.
     *
     * @return Collection<int, Invoice>
     */
    public static function unsent(Deal $deal): Collection
    {
        return $deal->issuedInvoices()->where('kind', '!=', ChargeKind::Reward)->where('state', '!=', InvoiceState::Void->value)
            ->with('payments')->orderBy('id')->get()->reject(fn (Invoice $i) => $i->isShownToManager())->values();
    }
}
