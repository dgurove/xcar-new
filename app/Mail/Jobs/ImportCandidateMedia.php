<?php

namespace App\Mail\Jobs;

use App\Mail\Actions\PinThread;
use App\Mail\Candidate;
use App\Mail\Extraction\AttachmentImporter;
use App\Offers\Offer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Фото и документы из письма-кандидата — в черновик. Долго: архивы, обрезка листов. Ход виден по кэшу. */
final class ImportCandidateMedia implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $candidateId, public int $offerId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(AttachmentImporter $importer, PinThread $pin): void
    {
        $candidate = Candidate::find($this->candidateId);
        $offer = Offer::find($this->offerId);
        if (! $candidate || ! $offer) {
            return;
        }
        self::report($offer->id, 'Забираем файлы из ящика');
        try {
            // Файлы письма лежат в ящике; черновику они нужны у нас — сначала закрепить.
            if ($candidate->thread) {
                $pin($candidate->thread);
            }
            self::report($offer->id, 'Читаем письмо');
            $attachments = $importer->attachmentsOf($candidate->message_id, $candidate->thread_id);
            $importer->import($offer, $attachments, 'photos', 'papers', [], fn ($stage, $i = null, $n = null) => self::report($offer->id, $stage, $i, $n));
        } finally {
            Cache::forget(self::key($offer->id));
        }
        \App\Offers\Events\OfferStateChanged::dispatch($offer->fresh());
    }

    public static function report(int $offerId, string $stage, ?int $i = null, ?int $n = null): void
    {
        Cache::put(self::key($offerId), ['stage' => $stage, 'i' => $i, 'n' => $n], 1800);
    }

    public static function progress(int $offerId): ?array
    {
        return Cache::get(self::key($offerId));
    }

    private static function key(int $offerId): string
    {
        return "import:offer:{$offerId}";
    }
}
