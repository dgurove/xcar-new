<?php

namespace App\Offers\Documents;

use App\Offers\Deal;
use App\Workflow\Requirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документ об оплате одним PDF (10.11.2026, владелец: первая оплата по счёту Альфы через систему — менеджер прислал два фото
 * чека кассы; «из двух фоток, как с паспортом, формировался пдф, который мы отправим страховой»; «не всегда сфотографировать,
 * иногда это файл, иногда скан»). Что приложили на шаге оплаты (менеджер или админ за него — `Workflow\Requirement`, файлы
 * `files`): снимки и сканы — по листу A4, как держали телефон (`ScanImage`); PDF — подклеиваются целиком (`pdfunite`, нет его
 * — листами-картинками). Уходит вложением письма шага «Подтверждение оплаты» (`MailController`), в CRM — «Документ об оплате, PDF».
 */
final class PaymentProofPdf
{
    /** Ответ менеджера на шаге оплаты с документом; прежнее название — в ответах до 10.11.2026. */
    public const EXITS = ['Оплатил, документ приложен', 'Платёжное поручение приложено'];

    /** Просьба шага оплаты с файлами: открытая (приложили, шаг ещё не нажат) или последняя пройденная «Оплатил…». */
    public static function requirement(Deal $deal): ?Requirement
    {
        $open = $deal->openRequirement;
        if ($open?->stage?->isPayStep() && $open->getMedia('files')->isNotEmpty()) {
            return $open;
        }

        return Requirement::where('deal_id', $deal->id)->whereNotNull('done_at')->whereIn('answer->exit', self::EXITS)->latest('done_at')->first();
    }

    /** @return Collection<int, Media> */
    public static function files(Deal $deal): Collection
    {
        return self::requirement($deal)?->getMedia('files')->sortBy('order_column')->values() ?? collect();
    }

    /** null — нечего собрать. Сборка — `ScanBundle`, та же у подписанного договора. */
    public static function render(Deal $deal): ?string
    {
        return ScanBundle::pdf(self::files($deal));
    }

    /** «Оплата 0790-046-09068-26.pdf» — по номеру убытка, без него — по номеру предложения. */
    public static function fileName(Deal $deal): string
    {
        $ref = $deal->offer?->claim_ref ?: (string) $deal->offer?->number;

        return 'Оплата '.Str::of($ref)->replaceMatches('/[\\\\\/:*?"<>|]+/u', '-')->squish().'.pdf';
    }
}
