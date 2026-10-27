<?php

namespace App\Park;

use App\Cars\Settlement;
use App\Mail\Message;
use App\Vendors\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Город ТС и цепочки «Из писем» — одно решение с источником (27.10.2026: заявка ВСК от питерского эксперта встала в Москву,
 * и питерский управляющий её не видел; до того город считали пять мест по разным правилам). Доказательства от сильного к
 * слабому, берётся первый уровень, где они есть:
 *
 * - `yard` — парковка ТС: её город;
 * - `manual` — решил человек («Это Санкт-Петербург», перевод), не перетирается ничем, кроме парковки;
 * - `letter` — адрес или город в полях письма вендора, если это город одной из наших парковок;
 * - `contact` — парковка, вписанная у контакта отправителя руками;
 * - `sender` — история отправителя: ТС его заявок с городом от парковки или человека стояли в одном городе;
 * - `vendor` — парковка вендора по умолчанию, иначе его история в одном городе, новый вендор — основной город.
 *
 * На уровне два разных города — не уверены: `city` null, ТС видна управляющим всех городов, пока её не примут
 * (владелец: «если не уверены, пусть и там и там»; «один отправитель бывает и для Питера, и для Москвы»).
 *
 * @phpstan-type Guess array{0: ?int, 1: string}
 */
final class CityGuess
{
    public const YARD = 'yard';

    public const MANUAL = 'manual';

    /** Их перетирает только парковка: решение есть. */
    public const FIRM = [self::YARD, self::MANUAL];

    /** @return Guess город и источник для `Vehicle::saving` */
    public static function vehicle(Vehicle $v): array
    {
        if ($v->yard_id && ($city = Yard::withoutGlobalScope(City::SCOPE)->whereKey($v->yard_id)->value('settlement_id'))) {
            return [(int) $city, self::YARD];
        }
        if ($v->city_source === self::MANUAL && $v->city_id) {
            return [(int) $v->city_id, self::MANUAL];
        }
        $letters = $v->exists ? Message::whereIn('id', Request::withoutGlobalScope(City::SCOPE)->where('vehicle_id', $v->id)->whereNotNull('letter_id')->select('letter_id'))->get() : collect();

        return self::letters($letters, $v->vendor_id);
    }

    /**
     * По письмам вендора (цепочка «Из писем», письмо заявки): адрес в письме → контакт → история отправителя → вендор.
     *
     * @param  Collection<int, Message>  $letters
     * @return Guess
     */
    public static function letters(Collection $letters, ?int $vendorId): array
    {
        $theirs = $letters->filter(fn (Message $m) => ! $m->isOurs() || $m->isForwardedByStaff());
        $senders = $theirs->map(fn (Message $m) => mb_strtolower(trim((string) ($m->isForwardedByStaff() ? $m->field('sender') : $m->from_email))))->filter()->unique();
        $levels = [
            'letter' => fn () => $theirs->map(fn (Message $m) => self::ofText(collect(['city', 'address', 'from_address', 'pickup_address'])->map(fn ($k) => $m->field($k))->filter()->implode(', ')))->all(),
            'contact' => fn () => $senders->map(fn ($e) => Contact::yardFor($e)?->settlement_id)->all(),
            'sender' => fn () => $senders->flatMap(fn ($e) => self::senderCities($e))->all(),
        ];
        foreach ($levels as $source => $found) {
            $cities = array_values(array_unique(array_map('intval', array_filter($found()))));
            if ($cities) {
                return [count($cities) === 1 ? $cities[0] : null, $source];
            }
        }

        return [City::vendorCity($vendorId), 'vendor'];
    }

    /** Город наших парковок в адресе письма: «г. Санкт-Петербург, Пулковское ш.» → Петербург; чужой город — null. */
    private static function ofText(string $text): ?int
    {
        if (trim($text) === '') {
            return null;
        }
        $place = Settlement::inAddress($text);
        $cities = array_keys(City::options());
        if ($place && in_array((int) $place['id'], $cities, true)) {
            return (int) $place['id'];
        }
        // Место в том же регионе, что город парковки (Пушкин — Санкт-Петербург): её город.
        $region = $place ? Settlement::whereKey($place['id'])->value('region_id') : null;

        return $region ? Settlement::whereIn('id', $cities)->where('region_id', $region)->value('id') : null;
    }

    /** @return list<int> где стояли ТС заявок отправителя — город от парковки или человека, не догадка */
    private static function senderCities(string $email): array
    {
        // Без `once`: в одной задаче почты письма того же отправителя идут подряд, и решение по прошлому должно быть свежим.
        return DB::table('park_vehicles')->join('park_requests', 'park_requests.vehicle_id', '=', 'park_vehicles.id')
            ->join('mail_messages', 'mail_messages.id', '=', 'park_requests.letter_id')->whereRaw('lower(mail_messages.from_email) = ?', [$email])
            ->whereIn('park_vehicles.city_source', self::FIRM)->whereNotNull('park_vehicles.city_id')
            ->distinct()->pluck('park_vehicles.city_id')->map(fn ($c) => (int) $c)->all();
    }
}
