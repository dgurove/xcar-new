<?php

namespace App\Mail\Scan;

use App\Mail\Extraction\AttachmentClassifier;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Support\Docs;
use Illuminate\Support\Collection;

/**
 * Файлы для «✨»: вложения писем и документы предложения (`Paper`) — что умеет прочитать, без повторов (скан в «ч.1»
 * и в пересылке — один, документ письма, перенесённый в предложение, — тоже), документы первыми.
 */
final class Files
{
    /**
     * Подпись файла для людей: «фото», «заявка», «эптс»; имя сканера из одних цифр («20260930142927…») — «скан»;
     * длинное имя («040 ВЫПИСКА ИЗ ЭЛЕКТРОННОГО ПАСПОРТА …») обрезается — оно уходит и в историю дела.
     */
    public static function label(ScanFile $file, ?bool $photo = null): string
    {
        if ($photo ?? $file->isPhoto()) {
            return 'фото';
        }
        $label = Docs::label($file->scanName());

        return preg_match('/^[\d\s_\-\[\]()]+$|^\[?untitled\]?$/iu', $label) ? 'скан' : mb_strimwidth($label, 0, 40, '…');
    }

    /**
     * Повтор узнаётся по отпечатку, без него — по имени и размеру; из двух остаётся вложение письма: прочитанное по
     * нему перечитывает и письмо.
     *
     * @param  iterable<Message>  $messages
     * @param  iterable<Paper>  $papers
     * @return Collection<int, ScanFile>
     */
    public static function of(iterable $messages, iterable $papers = []): Collection
    {
        return collect($messages)->flatMap(fn (Message $m) => $m->files())->concat($papers)
            ->filter(fn (ScanFile $f) => DocumentText::scannable($f))
            ->unique(fn (ScanFile $f) => $f->scanSha() ?: $f->fileKey())
            ->sortBy(fn (ScanFile $f) => $f->isPhoto() ? 1 : 0)
            ->values();
    }

    /**
     * Документы в порядке чтения «Завести» (`x-mail.reader`): первым — где машина вернее всего. СТС и ПТС (VIN, год,
     * цвет), потом заявка («Приложение № 3» Альфы Москва — машина только в нём), акт, прочие сканы, а договор,
     * оценка, соглашение и осмотр — последними. Внутри яруса — порядок писем. Фото сами не читаются.
     *
     * @param  Collection<int, ScanFile>  $files
     * @return Collection<int, ScanFile>
     */
    public static function rank(Collection $files): Collection
    {
        return $files->reject->isPhoto()
            ->sortBy(fn (ScanFile $f) => match (true) {
                in_array($kind = AttachmentClassifier::kindOf($f->scanName()), ['sts', 'pts'], true) => 0,
                (bool) preg_match('/заявк|направлени|приложени\S*\s*№?\s*3(?!\d)/iu', $f->scanName()) => 1,
                $kind === 'act' => 2,
                $kind === null => 3,
                default => 4,
            })
            ->values();
    }
}
