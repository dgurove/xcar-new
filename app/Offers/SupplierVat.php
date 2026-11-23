<?php

namespace App\Offers;

use App\Billing\InvoiceState;
use App\Billing\Robot;
use App\Billing\Vat;
use App\Cars\Car;
use App\Cars\Sources\Collect;
use App\Mail\Attachment;
use App\Mail\Direction;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Notifications\DealEventNotice;
use App\Notifications\Notify;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Actions\UpdateOffer;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * НДС того, у кого забираем машину (20.11.2026, бухгалтер: «по договору комиссии нужно узнавать, по какой системе НДС работает
 * тот, у кого мы забираем машину; без НДС — в счёте без НДС, 22 % — 22 % на всю сумму»). Одна дверь разбора письма и документа
 * и записи в `offers.supplier_vat` (ставку счёта за ТС считает `DealContract::vatRate`).
 *
 * Что пишут на проде (поиск 20.11.2026): Совкомбанк — «Сделка НДС не облагается» в письмах вывоза, в договоре комиссии «НДС не
 * облагается» или «в т.ч. НДС 22 %»; Альфа — «реквизиты для выставления счёта. Без НДС.» / «C НДС.» (латинская C), её счёт и ДКП
 * «В том числе НДС 22 % — 60 049,18 руб.», а бывает «22 % — 0,00 руб.» — это без НДС; Абсолют — «в т.ч. НДС 34 348,13 руб.
 * (22%)»; ВСК — «НДС не облагается… упрощённой системы». Мимо — заказ-наряды ремонта и наши же счета и акты (наш ИНН в тексте,
 * правки счетов хранения «НДС 5 % = …»).
 *
 * Вписал человек — не перебиваем; свежее письмо или документ перебивает прежний автомат.
 */
final class SupplierVat
{
    /** Документ не о продаже машины поставщиком: ремонт, наши счета и акты, хранение. */
    private const SKIP = '/заказ[\s-]*наряд|ремонтн\w*\s+работ|хранени[ея]\s+(?:ТС|транспорт|автомоб)|5007110932|9704056013/ui';

    /** Ставка из текста или null — ничего про НДС не сказано. */
    public static function fromText(string $text): ?int
    {
        $t = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        if ($t === '' || preg_match(self::SKIP, $t)) {
            return null;
        }
        $nds = 'НДС';
        $num = '(\d{1,3}(?:[ \x{00A0}]?\d{3})*(?:[.,]\d{1,2})?)';
        // «в т.ч. НДС 22 % — 46 852,46 руб.», «В том числе НДС 22% - 0,00 руб.», «включая НДС 20 %»: ставка, при нулевой сумме — без НДС.
        // Все такие места: хоть одна нулевая сумма («22 % — 0,00 руб.» у Альфы) — без НДС, иначе первая ставка.
        if (preg_match_all('/(?:в\s*т\.?\s*ч\.?|в\s+том\s+числе|включая)\s+'.$nds.'\s*\(?\s*(\d{1,2})\s*%\s*\)?(?:\s*(?:[-–—:=]|в\s+размере)\s*'.$num.')?/ui', $t, $all, PREG_SET_ORDER)) {
            $rate = null;
            foreach ($all as $m) {
                if (isset($m[2]) && $m[2] !== '' && self::amount($m[2]) == 0.0) {
                    return 0;
                }
                $rate ??= self::rate($m[1]);
            }
            if ($rate !== null) {
                return $rate;
            }
        }
        // «в т.ч. НДС 34 348,13 руб. (22%)» (Абсолют).
        if (preg_match('/'.$nds.'\s*[-–—:]?\s*'.$num.'\s*(?:руб\.?|₽|р\.)?\s*\(\s*(\d{1,2})\s*%\s*\)/ui', $t, $m) && ($r = self::rate($m[2])) !== null) {
            return self::amount($m[1]) == 0.0 ? 0 : $r;
        }
        if (preg_match('/без\s+'.$nds.'|'.$nds.'\s+не\s+облага|упрощ[её]нн\w*\s+систем\w*\s+налогооблож/ui', $t)) {
            return 0;
        }
        // «НДС 20 %», «ставка НДС 22%».
        if (preg_match('/'.$nds.'\s*\(?\s*(\d{1,2})\s*%/ui', $t, $m) && ($r = self::rate($m[1])) !== null) {
            return $r;
        }
        // «C НДС.» (латинская C у Альфы) без ставки — общая ставка 2026 года.
        if (preg_match('/(?<![\p{L}\d])[сcСC]\s+'.$nds.'(?![\p{L}])/u', $t)) {
            return 22;
        }

        return null;
    }

    private static function rate(string $digits): ?int
    {
        $r = (int) $digits;

        return in_array($r, Vat::RATES, true) && $r > 0 ? $r : null;
    }

