<?php

namespace App\Billing\Jobs;

use App\Billing\Party;
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
        self::fullerName($media);
    }

    /**
     * ФИО со скана полнее вписанного — встаёт само (05.11.2026: у покупателя стояло имя аккаунта «Роман Мирошников», скан
     * прочёл «Мирошников Роман Игоревич», в письмо поставщику ушло без отчества). Только когда все слова вписанного есть в
     * прочитанном: другое имя — не исправление, его решает человек. Шторка делает то же, пока открыта.
     */
    public static function fullerName(Media $media): void
    {
        $party = $media->model;
        $read = trim((string) ($media->getCustomProperty('fields')['name'] ?? ''));
        if (! $party instanceof Party || $read === '') {
            return;
        }
        $words = fn (?string $v) => array_values(array_filter(preg_split('/\s+/u', str_replace('ё', 'е', mb_strtolower((string) $v))) ?: []));
        [$had, $got] = [$words($party->name), $words($read)];
        if ($had && count($got) > count($had) && ! array_diff($had, $got)) {
            $party->update(['name' => $read]);
        }
    }

    public function failed(): void
    {
        Media::find($this->mediaId)?->setCustomProperty('read', 'failed')->save();
    }
}
