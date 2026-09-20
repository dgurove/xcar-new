<?php

namespace App\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Разово после выкладки: сжатые версии фото с диска media (HDD) переезжают на hot (SSD), у медиа
 * пишется conversions_disk. Кадры без сжатых версий (очередь их не досчитала) считаются заново.
 */
final class MoveConversionsHot extends Command
{
    protected $signature = 'media:hot {--regenerate : досчитать недостающие сжатые версии}';

    protected $description = 'Переносит сжатые версии фото на диск hot и досчитывает недостающие';

    public function handle(FileManipulator $files): int
    {
        $from = Storage::disk('media');
        $to = Storage::disk('hot');
        $moved = 0;
        $missing = 0;
        foreach (Media::where('disk', 'media')->where(fn ($q) => $q->whereNull('conversions_disk')->orWhere('conversions_disk', '!=', 'hot'))->cursor() as $media) {
            $dir = $media->id.'/conversions';
            foreach ($from->allFiles($dir) as $file) {
                $to->put($file, $from->readStream($file));
            }
            $from->deleteDirectory($dir);
            $media->forceFill(['conversions_disk' => 'hot'])->saveQuietly();
            $moved++;
        }
        $this->line("Перенесено: {$moved}");
        if ($this->option('regenerate')) {
            foreach (Media::where('collection_name', 'photos')->where('conversions_disk', 'hot')->cursor() as $media) {
                $model = $media->model;
                if (! $model || ! method_exists($model, 'registerMediaConversions')) {
                    continue;
                }
                $need = collect(['w320', 'w640', 'w960', 'thumb'])->reject(fn ($c) => $media->hasGeneratedConversion($c));
                if ($need->isEmpty()) {
                    continue;
                }
                $files->createDerivedFiles($media, onlyMissing: true);
                $missing++;
            }
            $this->line("Досчитано: {$missing}");
        }

        return self::SUCCESS;
    }
}
