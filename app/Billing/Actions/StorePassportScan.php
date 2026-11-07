<?php

namespace App\Billing\Actions;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Media\PhotoIngest;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

/**
 * Страницы скана паспорта покупателя — одна дверь для плиток скана (`PassportScanController`) и «Нового покупателя»
 * (`PeopleController`, 07.11.2026, владелец: «сразу прикрепить фотки или пдф с паспортом»). Новая страница заменяет прежнюю,
 * читает её задача `ReadPassport`. Всё хранится картинками: PDF — его страницы JPEG (первая — разворот с фото, вторая —
 * прописка, 19.10.2026; одна страница — на ту плитку, куда бросили), чтобы просмотр, поворот и `PassportPdf` были одни.
 */
final class StorePassportScan
{
    /** Крупнее обычного фото (1600): строка паспорта мелкая, при сжатии не читается. */
    public const MAX = 2600;

    public function __construct(private PhotoIngest $ingest) {}

    /**
     * Файлы по порядку: фото — каждое на свою плитку начиная с `$page`, PDF из двух страниц — на обе.
     *
     * @param  list<UploadedFile>  $files
     */
    public function __invoke(Party $party, string $page, array $files, User $by): void
    {
        $pages = array_keys(Party::PASSPORT_PAGES);
        $at = max(0, (int) array_search($page, $pages, true));
        foreach ($files as $file) {
            if ($at >= count($pages)) {
                break;
            }
            if (str_starts_with((string) $file->getMimeType(), 'image/')) {
                $this->store($party, $pages[$at++], fn ($props) => $this->ingest->fromUpload($party, 'passport', $file, $props, self::MAX), $by);

                continue;
            }
            // PDF из двух страниц — разворот и прописка, на какую плитку ни бросили.
            $jpegs = self::pdfPages($file->getRealPath(), count($pages));
            count($jpegs) >= count($pages) && $at = 0;
            foreach (array_slice($jpegs, 0, count($pages) - $at) as $jpeg) {
                $p = $pages[$at++];
                $this->store($party, $p, fn ($props) => $this->ingest->fromString($party, 'passport', $jpeg, 'passport-'.$p.'.jpg', $props, self::MAX), $by);
            }
        }
    }

    /** Новая страница скана вместо прежней и её чтение. @param  callable(array): \Spatie\MediaLibrary\MediaCollections\Models\Media  $add */
    private function store(Party $party, string $page, callable $add, User $by): void
    {
        $old = $party->passportScan($page);
        $media = $add(['page' => $page, 'read' => 'reading', 'by' => $by->id]);
        $old?->delete();
        $party->unsetRelation('media');
        ReadPassport::dispatch($media->id);
    }

    /** Первые страницы PDF картинками JPEG (200 dpi — строка паспорта мелкая). @return list<string> */
    private static function pdfPages(string $path, int $limit): array
    {
        $dir = sys_get_temp_dir().'/passport-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        try {
            Process::timeout(60)->run(['pdftoppm', '-jpeg', '-r', '200', '-f', '1', '-l', (string) max(1, $limit), $path, $dir.'/p']);
            $files = glob($dir.'/p-*.jpg') ?: [];
            sort($files, SORT_NATURAL);

            return array_map('file_get_contents', $files);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
}
