<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Media\PhotoIngest;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Migtorg;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Фото лота Мигторга в предложение — по номеру дела, совпавшему с номером убытка. Кадры по одному: оригинал без их
 * знака, снимать нечего; уже взятые (по uuid) пропускаются, поэтому повтор дочитывает с места обрыва. Ход — пилюлей
 * в шапке редактора (`progress`), по окончании редактор перечитывается сам.
 */
final class FetchMigtorgPhotos implements ShouldQueue
{
    use Queueable;

    public const STATES = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    public int $timeout = 1500;

    public int $tries = 3;

    public array $backoff = [300, 1800];

    public function __construct(public int $offerId, public int $lotId, public bool $manual = false)
    {
        $this->onConnection('database-long')->onQueue('long')->afterCommit();
    }

    /** Ход — своей пилюлей: ключ разбора архива и писем общий, чужая задача стёрла бы его. */
    public static function progress(int $offerId): ?array
    {
        return Cache::get("migtorg:offer:{$offerId}");
    }

    /** Свежий лот по номеру убытка предложения, если он есть в индексе и за фото можно ходить. */
    public static function lotFor(Offer $offer): ?object
    {
        if (! $offer->claim_ref_key) {
            return null;
        }
        $lot = DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->orderByDesc('id')->first();

        return $lot && Migtorg::ready() ? $lot : null;
    }

    /**
     * Лот о той же машине: VIN с обеих сторон — должен совпасть. Номер из одних цифр («7805/2024») бывает у разных
     * страховых, сам такой лот берётся только при совпавшем VIN; кнопкой — можно, в подтверждении название лота.
     */
    public static function sameCar(Offer $offer, object $lot, bool $strict): bool
    {
        $vin = strtoupper(trim((string) $offer->vin));
        if ($vin && $lot->vin) {
            return $vin === $lot->vin;
        }

        return ! $strict || preg_match('/\p{L}/u', $offer->claim_ref_key) === 1;
    }

    /** Лот для кнопки «С Мигторга»: есть, ещё не взят и не о другой машине. */
    public static function forButton(Offer $offer): ?object
    {
        $lot = self::lotFor($offer);

        return $lot && ! $lot->offer_id && self::sameCar($offer, $lot, strict: false) ? $lot : null;
    }

    /** Взять фото лота: лоты с тем же номером помечаются предложением сразу, чтобы синхронизация не ставила задачу второй раз. */
    public static function start(Offer $offer, object $lot, bool $manual = false): void
    {
        DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->update(['offer_id' => $offer->id]);
        self::dispatch($offer->id, $lot->id, $manual);
    }

    /** Сами — только в пустой ряд: к своим кадрам менеджер добавит лот кнопкой, без дублей. */
    public static function auto(Offer $offer): bool
    {
        if (! in_array($offer->state, self::STATES, true) || $offer->media()->where('collection_name', 'photos')->exists()) {
            return false;
        }
        $lot = self::lotFor($offer);
        // Не взялся трижды — сутки сам не берётся: иначе синхронизация ставила бы задачу раз в полчаса без конца.
        if (! $lot || $lot->offer_id || ($lot->failed_at && now()->subDay()->lt($lot->failed_at)) || ! self::sameCar($offer, $lot, strict: true)) {
            return false;
        }
        self::start($offer, $lot);

        return true;
    }

    public function handle(Migtorg $migtorg, PhotoIngest $ingest, Publisher $publish): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer) {
            return;
        }
        $key = "migtorg:offer:{$offer->id}";
        $added = 0;
        $failed = 0;
        try {
            Cache::put($key, ['stage' => 'Фото с Мигторга', 'i' => null, 'n' => null], 1800);
            $uuids = $migtorg->photos($this->lotId);
            $uuids || Log::warning("Мигторг: в карточке лота {$this->lotId} нет фото");
            $have = $offer->media()->where('collection_name', 'photos')->get()
                ->map(fn (Media $m) => $m->getCustomProperty('migtorg'))->filter()->all();
            foreach ($uuids as $i => $uuid) {
                Cache::put($key, ['stage' => 'Фото с Мигторга', 'i' => $i, 'n' => count($uuids)], 1800);
                if (in_array($uuid, $have, true)) {
                    continue;
                }
                $temp = tempnam(sys_get_temp_dir(), 'kadr-');
                try {
                    $migtorg->download($uuid, $temp);
                    $ingest->add($offer, 'photos', $temp, 'migtorg-'.($i + 1).'.jpg', ['migtorg' => $uuid]);
                    $added++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning("Мигторг: кадр {$uuid} лота {$this->lotId} не забран: ".$e->getMessage());
                } finally {
                    @unlink($temp);
                }
            }
        } finally {
            Cache::forget($key);
        }
        OfferStateChanged::dispatch($offer->fresh());
        // Тост — только когда нажали кнопку: сами задачи синхронизации всем сотрудникам не звонят.
        if ($this->manual) {
            $publish->toast(Topics::STAFF, match (true) {
                ! $added => 'С Мигторга нового нет',
                ! $failed => "Фото с Мигторга: {$added}",
                default => "Фото с Мигторга: {$added}, не забрано {$failed}",
            }, "/offers/{$offer->number}");
        }
    }

    public function failed(?Throwable $e): void
    {
        // Лоты номера свободны, как их пометил start(): кнопка «С Мигторга» вернётся, сам — через сутки.
        DB::table('migtorg_lots')->where('offer_id', $this->offerId)->update(['offer_id' => null, 'failed_at' => now()]);
        Log::warning("Мигторг: фото лота {$this->lotId} в предложение {$this->offerId} не взяты: ".$e?->getMessage());
    }
}
