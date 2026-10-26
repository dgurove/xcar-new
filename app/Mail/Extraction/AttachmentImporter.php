<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Message;
use App\Media\DocumentScan;
use App\Media\PdfPhotos;
use App\Media\PhotoIngest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вложения письма → медиатека машины: документы как есть, фото через приём
 * (листы со снимками режет он же), архивы распаковываются. Что уже лежит (по
 * отпечатку `sha` исходника) — пропускается: повторное письмо, ручная
 * загрузка того же файла или второй запуск дублей не дают.
 */
final class AttachmentImporter
{
    public function __construct(private AttachmentClassifier $classifier, private ArchivePhotoExtractor $archives, private PhotoIngest $photos) {}

    /**
     * @param  array|callable(Attachment): array  $photoProperties  свойства кадра: одни на всех (оффер) либо по письму
     *                                                              вложения (стоянка: чьё письмо — такая и стадия)
     * @param  array  $documentProperties  свойства документов (ответ страховой по сделке: `managers`, `letter`)
     * @return array{photos: int, documents: int} сколько добавлено
     */
    public function import(Model $model, Collection $attachments, string $photosCollection, string $documentsCollection, array|callable $photoProperties = [], ?callable $progress = null, array $documentProperties = []): array
    {
        $added = ['photos' => 0, 'documents' => 0];
        if ($attachments->isEmpty()) {
            return $added;
        }
        $classified = $this->classifier->classify($attachments);
        $propertiesOf = is_array($photoProperties) ? fn () => $photoProperties : $photoProperties;
        $sources = [];
        foreach ($classified['documents'] as $document) {
            // PDF со снимками по кадру на страницу — не документ, а фото машины (`PdfPhotos`).
            if ($photosCollection !== '' && ($pages = $this->pdfPhotos($document->filename, fn () => $document->contents()))) {
                foreach ($pages as $page) {
                    $sources[] = ['name' => $page['name'], 'contents' => $page['contents'], 'properties' => $propertiesOf($document)];
                }

                continue;
            }
            $added['documents'] += (int) $this->addDocument($model, $documentsCollection, $document, $documentProperties);
        }
        foreach ($classified['photos'] as $photo) {
            if (($contents = $photo->contents()) !== null) {
                $sources[] = ['name' => $photo->filename, 'contents' => $contents, 'properties' => $propertiesOf($photo)];
            }
        }
        foreach ($classified['archives'] as $i => $archive) {
            $progress && $progress('Распаковываем архив', $i, count($classified['archives']));
            // Архив разворачивается целиком: кадры — к фото, остальное (PDF, СТС, Excel) — к документам;
            // не открылся (битый, с паролем) — лежит документом, как пришёл.
            $extracted = $this->archives->extractFiles($archive);
            if (! $extracted) {
                $added['documents'] += (int) $this->addDocument($model, $documentsCollection, $archive, $documentProperties);

                continue;
            }
            // Раньше не открылся и лёг документом (rar без кодека до 17.10.2026) — теперь развёрнут: архив в документах
            // не нужен, документы из него ляжут ниже по одному, их прочтёт «✨».
            if ($documentsCollection !== '' && ($sha = $archive->blob_sha ?: hash('sha256', (string) $archive->contents()))) {
                $model->media()->where('collection_name', $documentsCollection)->where('custom_properties->sha', $sha)->get()->each->delete();
            }
            // Кадры из архива и вырезки из листа — свойства того вложения, в котором они приехали.
            $this->unpack($model, $extracted, $documentsCollection, $documentProperties, 'mail', $propertiesOf($archive), false, $sources, $added);
        }
        $this->placePhotos($model, $sources, $photosCollection, $documentsCollection, $documentProperties, 'mail', $progress, $added);

        return $added;
    }

    /**
     * Только документы, которые уже лежат у нас (закреплены при приходе письма): их кладут сразу при «Завести», пока
     * человек открывает редактор. Документ без файла на диске, архивы и фото — работа `ImportThreadFiles`.
     *
     * @return int сколько добавлено
     */
    public function importDocuments(Model $model, Collection $attachments, string $collection): int
    {
        $added = 0;
        foreach ($this->classifier->classify($attachments)['documents'] as $document) {
            // PDF со снимками — фото: его разбирает задача вместе с остальными кадрами.
            if (! $document->isOnDisk() || $this->isPhotoPdf($document)) {
                continue;
            }
            $added += (int) $this->addDocument($model, $collection, $document);
        }

        return $added;
    }

