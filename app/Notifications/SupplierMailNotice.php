<?php

namespace App\Notifications;

use App\Mail\Message;
use App\Offers\Offer;
use App\Offers\ReplyKind;
use App\Telegram\Messages\VendorLetter;
use App\Vendors\Vendor;
use Illuminate\Support\Str;

/**
 * Письмо поставщика — админам в ленту, пушем и в Telegram (18.11.2026; было только Telegram-сообщением `VendorLetter` мимо
 * ленты — начальник не видел «ГОТС поступили на реализацию» по Santa Fe в 15:55). Каждое письмо по машине в идущей сделке
 * (любой ящик, пересылка сотрудника) и, как было, любое письмо вендора с галкой `letters_to_telegram`. Автоответ — нет.
 * Капсулой: логотип вендора, машина, о чём письмо (`ReplyKind`).
 */
final class SupplierMailNotice extends Notice
{
    public function __construct(private Message $letter, private Vendor $from, private ?Offer $offer = null)
    {
        $this->telegramMessage = new VendorLetter($letter, $from, $offer);
    }

    public function label(): string
    {
        return 'Письмо поставщика';
    }

    public function title(): string
    {
        return $this->from->name.': '.($this->offer?->titleWithYear() ?? ($this->letter->subject ?: 'письмо без темы'));
    }

    public function text(): ?string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $this->letter->shownText())), 300) ?: null;
    }

    /** «АльфаСтрахование: ГОТС поступили на реализацию»; переписка без шаблона — первыми словами письма. */
    public function brief(?\App\Users\User $user = null): string
    {
        $kind = $this->offer?->deal ? ReplyKind::of($this->letter) : ReplyKind::Other;
        $what = $kind === ReplyKind::Other ? Str::limit((string) $this->text(), 90) : $kind->title();

        return $this->from->name.': '.($what ?: 'письмо');
    }

    public function car(): ?string
    {
        return $this->offer?->titleWithYear();
    }

    public function href(): string
    {
        return $this->telegramMessage->parts()['link']['url'];
    }

    /** Машина: открыл её — строка прочитана. Строк и пушей — по письму (лента не сворачивает, `collapses`). */
    public function subject(): string
    {
        return $this->offer ? '/offers/'.$this->offer->number : parent::subject();
    }

    public function offerNumber(): ?int
    {
        return $this->offer?->number;
    }

    public function vendor(): ?Vendor
    {
        return $this->from;
    }

    public function category(): string
    {
        return 'supplier_mail';
    }

    /** Звенит и висит: ответ ждут от нас. */
    public function important(): bool
    {
        return true;
    }
}
