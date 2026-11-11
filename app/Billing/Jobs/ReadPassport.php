<?php

namespace App\Billing\Jobs;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Passport\PassportScan;
use App\Billing\Passport\PassportText;
use App\Mail\Extraction\DocumentText;
use App\Mail\Parts;
use App\Media\Actions\RotatePhoto;
use App\Media\Thumb;
use App\Users\Actions\AddPerson;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Прочитать скан страницы паспорта и разобрать поля (11.10.2026): текст — `ocr` на нашем сервере (`DocumentText`, кэш по
 * отпечатку), поля — `PassportText`. Итог — в свойствах медиа: `read` (reading | done | failed), `fields`, `checked`
 * (сошлись контрольные цифры зоны). Страница боком — файл поворачивается (`upright`). Шторку паспорта заполняет опрос
 * `/parties/{party}/passport/live`.
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
        DocumentText::read([new PassportScan($media)], function ($file, ?string $text, ?int $turn = null) use ($media, &$read) {
            $read = true;
            $page = $text === null ? $media->getCustomProperty('page') : self::placePage($media, (string) $text);
            $result = $page === 'reg' ? PassportText::registration((string) $text) : PassportText::main((string) $text);
            // Сначала поворот, потом «прочитано»: шторка по «done» берёт миниатюру уже прямой.
            $text !== null && $turn && self::upright($media, $turn, $text);
            $media->setCustomProperty('read', $text === null ? 'failed' : 'done')
                ->setCustomProperty('fields', $result['fields'])
                ->setCustomProperty('checked', $result['checked'])
                ->save();
        }, $this->timeout - 60);
        if (! $read) {
            $media->setCustomProperty('read', 'failed')->save();
        }
        self::passportName($media);
    }

    /**
     * Какая это страница на самом деле (11.11.2026, владелец: «прикрепляют паспорт и прописку разом — путает порядок,
     * подставляет прописку в паспорт и наоборот, и ничего не распознаёт»): фото из галереи приходят в любом порядке, а плитка
     * бралась по порядку. Разворот узнаётся по зоне внизу (сошлись контрольные цифры) или ФИО, прописка — по штампу с
     * адресом. Лежит не на своей плитке — меняется местами с соседней (та перечитывается под своей: текст в кэше).
     * Обе страницы читаются разом, и обе могут поменять — итог тот же: каждая встаёт туда, что про неё прочитано.
     */
    private static function placePage(Media $media, string $text): string
    {
        $page = (string) $media->getCustomProperty('page');
        $main = PassportText::main($text);
        $isMain = $main['checked'] !== [] || isset($main['fields']['name']);
        $isReg = ! $isMain && isset(PassportText::registration($text)['fields']['reg_address']);
        $actual = match (true) {
            $page === 'reg' && $isMain => 'main',
            $page !== 'reg' && $isReg => 'reg',
            default => $page,
        };
        $party = $media->model;
        if ($actual === $page || ! $party instanceof Party) {
            return $page;
        }
        $other = Media::where('model_type', $media->model_type)->where('model_id', $media->model_id)->where('collection_name', 'passport')
            ->whereKeyNot($media->id)->get()->first(fn (Media $m) => $m->getCustomProperty('page') === $actual);
        $media->setCustomProperty('page', $actual)->save();
        if ($other) {
            $other->setCustomProperty('page', $page)->setCustomProperty('read', 'reading')->save();
            self::dispatch($other->id);
        }

        return $actual;
    }

    /**
     * Страница боком — повернуть сам файл (07.11.2026, владелец: «распозналась как прописка — пусть и переворачивает»): плитка,
     * просмотр и PDF поставщику — прямо. `$turn` — четверти против часовой, на которые `ocr` повернул страницу для
     * лучшего текста. Прочитанное ложится в кэш под новым отпечатком — второй раз не читается.
     */
    private static function upright(Media $media, int $turn, string $text): void
    {
        app(RotatePhoto::class)($media, (4 - $turn % 4) % 4);
        $path = $media->getPath();
        if (! is_file($path)) {
            return;
        }
        $sha = hash_file('sha256', $path);
        $media->setCustomProperty('sha', $sha)->save();
        Storage::disk(Parts::CACHE_DISK)->put('scantext/'.$sha.'.txt', $text);
        Thumb::forget("thumb-m{$media->id}");
    }

    /**
     * ФИО — со скана, поверх вписанного (07.11.2026, владелец: вписали «Гусейн Максутов», паспорт — «Максутов Гусейн
     * Максутович», осталось вписанное). Буква, в которой OCR ошибся, — по вписанному (`spelled`). У организации ФИО нет.
     * Покупатель, заведённый по одному скану («Новый покупатель»), получает имя аккаунта «Имя Фамилия» с паспорта.
     */
    public static function passportName(Media $media): void
    {
        $party = $media->model;
        $read = trim((string) ($media->getCustomProperty('fields')['name'] ?? ''));
        if (! $party instanceof Party || $party->kind === PartyKind::Company || count(preg_split('/\s+/u', $read)) < 2) {
            return;
        }
        $name = self::spelled($read, (string) $party->name);
        if ($name !== $party->name) {
            $party->update(['name' => $name]);
        }
        $user = User::where('party_id', $party->id)->first();
        if ($user && $user->name === AddPerson::UNNAMED) {
            $w = preg_split('/\s+/u', $name);
            $user->update(['name' => trim(($w[1] ?? '').' '.$w[0])]);
        }
    }

    /** Слово паспорта той же длины, что вписанное, и с одной другой буквой — вписанным написанием: «Фусейн» → «Гусейн». */
    public static function spelled(string $read, string $typed): string
    {
        $norm = fn (string $w) => mb_str_split(str_replace('ё', 'е', mb_strtolower($w)));
        $typedWords = array_values(array_filter(preg_split('/\s+/u', trim($typed)) ?: []));
        $out = [];
        foreach (preg_split('/\s+/u', trim($read)) as $w) {
            $a = $norm($w);
            foreach ($typedWords as $t) {
                $b = $norm($t);
                if (count($a) === count($b) && count(array_diff_assoc($a, $b)) === 1) {
                    $w = mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);
                    break;
                }
            }
            $out[] = $w;
        }

        return implode(' ', $out);
    }

    public function failed(): void
    {
        Media::find($this->mediaId)?->setCustomProperty('read', 'failed')->save();
    }
}
