<?php

namespace App\Mail\Actions;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Keys;
use App\Mail\Extraction\Patterns;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Actions\ShowInGallery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Ветка ↔ машина (оффер или машина стоянки): по номерам письма (`Message::keys`, как их прочёл `ReadLetter`), а нет —
 * по номеру убытка и VIN во всём письме вместе с цитатой: ответ «RE:» без номера в теме несёт его в цитате исходного,
 * а машина уже заведена — ошибиться некуда. Либо руками. Одна дверь: как бы ни привязали, файлы ветки едут в
 * медиатеку машины (ImportThreadFiles).
 *
 * Номер убытка — не тождество, если письмо само говорит о другой машине (21.10.2026, Совкомбанк прислал Rexton темой
 * «по делу 687805-2026» — номером прицепа Krone, — и фото, акт и договор Rexton легли к Krone): VIN письма дальше двух
 * знаков от VIN машины или другая марка (`ScanFields::otherCar`) — по номеру не привязываем, письмо ждёт в «Из писем».
 */
final class LinkThread
{
    public function __construct(private CodeMatcher $matcher) {}

    /** `files: false` — привязать без импорта файлов (ТС уже выдана: из писем ничего не хранится). */
    public function __invoke(Thread $thread, Offer|Vehicle $to, bool $files = true): void
    {
        $thread->update(($to instanceof Offer ? ['offer_id' => $to->id] : ['vehicle_id' => $to->id]) + ['unlinked_at' => null]);
        if ($files) {
            ImportThreadFiles::attachNow($thread);
        }
        // Письмо вендора по черновику в галерее — предложение уже пришло, интерес не нужен (18.10.2026).
        if ($to instanceof Offer) {
            ShowInGallery::onLetter($to);
        }
    }

    /**
     * ТС завели (руками или из письма): все непривязанные ветки с её номером, VIN или госномером — к ней,
     * включая наши ответы из почтового клиента и письма «ч.2», пришедшие отдельной веткой.
     */
    public function forVehicle(Vehicle $vehicle, bool $files = true): int
    {
        $keys = Keys::ofVehicle($vehicle);
        $threads = Thread::withoutGlobalScope(City::SCOPE)->withAnyKey($keys)->whereNull('vehicle_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->get()->reject(fn (Thread $t) => self::otherThread($vehicle, $t));
        foreach ($threads as $thread) {
            $this($thread, $vehicle, $files);
        }

        return $threads->count();
    }

    public function forOffer(Offer $offer): int
    {
        $keys = Keys::ofOffer($offer);
        $threads = Thread::withoutGlobalScope(City::SCOPE)->withAnyKey($keys)->whereNull('offer_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->get()->reject(fn (Thread $t) => self::otherThread($offer, $t));
        foreach ($threads as $thread) {
            $this($thread, $offer);
        }

        return $threads->count();
    }

    /** Письмо о другой машине, чем эта: VIN письма далёк от её VIN или марка другая (`ScanFields::otherCar`). */
    public static function otherCar(Offer|Vehicle $car, Message $message): bool
    {
        $fields = $message->fields();

        return ScanFields::otherCar($car->vin, $car->brand_id, $fields['vin']['value'] ?? null, $fields['brand']['value'] ?? null);
    }

    /** Ветка о другой машине: в её номерах есть VIN, и ни один не близок к VIN машины. */
    private static function otherThread(Offer|Vehicle $car, Thread $thread): bool
    {
        $vins = array_map(fn ($k) => substr($k, 4), array_filter($thread->keys ?? [], fn ($k) => str_starts_with($k, 'vin:')));

        return $vins && collect($vins)->every(fn ($vin) => ScanFields::otherCar($car->vin, null, $vin, null));
    }

    /** Отвязать руками: ссылка обнуляется, автопривязка по номеру или VIN эту ветку больше не трогает; файлы и blobs остаются. */
    public function unlink(Thread $thread): void
    {
        $thread->update(['offer_id' => null, 'vehicle_id' => null, 'unlinked_at' => now()]);
    }

    public function auto(Message $message): Offer|Vehicle|null
    {
        $thread = $message->thread;
        if (! $thread) {
            return null;
        }
        if ($message->account->scope === Scope::Park) {
            return $this->autoPark($message, $thread);
        }
        if ($thread->offer_id || $thread->unlinked_at) {
            return null;
        }
        $codes = array_unique([...$this->keyed($message, 'code'), ...array_map(fn ($c) => (string) Code::key($c), $this->matcher->findAll($this->whole($message)))]);
        foreach ($codes as $code) {
            if (($offer = Offer::whereCar('claim_ref_key', $code)->first()) && ! self::otherCar($offer, $message)) {
                $this($thread, $offer);

                return $offer;
            }
        }
        if ($vin = $this->vin($message)) {
            if ($offer = Offer::whereCar('vin', $vin)->latest()->first()) {
                $this($thread, $offer);

                return $offer;
            }
        }
        // Госномер — только у живого предложения и только когда оно одно: письма новых страховых бывают без номера
        // убытка и VIN («Годные остатки Tenet T4 Х871СВ763», 16.10.2026), а машина уже заведена.
        if ($plate = Patterns::plateKey($this->keyed($message, 'plate')[0] ?? null)) {
            $live = Offer::whereCar('plate', $plate)->whereNotIn('offers.state', [OfferState::Archived, OfferState::Cancelled])->limit(2)->get();
            if ($live->count() === 1) {
                $this($thread, $live->first());

                return $live->first();
            }
        }

        return null;
    }

    /** Стоянка: ветка ↔ машина по номеру убытка, VIN или госномеру. */
    private function autoPark(Message $message, Thread $thread): ?Vehicle
    {
        if ($thread->vehicle_id || $thread->unlinked_at) {
            return null;
        }
        $fields = $message->fields();
        // Только живая ТС: письмо о выданной или отменённой — новый заезд, ему место в «Из писем».
        $live = fn () => Vehicle::withoutGlobalScope(City::SCOPE)->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vehicle = null;
        if ($code = $fields['code']['value'] ?? null) {
            $vehicle = $live()->whereCar('claim_ref_key', Vehicle::keyFor($code))->first();
            $vehicle = $vehicle && self::otherCar($vehicle, $message) ? null : $vehicle;
        }
        $vehicle ??= ($vin = $this->vin($message)) ? $live()->whereCar('vin', $vin)->first() : null;
        $vehicle ??= ($plate = Patterns::plateKey($fields['plate']['value'] ?? null)) ? $live()->whereCar('plate', $plate)->first() : null;
        if ($vehicle) {
            $this($thread, $vehicle);
        }

        return $vehicle;
    }

    /** VIN письма: прочитанный `ReadLetter`, иначе первый во всём письме с цитатой (`Patterns::vin` — без заглушек). */
    private function vin(Message $message): ?string
    {
        return $this->keyed($message, 'vin')[0] ?? Patterns::vin($this->whole($message));
    }

    private function whole(Message $message): string
    {
        return $message->subject."\n".($message->text_body ?: strip_tags((string) $message->html_body));
    }

    /** Номера письма одного вида (`code`, `vin`, `plate`) без приставки. @return list<string> */
    private function keyed(Message $message, string $kind): array
    {
        return array_values(array_map(fn ($k) => substr($k, strlen($kind) + 1), array_filter($message->keys(), fn ($k) => str_starts_with($k, $kind.':'))));
    }
}
