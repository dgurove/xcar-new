<?php

namespace App\Offers\Jobs;

use App\Mail\Actions\PinThread;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Extraction\CloudShare;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Media\Hidden;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Файлы ответа страховой по сделке (05.10.2026): вложения письма и всё, что лежит по ссылке на облако из его текста
 * (Т-Страхование шлёт архив на data.tbank.ru с паролем в письме, `CloudShare`). Документы ложатся к машине с письмом, с
 * которым пришли, — **без** «Видно менеджеру» (15.10.2026, владелец: в них вендор и закупочная — оценка, бланки
 * страховой); нужное менеджеру (паспорт, СТС) админ открывает отметкой. Не скачалось — запись в журнале предложения.
 */
final class ShareReplyFiles implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    public function __construct(public int $messageId)
    {
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    public function handle(PinThread $pin, AttachmentImporter $importer, CloudShare $cloud): void
    {
        $message = Message::with(['thread', 'attachments', 'account'])->find($this->messageId);
        [$model, , $offer] = ImportThreadFiles::target($message?->thread);
        if (! $model || ! $offer instanceof Offer) {
            return;
        }
        $documents = ['letter' => $message->id];
        $photos = Hidden::incoming($offer);
        // Идёт сделка — картинки ответа (во вложениях и в архиве по ссылке) это документы, а не кадры машины.
        $dealing = $offer->deal()->exists();
        if ($message->files()->isNotEmpty()) {
            $pin($message->thread);
            $attachments = $importer->attachmentsOf($message->id, null);
            // Идёт сделка — картинки ответа это документы, а не кадры машины: фото паспорта, СТС (10.10.2026, Т-Страхование
            // прислало паспорт картинкой, и он лёг скрытым кадром — менеджер его не видел).
            if ($dealing) {
                [$images, $attachments] = $attachments->partition(fn ($a) => ! $a->is_inline && str_starts_with(mb_strtolower((string) $a->mime), 'image/'));
                foreach ($images as $image) {
                    if (($contents = $image->contents()) !== null) {
                        self::photoToDocument($model, hash('sha256', $contents), $documents);
                        $importer->importDocument($model, 'papers', (string) $image->filename, $contents, 'mail', $documents);
                    }
                }
            }
            $importer->import($model, $attachments, 'photos', 'papers', $photos, null, $documents);
        }
        foreach (CloudShare::links($message->replyText(marks: true)) as $link) {
            $path = sys_get_temp_dir().'/cloud-'.Str::uuid();
            try {
                $name = $cloud->download($link, $path);
                $added = str_ends_with(mb_strtolower($name), '.zip')
                    ? $importer->importArchive($model, $path, 'photos', 'papers', null, $documents, 'mail', $photos, imagesAsDocuments: $dealing)
                    : ['documents' => (int) $importer->importDocument($model, 'papers', $name, (string) file_get_contents($path), 'mail', $documents), 'photos' => 0];
                $offer->log(OfferEventType::Note, null, ['text' => 'Файлы по ссылке из письма страховой: '.($added === null ? 'архив не открылся' : 'документов '.$added['documents'].', фото '.$added['photos'])]);
            } catch (Throwable $e) {
                Log::warning('Облако из письма не скачалось', ['message' => $message->id, 'url' => $link['url'], 'error' => $e->getMessage()]);
                $offer->log(OfferEventType::Note, null, ['text' => 'Файлы по ссылке из письма страховой не скачались: '.$e->getMessage()]);
            } finally {
                @unlink($path);
            }
        }
        OfferStateChanged::dispatch($offer->fresh());
    }

    /**
     * Та же картинка уже легла скрытым кадром — разбор всей ветки (`ImportThreadFiles`) успел раньше: переносим её в
     * документы, а не пропускаем как «уже есть» (иначе паспорт оставался кадром, который он не видит).
     */
    private static function photoToDocument(Model $model, string $sha, array $properties): void
    {
        $model->media()->where('collection_name', 'photos')->where('custom_properties->sha', $sha)->get()
            ->each(function (Media $m) use ($model, $properties) {
                // Файл кадра — на публичном диске, документы — на закрытом: переносом (копия в «papers» и удаление кадра).
                $moved = $m->move(method_exists($model, 'carRecord') ? $model->carRecord() : $model, 'papers');
                $moved->forceFill(['custom_properties' => [...array_diff_key($moved->custom_properties, ['hidden' => true]), ...$properties]])->save();
            });
    }
}
