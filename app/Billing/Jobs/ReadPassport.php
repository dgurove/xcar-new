<?php

namespace App\Billing\Jobs;

use App\Billing\Passport\PassportScan;
use App\Billing\Passport\PassportText;
use App\Mail\Extraction\DocumentText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Прочитать скан страницы паспорта и разобрать поля (11.10.2026): текст — `ocr` на нашем сервере (`DocumentText`, кэш по
 * отпечатку), поля — `PassportText`. Итог — в свойствах медиа: `read` (reading | done | failed), `fields`, `checked`
 * (сошлись контрольные цифры зоны). Шторку паспорта заполняет опрос `/parties/{party}/passport/live`.
 */
final class ReadPassport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $mediaId)
    {
        $this->onConnection('database-scan')->onQueue('scan');
    }

    public function handle(): void
    {
        $media = Media::find($this->mediaId);
        if (! $media) {
            return;
        }
        $read = false;
        DocumentText::read([new PassportScan($media)], function ($file, ?string $text) use ($media, &$read) {
            $read = true;
            $result = $media->getCustomProperty('page') === 'reg' ? PassportText::registration((string) $text) : PassportText::main((string) $text);
            $media->setCustomProperty('read', $text === null ? 'failed' : 'done')
                ->setCustomProperty('fields', $result['fields'])
                ->setCustomProperty('checked', $result['checked'])
                ->save();
        }, $this->timeout - 60);
        if (! $read) {
            $media->setCustomProperty('read', 'failed')->save();
        }
    }

    public function failed(): void
    {
        Media::find($this->mediaId)?->setCustomProperty('read', 'failed')->save();
    }
}
