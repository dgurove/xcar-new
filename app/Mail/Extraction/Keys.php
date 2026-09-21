<?php

namespace App\Mail\Extraction;

use App\Mail\Candidate;
use App\Offers\Offer;
use App\Park\Vehicle;

/**
 * Номера тем же ключом, что у писем (`ReadLetter` → `mail_message_keys`, `mail_threads.keys`): `code:`, `vin:`,
 * `plate:` — у ТС, предложения и строки поиска, чтобы письмо «ч.2» отдельной веткой, наш ответ из почтового
 * клиента и ТС с этим убытком находили друг друга.
 */
final class Keys
{
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
