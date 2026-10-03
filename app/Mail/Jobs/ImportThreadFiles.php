<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Attachment;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Extraction\Intent;
use App\Mail\Thread;
use App\Offers\Events\OfferStateChanged;
use App\Offers\OfferState;
use App\Park\PhotoStage;
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
 * отпечатку `sha` — не дублируется. У предложения с ТС парковки файлы — у ТС
 * (машина одна). В оффер, который уже не черновик, кадры ложатся скрытыми: что
 * на сайте — решает сотрудник глазом в полосе.
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
        // Машина одна: ветка ящика парковки — в медиатеку ТС со стадией кадра; ветка предложения — в предложение, а
        // `SaleMedia` кладёт файл к его ТС («от страховой»), если она есть. Ветка с ТС и чужим предложением — к предложению.
        $vehicle = $thread?->vehicle;
        $offer = $thread?->offer ?? $vehicle?->offer;
        if ($vehicle && $thread->offer_id && $thread->offer_id !== $vehicle->offer_id) {
            $vehicle = null;
        }
        $model = $vehicle ?? $offer;
        if (! $model) {
            return;
        }
        $car = $vehicle ?? $offer->parkVehicle;
        $key = $offer ? self::key($offer->id) : null;
        $report = fn (string $stage, ?int $i = null, ?int $n = null) => $key && Cache::put($key, ['stage' => $stage, 'i' => $i, 'n' => $n], 1800);
        try {
            $report('Забираем файлы из ящика');
            $pin($thread);
            $report('Читаем письмо');
            $attachments = $importer->attachmentsOf($this->messageId, $this->messageId ? null : $thread->id);
            $hidden = $offer && $offer->state !== OfferState::Draft ? ['hidden' => true] : [];
            $properties = $vehicle ? fn (Attachment $a) => self::stageOf($a) + $hidden : $hidden;
            $added = $importer->import($model, $attachments, 'photos', 'papers', $properties, $report);
        } finally {
            $key && Cache::forget($key);
        }
        if ($offer) {
            OfferStateChanged::dispatch($offer->fresh());
        }
        if ($car) {
            $publish->refresh(Topics::PARK, ["/cars/{$car->id}"]);
        }
        if ($added['photos'] || $added['documents']) {
            $what = 'Из письма — '.implode(', ', array_filter([$added['photos'] ? "фото: {$added['photos']}" : null, $added['documents'] ? "документов: {$added['documents']}" : null]));
            $offer && $publish->toast(Topics::STAFF, $what, "/offers/{$offer->number}");
            $car && $publish->toast(Topics::PARK, $what, "/cars/{$car->id}");
        }
    }

    /**
     * Чей кадр: письмо страховой — «от страховой», наше — «при приёме», а наш отчёт о выдаче — «при выдаче».
     * `source` отличает приехавшее из письма от снятого в приложении: при выдаче первое стирается, второе остаётся.
     */
    private static function stageOf(Attachment $attachment): array
    {
        $message = $attachment->message;
        $stage = match (true) {
            ! $message?->isOurs() => PhotoStage::Vendor,
            $message->intent === Intent::Released->value => PhotoStage::Release,
            default => PhotoStage::Intake,
        };

        return ['stage' => $stage->value, 'source' => 'mail'];
    }

    public static function progress(int $offerId): ?array
    {
        return Cache::get(self::key($offerId));
    }

    /** Ключ хода разбора — его же пишет разбор архива, брошенного руками (ImportOfferArchive): пилюля в редакторе одна. */
    public static function key(int $offerId): string
    {
        return "import:offer:{$offerId}";
    }
}
