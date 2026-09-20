<?php

namespace App\Storage\Console;

use App\Mail\Attachment;
use App\Mail\Blobs;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Media\Actions\CoolPhotos;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Purchases\Car;
use App\Purchases\PurchaseState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Ежедневная уборка: всё, что копится молча, — по срокам. Каждая часть
 * печатает, что убрала; с --dry-run только считает.
 */
final class Gc extends Command
{
    protected $signature = 'storage:gc {--dry-run : Только посчитать}';

    protected $description = 'Убирает просроченное: упавшие задачи, кэш, PDF, части писем, отвязанные файлы, холодные конверсии';

    public const FAILED_JOBS_DAYS = 7;

    public const SHARE_DAYS = 30;

    public const MAIL_CACHE_DAYS = 1;

    public const OUTBOX_DAYS = 7;

    public const UNPINNED_DAYS = 30;

    public const COLD_MONTHS = 6;

    private bool $dry = false;

    public function handle(CoolPhotos $cool): int
    {
        $this->dry = (bool) $this->option('dry-run');

        $this->step('упавшие задачи старше '.self::FAILED_JOBS_DAYS.' дн.', function () {
            $count = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(self::FAILED_JOBS_DAYS))->count();
            $this->dry || DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(self::FAILED_JOBS_DAYS))->delete();

            return "{$count} шт.";
        });

        $this->step('просроченный кэш в базе', function () {
            $count = DB::table('cache')->where('expiration', '<', time())->count() + DB::table('cache_locks')->where('expiration', '<', time())->count();
            if (! $this->dry) {
                DB::table('cache')->where('expiration', '<', time())->delete();
                DB::table('cache_locks')->where('expiration', '<', time())->delete();
            }

            return "{$count} ключей";
        });

        $this->step('PDF шеринга старше '.self::SHARE_DAYS.' дн.', fn () => $this->sweep('cache', 'share', self::SHARE_DAYS));
        $this->step('части писем из ящика старше '.self::MAIL_CACHE_DAYS.' дн.', fn () => $this->sweep('cache', 'mail', self::MAIL_CACHE_DAYS));
        $this->step('неотправленные файлы старше '.self::OUTBOX_DAYS.' дн.', fn () => $this->sweep('cache', 'outbox', self::OUTBOX_DAYS));
        $this->step('временные файлы старше суток', fn () => $this->sweep('cache', 'tmp', 1));

        $this->step('файлы писем отвязанных веток старше '.self::UNPINNED_DAYS.' дн.', function () {
            $stale = Attachment::whereNotNull('blob_sha')->where('pinned_at', '<', now()->subDays(self::UNPINNED_DAYS))
                ->whereHas('message', fn ($q) => $q->whereHas('thread', fn ($t) => $t->whereNull('offer_id')->whereNull('vehicle_id')))->get();
            foreach ($stale as $attachment) {
                if ($this->dry) {
                    continue;
                }
                $sha = $attachment->blob_sha;
                $attachment->forceFill(['blob_sha' => null, 'pinned_at' => null])->save();
                Blobs::release($sha);
            }

            return $stale->count().' шт.';
        });

        $this->step('кадры карточек кандидатов, ушедших в архив больше '.self::UNPINNED_DAYS.' дн. назад', function () {
            $stale = Candidate::where('state', CandidateState::Rejected)->where('updated_at', '<', now()->subDays(self::UNPINNED_DAYS))
                ->whereHas('media', fn ($m) => $m->where('collection_name', 'card'))->get();
            foreach ($stale as $candidate) {
                $this->dry || $candidate->clearMediaCollection('card');
            }

            return $stale->count().' шт.';
        });

        $this->step('файлы blobs без ссылок', function () {
            $disk = Storage::disk(Blobs::DISK);
            $orphans = 0;
            $bytes = 0;
            foreach ($disk->allFiles('blobs') as $file) {
                $sha = basename($file);
                if (strlen($sha) !== 64 || Attachment::where('blob_sha', $sha)->exists()) {
                    continue;
                }
                $orphans++;
                $bytes += (int) $disk->size($file);
                $this->dry || $disk->delete($file);
            }

            return "{$orphans} шт., ".self::human($bytes);
        });

        $this->step('чистые копии кадров под знаком, у которых кадра уже нет', function () {
            $disk = Storage::disk('private');
            $orphans = 0;
            $bytes = 0;
            foreach ($disk->files('clean') as $file) {
                $id = (int) pathinfo($file, PATHINFO_FILENAME);
                if ($id && Media::whereKey($id)->exists()) {
                    continue;
                }
                $orphans++;
                $bytes += (int) $disk->size($file);
                $this->dry || $disk->delete($file);
            }

            return "{$orphans} шт., ".self::human($bytes);
        });

        $this->step('файлы конверсий, которых в коде больше нет', function () {
            $count = 0;
            $bytes = 0;
            foreach (Media::whereRaw("generated_conversions::jsonb ?? 'w1440'")->cursor() as $m) {
                $path = CoolPhotos::conversionsDir($m).'/'.pathinfo($m->file_name, PATHINFO_FILENAME).'-w1440.webp';
                if (is_file($path)) {
                    $count++;
                    $bytes += (int) filesize($path);
                    $this->dry || @unlink($path);
                }
                if (! $this->dry) {
                    $generated = (array) $m->generated_conversions;
                    unset($generated['w1440']);
                    $m->forceFill(['generated_conversions' => $generated])->saveQuietly();
                }
            }

            return "{$count} файлов, ".self::human($bytes);
        });

        $this->step('конверсии у машин, закрытых больше '.self::COLD_MONTHS.' мес. назад', function () use ($cool) {
            $since = now()->subMonths(self::COLD_MONTHS);
            $offers = Offer::whereIn('state', [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered])->where('updated_at', '<', $since)->pluck('id');
            // Закрытые — открытые с давно прошедшим сроком: отдельного состояния «закрыта» нет.
            $cars = Car::whereHas('purchase', fn ($q) => $q->where('updated_at', '<', $since)->where(fn ($w) => $w
                ->where('state', PurchaseState::Archived)
                ->orWhere(fn ($o) => $o->where('state', PurchaseState::Open)->where('offers_close_at', '<', $since))))->pluck('id');
            $media = Media::where('collection_name', 'photos')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('model_type', Offer::class)->whereIn('model_id', $offers))
                ->orWhere(fn ($q) => $q->where('model_type', Car::class)->whereIn('model_id', $cars)))
                ->whereRaw("generated_conversions::text <> '[]' and generated_conversions::text <> '{}'")->cursor();
            $stat = $cool($media, $this->dry);

            return "{$stat['media']} кадров, {$stat['files']} файлов, ".self::human($stat['bytes']);
        });

        return self::SUCCESS;
    }

    /** Файлы каталога на диске старше N дней — стереть, пустые подкаталоги — тоже. */
    private function sweep(string $disk, string $dir, int $days): string
    {
        $storage = Storage::disk($disk);
        $deadline = now()->subDays($days)->timestamp;
        $count = 0;
        $bytes = 0;
        foreach ($storage->allFiles($dir) as $file) {
            if ($storage->lastModified($file) >= $deadline) {
                continue;
            }
            $count++;
            $bytes += (int) $storage->size($file);
            $this->dry || $storage->delete($file);
        }
        if (! $this->dry) {
            foreach (array_reverse($storage->allDirectories($dir)) as $sub) {
                if (! $storage->files($sub) && ! $storage->directories($sub)) {
                    $storage->deleteDirectory($sub);
                }
            }
        }

        return "{$count} файлов, ".self::human($bytes);
    }

    private function step(string $title, callable $do): void
    {
        $this->line(($this->dry ? '[посчитано] ' : '').$title.': '.$do());
    }

    public static function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1 << 30 => round($bytes / (1 << 30), 2).' ГБ',
            $bytes >= 1 << 20 => round($bytes / (1 << 20), 1).' МБ',
            $bytes >= 1 << 10 => round($bytes / (1 << 10)).' КБ',
            default => $bytes.' Б',
        };
    }
}
