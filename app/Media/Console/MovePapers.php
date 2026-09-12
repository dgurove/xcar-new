<?php

namespace App\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Документы, залитые до разделения дисков, переезжают с публичного media на закрытый private. Один раз при выкладке. */
final class MovePapers extends Command
{
    protected $signature = 'media:move-private';

    protected $description = 'Переносит документы (papers) с публичного диска на закрытый';

    public function handle(): int
    {
        $from = Storage::disk('media');
        $to = Storage::disk('private');
        $moved = 0;
        foreach (Media::where('collection_name', 'papers')->where('disk', 'media')->cursor() as $media) {
            $dir = (string) $media->id;
            foreach ($from->allFiles($dir) as $file) {
                $to->put($file, $from->readStream($file));
            }
            $from->deleteDirectory($dir);
            $media->forceFill(['disk' => 'private', 'conversions_disk' => 'private'])->save();
            $moved++;
        }
        $this->line("Перенесено: {$moved}");

        return self::SUCCESS;
    }
}
