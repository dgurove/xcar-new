<?php

namespace App\Purchases\Jobs;

use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Media\PhotoIngest;
use App\Purchases\Car;
use App\Purchases\Carcade;
use App\Purchases\Gone;
use App\Purchases\ImportState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Фото из облака поставщика: поштучно (архивом папку качать нельзя), до
 * потолка 40 кадров, каждый ужимается до 1600 webp и оригинал не хранится.
 * На диске одновременно лежит один оригинал.
 *
 * Выкачка идёт до получаса, и перезапуск воркера на выкладке убивает её
 * посреди папки. Поэтому попыток три: уже забранные кадры пропускаются по
 * имени, и повтор просто продолжает с места обрыва. Если и третья не
 * дошла до конца — `failed()` пишет это на машину, в «идёт» она не остаётся.
 */
final class FetchPhotos implements ShouldQueue
{
    use Queueable;

    public const LIMIT = 40;

    public int $timeout = 1800;

    public int $tries = 3;

    public array $backoff = [120, 600];

    public function __construct(public int $carId, public bool $all = false, public array $pictures = [])
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(Carcade $carcade, PhotoIngest $ingest, ArchivePhotoExtractor $archives): void
    {
        $car = Car::find($this->carId);
        if (! $car) {
            return;
        }
        if ($this->pictures) {
            $this->fromSite($car, $carcade, $ingest);

            return;
        }
        if (! $car->cloud_url) {
            return;
        }
        $car->forceFill(['photos_state' => ImportState::Running])->save();
        try {
            $files = $carcade->cloudFiles($car->cloud_url);
        } catch (Gone $e) {
            $car->forceFill(['photos_state' => ImportState::Gone, 'photos_error' => $e->getMessage(), 'photos_at' => now()])->save();

            return;
        } catch (Throwable $e) {
            $car->forceFill(['photos_state' => ImportState::Failed, 'photos_error' => Str::limit($e->getMessage(), 280), 'photos_at' => now()])->save();

            return;
        }

        $limit = $this->all ? 0 : self::LIMIT;
        $isImage = fn ($f) => preg_match('/\.(jpe?g|png|webp|heic)$/i', $f['name']) || str_starts_with((string) $f['type'], 'image/');
        $isArchive = fn ($f) => ArchivePhotoExtractor::isArchiveName($f['name']);
        $photos = array_values(array_filter($files, $isImage));
        $packed = array_values(array_filter($files, $isArchive));
        $leftovers = array_values(array_map(fn ($f) => ['name' => $f['name'], 'bytes' => $f['bytes']], array_filter($files, fn ($f) => ! $isImage($f) && ! $isArchive($f))));
        if (! $photos && ! $packed) {
            $car->forceFill(['photos_state' => ImportState::Skipped, 'photos_error' => 'В папке нет фотографий', 'photos_at' => now(), 'cloud_leftovers' => $leftovers])->save();

            return;
        }

        $already = $car->photos()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $have = count($already);
        $failed = 0;
        usort($photos, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($photos as $file) {
            if ($limit && $have >= $limit) {
                break;
            }
            $stem = mb_strtolower(pathinfo($file['name'], PATHINFO_FILENAME));
            if (in_array($stem, $already, true)) {
                continue;
            }
            $temp = tempnam(sys_get_temp_dir(), 'zakupka-');
            try {
                $carcade->download($file['url'], $temp);
                $ingest->add($car, 'photos', $temp, $file['name']);
                $already[] = $stem;
                $have++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning("Закупка: кадр {$file['name']} машины {$car->ref} не забран: ".$e->getMessage());
            } finally {
                @unlink($temp);
            }
        }
        $unpacked = [];
        foreach ($packed as $file) {
            if ($limit && $have >= $limit) {
                break;
            }
            $temp = tempnam(sys_get_temp_dir(), 'zakupka-arch-');
            try {
                $carcade->downloadPacked($car->cloud_url, $file['name'], $temp);
                $archives->walkFile($temp, function (string $name, string $contents) use ($car, $ingest, &$already, &$have, $limit) {
                    if ($limit && $have >= $limit) {
                        return;
                    }
                    $stem = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));
                    if (in_array($stem, $already, true) || strlen($contents) < 20_000) {
                        return;
                    }
                    try {
                        $ingest->fromString($car, 'photos', $contents, $name);
                        $already[] = $stem;
                        $have++;
                    } catch (Throwable) {
                    }
                });
                $unpacked[] = $file['name'];
            } catch (Throwable $e) {
                $failed++;
                $leftovers[] = ['name' => $file['name'], 'bytes' => $file['bytes']];
                Log::warning("Закупка: архив {$file['name']} машины {$car->ref} не разобран: ".$e->getMessage());
            } finally {
                @unlink($temp);
            }
        }

        // Свежим запросом: relation `media` загружена до приёма кадров и их не видит.
        $count = $car->media()->where('collection_name', 'photos')->count();
        $car->unsetRelation('media')->forceFill([
            'photos_count' => $count,
            'cloud_leftovers' => $leftovers,
            'photos_state' => $count === 0 ? ImportState::Skipped : ($failed ? ImportState::Partial : ImportState::Done),
            'photos_error' => $count === 0 ? 'В папке нет фотографий' : ($failed ? "Не забрано кадров: {$failed}" : null),
            'photos_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        $why = $e instanceof MaxAttemptsExceededException ? 'воркер останавливался посреди работы три раза' : ($e?->getMessage() ?: 'воркер остановлен');
        Car::where('id', $this->carId)->where('photos_state', ImportState::Running)
            ->update(['photos_state' => ImportState::Failed, 'photos_error' => Str::limit('Прервано: '.$why, 280), 'photos_at' => now()]);
    }

    private function fromSite(Car $car, Carcade $carcade, PhotoIngest $ingest): void
    {
        foreach (array_slice($this->pictures, 0, 10) as $i => $url) {
            $temp = tempnam(sys_get_temp_dir(), 'zakupka-');
            try {
                $carcade->download($url, $temp);
                $ingest->add($car, 'photos', $temp, 'site-'.($i + 1).'.jpg');
            } catch (Throwable) {
            } finally {
                @unlink($temp);
            }
        }
        $car->forceFill(['photos_count' => $car->media()->where('collection_name', 'photos')->count()])->save();
    }
}
