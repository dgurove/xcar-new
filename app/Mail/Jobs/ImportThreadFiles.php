<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Thread;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Ветка привязана к машине — её файлы в медиатеке этой машины. Одна джоба на
 * все случаи: привязка сама по коду или VIN, руками, «Завести» из кандидата,
 * новое письмо в уже привязанной ветке. Сначала закрепить вложения в blobs
 * (письма страховых — по 30 кадров, из ящика это минуты), потом разобрать:
 * документы в `papers`, фото и архивы в `photos`. Что уже лежит — по
 * отпечатку `sha` — не дублируется. В оффер, который уже не черновик, кадры
 * ложатся скрытыми: что на сайте — решает сотрудник глазом в полосе.
 */
final class ImportThreadFiles implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    /** @param ?int $messageId только одно письмо (пришло в привязанную ветку); null — вся ветка */
    public function __construct(public int $threadId, public ?int $messageId = null)
    {
        $this->onConnection('database-long')->onQueue('long')->afterCommit();
    }

    /** Замок на ветку и письмо: ждущая джоба одного письма не должна глотать джобу следующего. */
    public function uniqueId(): string
    {
        return $this->threadId.':'.($this->messageId ?? 'all');
    }

    public function handle(PinThread $pin, AttachmentImporter $importer, Publisher $publish): void
    {
        $thread = Thread::find($this->threadId);
        $model = $thread?->offer ?? $thread?->vehicle;
        if (! $model) {
            return;
        }
        $offer = $model instanceof Offer ? $model : null;
        $key = $offer ? self::key($offer->id) : null;
        $report = fn (string $stage, ?int $i = null, ?int $n = null) => $key && Cache::put($key, ['stage' => $stage, 'i' => $i, 'n' => $n], 1800);
        try {
            $report('Забираем файлы из ящика');
            $pin($thread);
            $report('Читаем письмо');
            $attachments = $importer->attachmentsOf($this->messageId, $this->messageId ? null : $thread->id);
            $hidden = $offer && $offer->state !== OfferState::Draft;
            $added = $importer->import($model, $attachments, 'photos', 'papers', $hidden ? ['hidden' => true] : ($model instanceof Vehicle ? ['stage' => 'mail'] : []), $report);
        } finally {
            $key && Cache::forget($key);
        }
        if ($offer) {
            OfferStateChanged::dispatch($offer->fresh());
        } elseif ($model instanceof Vehicle) {
            $publish->refresh(Topics::PARK, ["/cars/{$model->id}"]);
        }
        if ($added['photos'] || $added['documents']) {
            $what = array_filter([$added['photos'] ? "фото: {$added['photos']}" : null, $added['documents'] ? "документов: {$added['documents']}" : null]);
            $publish->toast($offer ? Topics::STAFF : Topics::PARK, 'Из письма — '.implode(', ', $what), $offer ? "/offers/{$offer->number}" : "/cars/{$model->id}");
        }
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
