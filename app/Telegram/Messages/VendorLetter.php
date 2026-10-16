<?php

namespace App\Telegram\Messages;

use App\Mail\Message as Letter;
use App\Offers\Offer;
use App\Support\Surface;
use App\Telegram\Text;
use App\Vendors\Vendor;
use Illuminate\Support\Str;

/**
 * Письмо вендора на deal@ — владельцу и админам в Telegram сразу (17.10.2026, владелец про АльфаСтрахование: «очень
 * важно»; у вендора галка `letters_to_telegram`). Машина — если письмо уже у предложения, дальше от кого и его слова.
 */
final class VendorLetter extends Message
{
    public function __construct(private Letter $letter, private Vendor $vendor, private ?Offer $offer = null) {}

    protected function title(): string
    {
        return $this->vendor->name.': '.($this->offer?->titleWithYear() ?? ($this->letter->subject ?: 'письмо без темы'));
    }

    protected function lines(): array
    {
        $words = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $this->letter->ownText())), 600);

        return Text::lines($this->offer,
            'От '.trim(($this->letter->from_name ? $this->letter->from_name.' ' : '').'<'.$this->letter->from_email.'>'),
            $this->offer ? $this->letter->subject : null,
            $words,
        );
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        $path = $this->offer
            ? "/offers/{$this->offer->number}?window=/offers/{$this->offer->number}/letters"
            : '/work/mail?window='.$this->letter->thread_id;

        return ['text' => 'Открыть письмо', 'url' => Surface::Crm->url($path)];
    }
}
