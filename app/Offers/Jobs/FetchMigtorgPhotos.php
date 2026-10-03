<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Jobs\ImportThreadFiles;
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
 * разбора файлов в редакторе, по окончании редактор перечитывается сам.
 */
final class FetchMigtorgPhotos implements ShouldQueue
{
    use Queueable;

    public const STATES = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    public int $timeout = 1500;

    public int $tries = 3;

    public array $backoff = [300, 1800];

    public function __construct(public int $offerId, public int $lotId)
    {
        $this->onConnection('database-long')->onQueue('long')->afterCommit();
    }

    /** Свежий лот по номеру убытка предложения, если он есть в индексе. */
    public static function lotFor(Offer $offer): ?object
    {
        return $offer->claim_ref_key && Migtorg::ready() ? DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->orderByDesc('id')->first() : null;
    }

    /** Взять фото лота: лоты с тем же номером помечаются предложением сразу, чтобы синхронизация не ставила задачу второй раз. */
    public static function start(Offer $offer, object $lot): void
    {
        DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->update(['offer_id' => $offer->id]);
        self::dispatch($offer->id, $lot->id);
    }

    /** Сами — только в пустой ряд: к своим кадрам менеджер добавит лот кнопкой, без дублей. */
    public static function auto(Offer $offer): bool
    {
        if (! in_array($offer->state, self::STATES, true) || $offer->media()->where('collection_name', 'photos')->exists()) {
            return false;
        }
        $lot = self::lotFor($offer);
        if (! $lot || $lot->offer_id) {
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
        $key = ImportThreadFiles::key($offer->id);
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
        $publish->toast(Topics::STAFF, $added ? "С Мигторга — фото: {$added}".($failed ? ", не забрано: {$failed}" : '') : 'С Мигторга нового нет', "/offers/{$offer->number}");
    }

    public function failed(?Throwable $e): void
    {
        // Кнопка «С Мигторга» снова появится: лот свободен.
        DB::table('migtorg_lots')->where('id', $this->lotId)->where('offer_id', $this->offerId)->update(['offer_id' => null]);
        Log::warning("Мигторг: фото лота {$this->lotId} в предложение {$this->offerId} не взяты: ".$e?->getMessage());
    }
}
