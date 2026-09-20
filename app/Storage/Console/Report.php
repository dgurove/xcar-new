<?php

namespace App\Storage\Console;

use App\Mail\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Чем занят диск: медиа по владельцам, закрытое по каталогам, кэш, база по таблицам. Печатает check.sh. */
final class Report extends Command
{
    protected $signature = 'storage:report';

    protected $description = 'Показывает, чем заняты диски и база';

    public function handle(): int
    {
        $rows = [];
        foreach (Media::query()->selectRaw('model_type, collection_name, count(*) as n, sum(size) as bytes')->groupBy('model_type', 'collection_name')->orderByDesc('bytes')->get() as $r) {
            $rows[] = ['медиа '.class_basename($r->model_type).'/'.$r->collection_name, $r->n.' шт.', Gc::human((int) $r->bytes)];
        }
        $rows[] = ['медиа: всего на диске', '', Gc::human($this->diskBytes('media'))];
        $rows[] = ['hot: сжатые версии и кадры кандидатов (SSD)', '', Gc::human($this->diskBytes('hot'))];

        // Числовые каталоги — медиатека на закрытом диске (документы, файлы просьб): одной строкой.
        $papers = 0;
        foreach (Storage::disk('private')->directories('') as $dir) {
            if (ctype_digit($dir)) {
                $papers += $this->diskBytes('private', $dir);
            } else {
                $rows[] = ['private/'.$dir, '', Gc::human($this->diskBytes('private', $dir))];
            }
        }
        $rows[] = ['private: документы и файлы просьб', '', Gc::human($papers)];
        $blobs = Attachment::whereNotNull('blob_sha')->distinct('blob_sha')->count('blob_sha');
        $rows[] = ['  из них blobs с ссылками', $blobs.' шт.', ''];
        foreach (Storage::disk('cache')->directories('') as $dir) {
            $rows[] = ['cache/'.$dir, '', Gc::human($this->diskBytes('cache', $dir))];
        }

        $tables = DB::select("select relname, pg_total_relation_size(c.oid) as bytes from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' order by bytes desc limit 8");
        foreach ($tables as $t) {
            $rows[] = ['база: '.$t->relname, '', Gc::human((int) $t->bytes)];
        }
        $rows[] = ['база: всего', '', Gc::human((int) DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes)];

        $this->table(['Что', 'Сколько', 'Объём'], $rows);

        return self::SUCCESS;
    }

    private function diskBytes(string $disk, string $dir = ''): int
    {
        $root = rtrim(Storage::disk($disk)->path($dir), '/');
        if (! is_dir($root)) {
            return 0;
        }
        $bytes = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($it as $file) {
                $bytes += (int) $file->getSize();
            }
        } catch (\Throwable) {
            // Чужой каталог без прав — считаем, что смогли.
        }

        return $bytes;
    }
}
