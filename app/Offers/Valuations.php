<?php

namespace App\Offers;

use App\Cars\Car;
use App\Cars\Sources\Collect;
use App\Mail\Attachment;
use App\Mail\Direction;
use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Offers\Actions\UpdateOffer;
use App\Park\Actions\UpdateVehicle;
use App\Support\Ago;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Какая оценочная верна — самая свежая по дате источника (15.11.2026, владелец по 0790/046/10760/26: «18 октября Альфа прислала
 * оценку 6 млн, 26 октября — заявку на приём, в ней уже 3 млн. Получается, это более свежая оценка! Мы оценки с парковки
 * отметали и опирались на оценку от Альфы, но это был неверный подход»). Тип источника ничего не решает, Мигторг сам по себе
 * ничего не отменяет — только дата, на которую сумма верна (`offers.value_at`, `cars.value_at`):
 * - заявка поставщика во вложении письма — дата документа («от «23» октября 2026»), не прочлась — дата письма;
 * - письмо о переоценке — дата письма (`DetectReprice`);
 * - «Оценить из текста», бот, правка рукой — момент записи.
 *
 * Пишет только `UpdateOffer` (`$log['as_of']`): оценочная старше нынешней не встаёт (`older`), закупочная идёт за оценочной по
 * правилу, если не вписана рукой, при подтвердивших — переоценка на решение. Документы машины — после каждого чтения
 * (`Collect` → `fromDocuments`): своя заявка поставщика новее нынешней оценочной — она и встаёт.
 */
final class Valuations
{
    /** Где оценочная продажи ещё что-то решает: дальше сделка держит свою закупочную. */
    private const LIVE = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    /** На какой день верна нынешняя оценочная продажи. До `value_at` — последняя правка оценочной в «Истории». */
    public static function at(Offer $offer): ?Carbon
    {
        if ($offer->value === null) {
            return null;
        }
        if ($offer->value_at) {
            return $offer->value_at;
        }
        $at = $offer->id ? DB::table('car_events')->where('offer_id', $offer->id)->whereRaw("jsonb_exists(payload->'changes', 'value')")->max('created_at') : null;

        return $at ? Carbon::parse($at) : $offer->created_at;
    }

    /** На какой день верна оценочная ТС парковки. До `value_at` — правка на парковке, заявка, правка машины в «Истории». */
    public static function carAt(Car $car): ?Carbon
    {
        if (! $car->value) {
            return null;
        }
        if ($car->value_at) {
            return $car->value_at;
        }
        // Последняя правка оценочной — на парковке или у продажи (до 15.11.2026 «Оценить из текста» писала и в ТС); правок не
        // было — она с заявки на приём.
        $edits = array_filter([
            DB::table('park_vehicle_events')->join('park_vehicles', 'park_vehicles.id', '=', 'park_vehicle_events.vehicle_id')
                ->where('park_vehicles.car_id', $car->id)->whereRaw("jsonb_exists(park_vehicle_events.payload->'changes', 'value')")->max('park_vehicle_events.created_at'),
            DB::table('car_events')->where('car_id', $car->id)->whereRaw("jsonb_exists(payload->'changes', 'value')")->max('created_at'),
        ]);
        $at = $edits ? max($edits)
            : DB::table('park_requests')->join('park_vehicles', 'park_vehicles.id', '=', 'park_requests.vehicle_id')->where('park_vehicles.car_id', $car->id)->min('park_requests.letter_at');

        return $at ? Carbon::parse($at) : $car->created_at;
    }

    /** Сумма на `$asOf` старше нынешней оценочной — не встаёт. */
    public static function older(Offer $offer, ?Carbon $asOf): bool
    {
        $at = self::at($offer);

        return $asOf && $at && $asOf->lt($at);
    }

