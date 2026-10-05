<?php

namespace App\Offers\Jobs;

use App\Mail\Actions\PinThread;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Extraction\CloudShare;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Файлы ответа страховой по сделке — менеджеру сразу (05.10.2026): вложения письма и всё, что лежит по ссылке на облако
 * из его текста (Т-Страхование шлёт архив на data.tbank.ru с паролем в письме, `CloudShare`). Документы ложатся к
 * предложению (у ТС парковки — к ТС, машина одна) отмеченными «Видно менеджеру» и письмом, с которым пришли: на странице
 * сделки они в документах и под ответом. Не скачалось — запись в журнале предложения, письмо остаётся в почте.
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
        $documents = ['managers' => true, 'letter' => $message->id];
        $photos = $offer->state !== OfferState::Draft ? ['hidden' => true] : [];
        if ($message->files()->isNotEmpty()) {
            $pin($message->thread);
            $importer->import($model, $importer->attachmentsOf($message->id, null), 'photos', 'papers', $photos, null, $documents);
        }
        foreach (CloudShare::links($message->replyText(marks: true)) as $link) {
            $path = sys_get_temp_dir().'/cloud-'.Str::uuid();
            try {
                $name = $cloud->download($link, $path);
                $added = str_ends_with(mb_strtolower($name), '.zip')
                    ? $importer->importArchive($model, $path, 'photos', 'papers', null, $documents, 'mail')
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
}
