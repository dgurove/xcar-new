<?php

namespace App\Mail\Extraction;

use App\Mail\Candidate;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Park\Vehicle;

/**
 * Номера ветки: убыток, VIN, госномер по всем её письмам, включая тему наших ответов («Re: 6892/046/01351/26 ч.1»).
 * Хранятся в `mail_threads.keys` тем же ключом, что тождества кандидата (`code:`, `vin:`, `plate:`), так что
 * письмо «ч.2» отдельной веткой, наш ответ из почтового клиента и ТС с этим убытком находят друг друга.
 */
final class Keys
{
    public function __construct(private Extractor $offers) {}

    /** @return list<string> */
    public function ofMessage(Message $message): array
    {
        $message->loadMissing('attachments');
        $body = $message->text_body ?: $message->html_body;
        $park = $message->account?->scope === Scope::Park;
        // Бухгалтерия (отчёт-акт со списком VIN) привязала бы ветку к десяткам цепочек — ключей не даёт; как и любое письмо с кучей номеров.
        if ($park && ($message->intent === Intent::Billing->value || ($message->intent === null && Intent::billing($message->subject, QuotationStripper::strip($body))))) {
            return [];
        }
        $fields = $park
            ? app(ParkExtractor::class)->extract($message->subject, $body, $message->from_email, $message->date_at, $message->attachments->pluck('filename')->all())
            : $this->offers->extract($message->subject, $body, $message->from_email, $message->date_at);
        $keys = Candidate::identities($fields, null);

        return count($keys) > 3 ? [] : $keys;
    }

    /** @return list<string> */
    public function ofThread(Thread $thread): array
    {
        $keys = [];
        foreach (Message::with(['account', 'attachments'])->where('thread_id', $thread->id)->get() as $message) {
            $keys = [...$keys, ...$this->ofMessage($message)];
        }

        return array_values(array_unique($keys));
    }

    public function rekey(Thread $thread): void
    {
        $thread->forceFill(['keys' => $this->ofThread($thread)])->saveQuietly();
    }

    /** Тождества ТС теми же ключами. @return list<string> */
    public static function ofVehicle(Vehicle $vehicle): array
    {
        return array_values(array_filter([
            $vehicle->ref_key ? 'code:'.$vehicle->ref_key : null,
            $vehicle->vin ? 'vin:'.strtoupper($vehicle->vin) : null,
            Candidate::plateKey($vehicle->plate) ? 'plate:'.Candidate::plateKey($vehicle->plate) : null,
        ]));
    }

    /** @return list<string> */
    public static function ofOffer(Offer $offer): array
    {
        return array_values(array_filter([
            $offer->claim_ref_key ? 'code:'.$offer->claim_ref_key : null,
            $offer->vin ? 'vin:'.strtoupper($offer->vin) : null,
        ]));
    }

    /** Ключи из строки поиска: номер убытка, VIN, госномер в любом написании. @return list<string> */
    public static function fromQuery(string $q): array
    {
        $keys = [];
        if (($code = Code::key($q)) && strlen((string) $code) >= 6) {
            $keys[] = 'code:'.$code;
        }
        if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', trim($q))) {
            $keys[] = 'vin:'.strtoupper(trim($q));
        }
        if (preg_match('/^[А-ЯA-Z]\s?\d{3}\s?[А-ЯA-Z]{2}\s?\d{2,3}$/iu', trim($q))) {
            $keys[] = 'plate:'.Candidate::plateKey($q);
        }

        return $keys;
    }
}
