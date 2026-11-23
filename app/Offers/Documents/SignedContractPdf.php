<?php

namespace App\Offers\Documents;

use App\Offers\Deal;
use App\Workflow\Asks;
use App\Workflow\Requirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Подписанный договор одним PDF (17.11.2026, владелец: «с платёжкой сделали — её прикладывают, и она не в ходе сделки, а в блоке
 * „Продажа“, не фотками, а единым PDF; с договором так же»). Листы — файлы сделки (`signed_contract`, `AttachSignedContract`) и
 * ответы менеджера на шаг договора («Договор приложен» — файлы просьбы шага, `Stage::aboutContract`); копии, что `askFor` кладёт
 * из сделки в просьбу, не повторяются. Сборка — `ScanBundle`. В «Продаже» — строкой «Подписанный договор» (`x-deal.signed-contract`).
 */
final class SignedContractPdf
{
    /** @return Collection<int, Media> */
    public static function files(Deal $deal): Collection
    {
        $own = $deal->getMedia('signed_contract');
        $steps = Requirement::with(['stage', 'media'])->where('deal_id', $deal->id)->where('asks', Asks::Document->value)->orderBy('id')->get()
            ->filter(fn (Requirement $r) => $r->stage?->aboutContract())
            ->flatMap(fn (Requirement $r) => $r->getMedia('files')->sortBy('order_column'));
        $seen = [];

        return $own->concat($steps)->filter(function (Media $m) use (&$seen) {
            $key = (string) ($m->getCustomProperty('sha') ?: $m->file_name.':'.$m->size);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        })->values();
    }

    public static function render(Deal $deal): ?string
    {
        return ScanBundle::pdf(self::files($deal));
    }

    /** Когда приложен последний лист — дата строки «Подписанный договор от 17.11». */
    public static function at(Deal $deal): ?\Carbon\CarbonInterface
    {
        return self::files($deal)->max('created_at');
    }

    /** «Договор 0790-046-09068-26.pdf» — по номеру убытка, без него — по номеру предложения. */
    public static function fileName(Deal $deal): string
    {
        // Название и марка с моделью, без номера убытка (24.11.2026, владелец: «номера убытков мы не пишем нигде, марку модель
        // там просто и всё»).
        return \App\Media\PhotosPdf::fileName('Договор купли-продажи', $deal->offer?->title());
    }
}