    private static function amount(string $sum): float
    {
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $sum);
    }

    /** Ставка из письма поставщика: свои слова без цитат (наши счета в цитате — не его НДС). */
    public static function fromLetter(Message $message): ?int
    {
        return $message->isFromVendor() && ! $message->isAutomatic() ? self::fromText($message->ownText()) : null;
    }

    /**
     * Ставка из документов поставщика по машине — самого свежего письма, где она есть (договор комиссии, его счёт или ДКП).
     *
     * @return array{rate: int, at: Carbon, message_id: int, snippet: string}|null
     */
    public static function fromDocuments(Car $car): ?array
    {
        $found = null;
        foreach (Collect::files($car) as $file) {
            if (! $file instanceof Attachment || $file->isPhoto() || ! ($message = $file->message) || $message->direction !== Direction::In || $message->isOurs()) {
                continue;
            }
            $text = $file->isImage() ? DocumentText::cached($file) : DocumentText::layer($file);
            if (! $text || ! self::saleDocument($text) || ($rate = self::fromText($text)) === null) {
                continue;
            }
            $at = $message->date_at ?? $message->created_at;
            if (! $found || $at->gt($found['at'])) {
                $flat = (string) preg_replace('/\s+/u', ' ', $text);
                $pos = mb_stripos($flat, 'НДС');
                $found = ['rate' => $rate, 'at' => $at, 'message_id' => $message->id, 'snippet' => $pos === false ? '' : mb_substr($flat, max(0, $pos - 40), 80)];
            }
        }

        return $found;
    }

    /**
     * Документ о продаже самой машины: его счёт («Оплата за повреждённое ТС»), договор комиссии или купли-продажи. Соглашение о
     * страховом возмещении и уведомление об абандоне лизинговой машины несут НДС возмещения и лизинга, а не продавца (прогон по
     * проду 20.11.2026: 2610101663, 2610272958 вышли бы «22 %», хотя Совкомбанк пишет «Сделка НДС не облагается»).
     */
    public static function saleDocument(string $text): bool
    {
        $t = (string) preg_replace('/\s+/u', ' ', $text);

        return (bool) preg_match('/сч[её]т\s+на\s+оплату|оплата\s+за\s+поврежд|договор\w*\s+комисси|договор\w*\s+купли[\s-]*продажи/ui', $t)
            && ! preg_match('/страхов\w+\s+возмещени|абандон/ui', $t);
    }

    /** Письмо поставщика по машине — его ставка, если сказана (`OnMessage`). */
    public static function onLetter(Offer $offer, Message $message): void
    {
        if (($rate = self::fromLetter($message)) !== null) {
            self::put($offer, $rate, 'letter', $message->id, $message->date_at ?? $message->created_at);
        }
    }

    /** Документы машины прочитаны — ставка из самого свежего, где она есть (`Collect`). */
    public static function onDocuments(Car $car): void
    {
        try {
            if (($offer = $car->offer) && ($doc = self::fromDocuments($car))) {
                self::put($offer, $doc['rate'], 'document', $doc['message_id'], $doc['at']);
            }
        } catch (Throwable $e) {
            Log::warning('НДС поставщика из документов не встал', ['car' => $car->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Записать ставку: вписанную человеком не трогаем, прежний автомат свежее — тоже. Сделка идёт и ставка в договоре не вписана
     * руками — счёт за ТС перевыставляется за ней (`SyncDealInvoices`), админам строкой; оплаченный — громко, разобраться.
     */
    public static function put(Offer $offer, int $rate, string $source, ?int $messageId, ?Carbon $at, ?User $by = null): bool
    {
        if (! in_array($rate, Vat::RATES, true) || $offer->supplier_vat_source === 'human'
            || ($offer->supplier_vat_at && $at && $at->lt($offer->supplier_vat_at) && in_array($offer->supplier_vat_source, ['letter', 'document'], true))) {
            return false;
        }
        if ($offer->supplier_vat === $rate && $offer->supplier_vat_source === $source) {
            return false;
        }
        $was = $offer->supplier_vat;
        app(UpdateOffer::class)($offer, ['supplier_vat' => $rate, 'supplier_vat_source' => $source, 'supplier_vat_message_id' => $messageId, 'supplier_vat_at' => $at ?? now()],
            $by, ['why' => 'НДС поставщика '.($source === 'letter' ? 'по письму' : 'по документу')]);
        if ($was === $rate) {
            return true;
        }
        $deal = Deal::where('offer_id', $offer->id)->active()->latest('id')->first();
        if ($deal && $deal->contract && $deal->contract->vat_rate === null && $deal->isPrime()) {
            $deal->unsetRelation('offer');
            $paid = $deal->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Sale)->where('state', InvoiceState::Paid)
                ->where('vat_rate', '!=', $rate ?: null)->exists();
            if (! $paid) {
                app(SyncDealInvoices::class)($deal->fresh(), $by ?? Robot::user());
            }
            $what = 'НДС поставщика '.Vat::label($rate).($source === 'letter' ? ' по письму' : ' по документу')
                .($paid ? ', а счёт за ТС уже оплачен с другим НДС: разберитесь' : ', счёт за ТС по нему');
            Notify::toDeals(DealEventNotice::supplierVat($deal, $what, $paid));
        }

        return true;
    }
}