    /**
     * Оценочные из заявок поставщика по машине (`DocumentFields::isRequest`: акт несёт нашу же оценочную) — вложения входящих
     * писем её веток, наши ответы не в счёт; только прочитанный текст: кеш чтения или текстовый слой PDF, OCR здесь не запускается.
     *
     * @return Collection<int, array{value: int, as_of: Carbon, title: string, message_id: int}>
     */
    public static function documents(Car $car): Collection
    {
        $out = collect();
        foreach (Collect::files($car) as $file) {
            if (! $file instanceof Attachment || $file->isPhoto()) {
                continue;
            }
            $message = $file->message;
            if (! $message || $message->direction !== Direction::In || $message->isOurs()) {
                continue;
            }
            $text = $file->isImage() ? DocumentText::cached($file) : DocumentText::layer($file);
            $fields = $text && DocumentFields::isRequest($text) ? DocumentFields::extract($text) : [];
            $value = (int) ($fields['value']['value'] ?? 0);
            if ($value < Pricing::MIN || ScanFields::otherCar($car->vin, $car->brand_id, $fields['vin']['value'] ?? null, $fields['brand']['value'] ?? null)) {
                continue;
            }
            $letter = $message->date_at ?? $message->created_at;
            $dated = DocumentFields::dated((string) $text);
            $asOf = $dated && $dated->lte($letter) && $dated->gt($letter->copy()->subMonths(6)) ? $dated : $letter;
            $out->push(['value' => $value, 'as_of' => $asOf, 'title' => 'заявка от '.Ago::day($asOf), 'message_id' => $message->id]);
        }

        // Одна сумма в нескольких письмах (пересылка, «ч.2») — её самая ранняя дата: пересылка не делает оценку свежее.
        return $out->groupBy('value')->map(fn (Collection $g) => $g->sortBy('as_of')->first())->sortBy('as_of')->values();
    }

    /** Самая свежая оценочная из документов поставщика. */
    public static function latestDocument(Car $car): ?array
    {
        return self::documents($car)->last();
    }

    /**
     * Что сделать с продажей по документам: новая оценочная, если заявка поставщика свежее нынешней и сумма другая. Только у
     * вендора «Закупочная от оценочной» (Альфа): у остальных оценочная цен не двигает. Сделка — нет: там своя закупочная.
     *
     * @return array{value: int, as_of: Carbon, title: string, message_id: int, was: ?int, was_at: ?Carbon}|null
     */
    public static function proposal(Offer $offer): ?array
    {
        if (! in_array($offer->state, self::LIVE, true) || ! Pricing::byValue($offer->vendor_id) || $offer->costDeal()) {
            return null;
        }
        $doc = self::latestDocument($offer->carRecord());
        if (! $doc || (int) $offer->value === $doc['value'] || self::older($offer, $doc['as_of']) || (self::at($offer) && $doc['as_of']->eq(self::at($offer)))) {
            return null;
        }

        return $doc + ['was' => $offer->value === null ? null : (int) $offer->value, 'was_at' => self::at($offer)];
    }

    /**
     * Оценочная ТС парковки (ставка хранения) из документов: заявка поставщика свежее нынешней, а нынешнюю не вписывал человек.
     *
     * @return array{value: int, as_of: Carbon, title: string, message_id: int, was: ?int}|null
     */
    public static function parkProposal(Car $car): ?array
    {
        $vehicle = $car->vehicle;
        if (! $vehicle || $vehicle->state->isFinal() || $car->byHuman('value')) {
            return null;
        }
        $doc = self::latestDocument($car);
        $at = self::carAt($car);
        if (! $doc || (int) $car->value === $doc['value'] || ($car->value && $at && $doc['as_of']->lte($at))) {
            return null;
        }

        return $doc + ['was' => $car->value ? (int) $car->value : null];
    }

    /**
     * Поставить оценочную из документов, где она свежее: продаже (`proposal`) и ТС парковки (`parkProposal`). Сначала продажа —
     * правка парковки идёт в продажу (`ParkValue::follow`), и та сумма там уже стоит. Зовёт `Collect` после чтения документов.
     */
    public static function fromDocuments(Car $car): void
    {
        try {
            if (($offer = $car->offer) && ($p = self::proposal($offer))) {
                app(UpdateOffer::class)($offer, ['value' => $p['value']], null, ['source' => 'file', 'as_of' => $p['as_of'], 'why' => self::why($p, $p['was_at'])]);
            }
            $car->refresh();
            if ($p = self::parkProposal($car)) {
                app(UpdateVehicle::class)($car->vehicle, ['value' => $p['value']], null, [self::why($p, $car->value_at)], $p['as_of']);
            }
        } catch (Throwable $e) {
            Log::warning('Оценочная из документов не встала', ['car' => $car->id, 'error' => $e->getMessage()]);
        }
    }

    private static function why(array $p, ?Carbon $wasAt): string
    {
        return 'Оценочная по '.$p['title'].($p['was'] !== null ? ', свежее прежней '.Money::rub($p['was']).($wasAt ? ' от '.Ago::day($wasAt) : '') : '');
    }
}
