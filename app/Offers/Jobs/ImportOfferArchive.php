<?php

namespace App\Offers\Jobs;

use App\Cars\Progress;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Jobs\ImportThreadFiles;
use App\Media\Hidden;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Архив, брошенный руками в «Фотографии» предложения (портал страховой отдаёт кадры архивом): в очереди, а не в запросе —
 * сотня кадров разбирается минутами. Кадры — к фото, документы — к документам, как архив письма; ход — той же пилюлей,
 * что разбор писем (`ImportThreadFiles::progressOf`), по окончании редактор перечитывается сам.
 */
final class ImportOfferArchive implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    /** @param string $path файл на диске private; удаляется после разбора */
    public function __construct(public int $offerId, public string $path, public string $name)
    {
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    public function handle(AttachmentImporter $importer, Publisher $publish): void
    {
        $offer = Offer::find($this->offerId);
        $file = Storage::disk('private')->path($this->path);
        if (! $offer) {
            Storage::disk('private')->delete($this->path);

            return;
        }
        // Свой ход в списке задач машины — разбор писем той же машины его не стирает (`Cars\Progress`).
        $task = Progress::ARCHIVE.':'.basename($this->path);
        try {
            Progress::step($offer, $task, 'Распаковываем архив');
            $added = $importer->importArchive($offer, $file, 'photos', 'papers',
                fn (string $stage, ?int $i = null, ?int $n = null) => Progress::step($offer, $task, $stage, $i, $n), photoProperties: Hidden::incoming($offer));
        } finally {
            Progress::done($offer, $task);
            Storage::disk('private')->delete($this->path);
        }
        OfferStateChanged::dispatch($offer->fresh());
        $what = $added === null ? null : array_filter([$added['photos'] ? "фото: {$added['photos']}" : null, $added['documents'] ? "документов: {$added['documents']}" : null]);
        $publish->toast(Topics::STAFF, match (true) {
            $added === null => "Архив {$this->name} не открылся",
            ! $what => "В архиве {$this->name} нового нет",
            default => 'Из архива: '.implode(', ', $what),
        }, "/offers/{$offer->number}", 'photo');
    }
}