    /** Кадры PDF со снимками по страницам; null — обычный документ. Содержимое читается, только если это PDF. */
    private function pdfPhotos(?string $name, callable $contents): ?array
    {
        if (! $this->isPdfName($name)) {
            return null;
        }
        try {
            $data = $contents();

            return $data === null ? null : PdfPhotos::extract($data, (string) $name);
        } catch (Throwable $e) {
            Log::warning('PDF со снимками не разобран', ['file' => $name, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** PDF со снимками (не разбирая): при «Завести» его не кладут документом — кадры из него достанет задача. */
    private function isPhotoPdf(Attachment $document): bool
    {
        return $this->isPdfName($document->filename) && PdfPhotos::looksLike((string) $document->contents());
    }

    private function isPdfName(?string $name): bool
    {
        return str_ends_with(mb_strtolower((string) $name), '.pdf');
    }

    /** Сколько фото и архивов ветки ещё прикрепит `ImportThreadFiles` — заглушки в ряду фото, пока она не дошла. */
    public function photosToCome(Collection $attachments): int
    {
        $classified = $this->classifier->classify($attachments);

        return count($classified['photos']) + count($classified['archives'])
            + count(array_filter($classified['documents'], fn (Attachment $a) => $a->isOnDisk() && $this->isPhotoPdf($a)));
    }

    /**
     * Архив с диска (брошен руками в «Фотографии»): кадры — к фото, остальное (PDF, СТС, Excel) — к документам, как архив
     * письма; что уже лежит — пропускается. Не открылся (битый, с паролем) — null.
     * `photoProperties` — свойства кадров (`hidden` — не в продажу); `imagesAsDocuments` — картинки тоже документами:
     * архив ответа страховой по идущей сделке (паспорт, СТС картинками — менеджеру, а не кадрами машины, 10.10.2026).
     *
     * @return array{photos: int, documents: int}|null
     */
    public function importArchive(Model $model, string $path, string $photosCollection, string $documentsCollection, ?callable $progress = null, array $documentProperties = [], ?string $source = null,
        array $photoProperties = [], bool $imagesAsDocuments = false): ?array
    {
        $extracted = $this->archives->extractPath($path);
        if (! $extracted) {
            return null;
        }
        $added = ['photos' => 0, 'documents' => 0];
        $sources = [];
        $progress && $progress('Разбираем архив', 0, count($extracted));
        $this->unpack($model, $extracted, $documentsCollection, $documentProperties, $source, $photoProperties, $imagesAsDocuments, $sources, $added);
        $this->placePhotos($model, $sources, $photosCollection, $documentsCollection, $documentProperties, $source, $progress, $added);

        return $added;
    }

    /**
     * Развёрнутый архив — один путь для письма и для архива с диска (26.10.2026, приборка: у брошенного руками PDF со
     * снимками ложился документом): кадр — в `$sources`, PDF со снимками по странице — тоже кадрами (`PdfPhotos`),
     * остальное (PDF, СТС, Excel) — документами. `imagesAsDocuments` — картинки тоже документами (ответ страховой).
     */
    private function unpack(Model $model, array $extracted, string $documentsCollection, array $documentProperties, ?string $source,
        array $photoProperties, bool $imagesAsDocuments, array &$sources, array &$added): void
    {
        foreach ($extracted as $file) {
            if ($file['photo'] && ! $imagesAsDocuments && ! $this->classifier->looksLikeDocument(mb_strtolower($file['name']))) {
                $sources[] = ['name' => $file['name'], 'contents' => $file['contents'], 'properties' => $photoProperties];
            } elseif (! $imagesAsDocuments && ($pages = $this->pdfPhotos($file['name'], fn () => $file['contents']))) {
                foreach ($pages as $page) {
                    $sources[] = ['name' => $page['name'], 'contents' => $page['contents'], 'properties' => $photoProperties];
                }
            } else {
                $added['documents'] += (int) $this->addDocumentFile($model, $documentsCollection, $file['name'], $file['contents'], $source, $documentProperties);
            }
        }
    }

    /** Кадры к машине: скан документа среди них — в документы (`DocumentScan`), лист со снимками режет сам приём. */
    private function placePhotos(Model $model, array $sources, string $photosCollection, string $documentsCollection, array $documentProperties,
        ?string $source, ?callable $progress, array &$added): void
    {
        foreach ($sources as $i => $photo) {
            $progress && $progress('Разбираем фотографии', $i, count($sources));
            // Скан документа среди кадров (заявка Альфы картинкой, 12.10.2026) — в документы: там его прочтёт «✨» и
            // автозаполнение, а в ленте фото машины ему не место.
            if ($documentsCollection !== '' && DocumentScan::is($photo['contents'])) {
                $added['documents'] += (int) $this->addDocumentFile($model, $documentsCollection, $photo['name'], $photo['contents'], $source, $documentProperties);

                continue;
            }
            $this->guard(function () use ($model, $photosCollection, $photo, &$added) {
                $added['photos'] += (int) $this->addPhoto($model, $photosCollection, $photo['contents'], $photo['name'], $photo['properties']);
            });
        }
    }

    private function addPhoto(Model $model, string $collection, string $contents, string $name, array $properties): bool
    {
        if ($model->hasFile(hash('sha256', $contents))) {
            return false;
        }
        $this->photos->fromString($model, $collection, $contents, $name, $properties);

        return true;
    }

    /** Вложения письма-кандидата и всех писем той же ветки; одинаковые файлы (по содержимому) — один раз. */
    public function attachmentsOf(?int $messageId, ?int $threadId): Collection
    {
        if (! $messageId && ! $threadId) {
            return collect();
        }

        // Письма старше «Файлы из писем с» у ящика в дело не едут: их файлы остаются в ящике.
        return Message::with(['attachments', 'account'])->where(fn ($q) => $q->when($messageId, fn ($q) => $q->whereKey($messageId))->when($threadId, fn ($q) => $q->orWhere('thread_id', $threadId)))
            ->get()->reject(fn (Message $m) => $m->filesFrozen())
            // Письмо остаётся при вложении: по нему видно, чей это кадр.
            ->flatMap(fn (Message $m) => $m->attachments->each(fn (Attachment $a) => $a->setRelation('message', $m)))
            ->unique(fn (Attachment $a) => $a->blob_sha ?? 'id:'.$a->id)->values();
    }

    /** Один файл документом (скачан по ссылке из письма, не архив). */
    public function importDocument(Model $model, string $collection, string $name, string $contents, ?string $source = null, array $properties = []): bool
    {
        return $this->addDocumentFile($model, $collection, $name, $contents, $source, $properties);
    }

    private function addDocument(Model $model, string $collection, Attachment $document, array $properties = []): bool
    {
        // Пустая коллекция — документы этому получателю не нужны (кандидат: только кадры).
        if ($collection === '') {
            return false;
        }
        $contents = $document->contents();

        return $contents !== null && $this->addDocumentFile($model, $collection, $document->filename, $contents, 'mail', $properties);
    }

    /**
     * `source: null` — файл не из письма (архив, загруженный руками). Файл уже лежит, а свойства есть (ответ страховой
     * открывает его менеджеру) — свойства ложатся на тот, что лежит: кто первым положил файл, не важно.
     */
    private function addDocumentFile(Model $model, string $collection, string $name, string $contents, ?string $source = 'mail', array $properties = []): bool
    {
        if ($collection === '') {
            return false;
        }
        if ($model->hasFile($sha = hash('sha256', $contents))) {
            if ($properties) {
                $model->media()->where('collection_name', $collection)->where('custom_properties->sha', $sha)->get()
                    ->each(fn ($m) => $m->forceFill(['custom_properties' => [...$m->custom_properties, ...$properties]])->save());
            }

            return false;
        }
        $this->guard(fn () => $model->addMediaFromString($contents)
            ->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name) ?: 'dokument')
            ->usingName(pathinfo($name, PATHINFO_FILENAME))
            ->withCustomProperties(array_filter(['sha' => $sha, 'kind' => AttachmentClassifier::kindOf($name), 'source' => $source]) + $properties)
            ->toMediaCollection($collection));

        return true;
    }

    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::warning('Вложение письма не перенеслось', ['error' => $e->getMessage()]);
        }
    }
}
