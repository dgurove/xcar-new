<?php

namespace App\Cars\Sources;

use App\Mail\Attachment;
use App\Mail\Message;
use App\Support\Docs;
use Closure;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Источники вариантов — документом шторки документов (`x-ui.doc`): вложение письма, документ машины, письмо. Одним запросом
 * на вид — для шторок полей (`x-car.variants`) и экрана «Парсинг».
 */
final class SourceDocs
{
    /** @param iterable<array> $variants варианты `Variants::for|all`; $mail — адрес окна писем поверхности. */
    public static function for(iterable $variants, string $mail): Closure
    {
        $ids = collect($variants)->flatMap(fn ($v) => collect([...$v['options'], $v['current'] ?? ['sources' => []]])->flatMap(fn ($o) => $o['sources']));
        $docs = $ids->where('kind', 'doc')->pluck('id')->map(fn ($i) => (string) $i);
        $attachments = $docs->filter(fn ($i) => ctype_digit($i))->whenNotEmpty(fn ($c) => Attachment::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());
        $media = $docs->filter(fn ($i) => str_starts_with($i, 'm'))->map(fn ($i) => (int) substr($i, 1))->whenNotEmpty(fn ($c) => Media::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());
        $letters = $ids->where('kind', 'letter')->pluck('id')->whenNotEmpty(fn ($c) => Message::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());

        return fn (array $s) => match ($s['kind']) {
            'doc' => str_starts_with((string) $s['id'], 'm')
                ? (($m = $media[(int) substr($s['id'], 1)] ?? null) ? Docs::media($m) : null)
                : (($a = $attachments[(int) $s['id']] ?? null) ? Docs::attachment($a, $mail) : null),
            'letter' => ($m = $letters[(int) $s['id']] ?? null) ? Docs::letter($m, $mail) : null,
            default => null,
        };
    }
}
