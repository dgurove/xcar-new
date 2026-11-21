<?php

namespace App\Billing\Documents;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;

/**
 * Строка QR платёжки по ГОСТ Р 56042 (ST00012, UTF-8): приложение любого банка заполняет перевод само —
 * получатель, счёт, сумма к оплате, назначение «Оплата по счёту № N от …», по которому выписка потом
 * находит счёт. Получатель — продавец счёта; без его банковских реквизитов QR не печатается.
 */
final class PaymentQr
{
    public static function text(Invoice $invoice, ?Party $self = null): ?string
    {
        $self ??= $invoice->seller->party();
        if ($self->bankMissing() || $invoice->isOwed() || ! $invoice->number) {
            return null;
        }
        $offset = $invoice->relationLoaded('payments')
            ? $invoice->payments->where('source', PaymentSource::Offset)->sum('amount')
            : $invoice->payments()->where('source', PaymentSource::Offset)->sum('amount');
        $due = round($invoice->total - $offset, 2);
        $fields = array_filter([
            'Name' => $self->name,
            'PersonalAcc' => $self->account,
            'BankName' => $self->bank_name,
            'BIC' => $self->bik,
            'CorrespAcc' => $self->corr_account ?: '0',
            'PayeeINN' => $self->inn,
            'KPP' => $self->kpp,
            'Sum' => (string) (int) round($due * 100),
            'Purpose' => self::purpose($invoice),
        ], fn ($v) => $v !== null && $v !== '');

        return 'ST00012|'.implode('|', array_map(fn ($k, $v) => $k.'='.str_replace('|', '/', $v), array_keys($fields), $fields));
    }

    /**
     * Счёт сделки ещё не отправлен менеджеру — номера нет (`Numbering::assign`), а без номера нет и назначения для QR: вместо кода
     * заглушка (21.11.2026, владелец: «рисовал заглушку QR кода и подписал красиво „QR сформируется после отправки“»).
     */
    public static function pending(Invoice $invoice, ?Party $self = null): bool
    {
        $self ??= $invoice->seller->party();

        return ! $self->bankMissing() && ! $invoice->isOwed() && ! $invoice->number && ! $invoice->code && $invoice->deal_id !== null;
    }

    /**
     * Заглушка PNG data-URI: бледный QR во весь квадрат, поверх — белая плашка с подписью в две строки. Одна картинка, а не
     * вёрстка поверх кода: dompdf наложение блоков рисует криво. Рисуется раз на процесс.
     */
    public static function placeholder(): string
    {
        static $uri = null;
        if ($uri !== null) {
            return $uri;
        }
        $im = imagecreatefromstring(\App\Support\Qr::png('ST00012|Name=XCAR|PersonalAcc=00000000000000000000|BankName=XCAR|BIC=000000000|CorrespAcc=0', 8, 0));
        $side = imagesx($im);
        imagefilledrectangle($im, 0, 0, $side, $side, imagecolorallocatealpha($im, 255, 255, 255, 22));
        $font = resource_path('fonts/pdf/Onest-Medium.ttf');
        $lines = ['QR сформируется', 'после отправки'];
        $size = $side / 15;
        $boxes = array_map(fn ($l) => imagettfbbox($size, 0, $font, $l), $lines);
        $width = max(array_map(fn ($b) => $b[2] - $b[0], $boxes));
        $line = $size * 1.5;
        [$padX, $padY] = [$size * 0.9, $size * 0.8];
        $x0 = ($side - $width) / 2 - $padX;
        $y0 = ($side - $line * count($lines)) / 2 - $padY;
        $plate = [$x0, $y0, $side - $x0, $side - $y0];
        $white = imagecolorallocate($im, 255, 255, 255);
        $r = (int) ($size * 0.8);
        imagefilledrectangle($im, (int) $plate[0] + $r, (int) $plate[1], (int) $plate[2] - $r, (int) $plate[3], $white);
        imagefilledrectangle($im, (int) $plate[0], (int) $plate[1] + $r, (int) $plate[2], (int) $plate[3] - $r, $white);
        foreach ([[$plate[0] + $r, $plate[1] + $r], [$plate[2] - $r, $plate[1] + $r], [$plate[0] + $r, $plate[3] - $r], [$plate[2] - $r, $plate[3] - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, (int) $cx, (int) $cy, 2 * $r, 2 * $r, $white);
        }
        $ink = imagecolorallocate($im, 0x80, 0x80, 0x80);
        foreach ($lines as $i => $text) {
            $w = $boxes[$i][2] - $boxes[$i][0];
            imagettftext($im, $size, 0, (int) (($side - $w) / 2), (int) ($y0 + $padY + $line * $i + $size * 1.15), $ink, $font, $text);
        }
        ob_start();
        imagepng($im);

        return $uri = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Назначение платежа. Счёт за ТС — «Оплата ТС Exeed LX 2024 г.в. VIN …» (19.11.2026, владелец): по VIN выписка сама кладёт
     * поступление в сделку машины (`MatchByVin` → `ApplyDealCredits`), номер счёта не нужен. Остальные — «Оплата по счёту № N от …»,
     * его ищет `MatchTransaction`.
     */
    public static function purpose(Invoice $invoice): string
    {
        // НДС с суммой, без ставки — «Без НДС» (20.11.2026, бухгалтер).
        $vat = $invoice->vatRate() ? 'В т.ч. НДС '.$invoice->vatRate().'% - '.number_format($invoice->vatAmount(), 2, '.', '').' руб.' : 'Без НДС';
        if ($invoice->kind === ChargeKind::Sale && ($offer = $invoice->carOffer()) && trim((string) $offer->carRecord()->vin) !== '') {
            return 'Оплата '.$offer->invoiceTitle().'. '.$vat;
        }

        return 'Оплата по счёту № '.$invoice->docNo().' от '.$invoice->issued_at->format('d.m.Y').'. '.$vat;
    }
}
