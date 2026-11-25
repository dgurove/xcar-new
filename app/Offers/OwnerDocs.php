<?php

namespace App\Offers;

use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\Paper;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документы собственника среди файлов машины — паспорт, СТС, ПТС (25.11.2026, владелец: «по Тиньку они нам вообще СТС и паспорт
 * отправляют, нам приходится вручную скачивать и в чате отправлять, это ж бред»). Т-Страхование шлёт «Паспорт.pdf», «СТС.pdf»,
 * «эптс.pdf», «Паспорт1.png», а бывает и «images.pdf», «3a24ec….pdf». Сначала имя файла, иначе уже прочитанный текст (кэш
 * `DocumentText`, без OCR в запросе страницы). Кто берёт: «Показать менеджеру» — отмечены сразу (`Disclosures::proposals`),
 * продавец ДКП из паспорта (`Actions\FillOwnerFromPassport`).
 */
final class OwnerDocs
{
    public const PASSPORT = 'passport';

    public const STS = 'sts';

    public const PTS = 'pts';

    /** Чей это документ собственника: `passport | sts | pts`, null — не он. */
    public static function kind(Media $media): ?string
    {
        $name = mb_strtolower(trim(($media->name ?: '').' '.pathinfo((string) $media->file_name, PATHINFO_FILENAME)));
        if (preg_match('/(?<![а-яё])э?птс(?![а-яё])|паспорт\W*(?:тс|транспорт)/u', $name)) {
            return self::PTS;
        }
        if (preg_match('/(?<![а-яё])стс(?![а-яё])|свидетельств\S*\s+о\s+регистрац/u', $name)) {
            return self::STS;
        }
        if (preg_match('/паспорт/u', $name)) {
            return self::PASSPORT;
        }
        // Имя ни о чём («images (1).pdf») — по прочитанному тексту.
        $text = DocumentText::cached(new Paper($media));

        return $text === null ? null : self::kindOfText($text);
    }

    public static function kindOfText(string $text): ?string
    {
        $t = mb_strtoupper(preg_replace('/\s+/u', ' ', $text));
        if (preg_match('/СВИДЕТЕЛЬСТВО\s+О\s+РЕГИСТРАЦИИ\s+(?:ТРАНСПОРТНОГО|ТС)/u', $t)) {
            return self::STS;
        }
        if (preg_match('/(?:ЭЛЕКТРОННЫЙ\s+)?ПАСПОРТ\s+ТРАНСПОРТНОГО\s+СРЕДСТВА|ВЫПИСКА\s+ИЗ\s+ЭЛЕКТРОННОГО\s+ПАСПОРТА/u', $t)) {
            return self::PTS;
        }
        // Паспорт гражданина: зона внизу «PNRUS…» или бланк с кодом подразделения.
        if (preg_match('/PNRUS/u', $t) || (preg_match('/ПАСПОРТ/u', $t) && preg_match('/КОД\s+ПОДРАЗДЕЛЕНИЯ|ЛИЧНЫЙ\s+КОД|МЕСТО\s+РОЖДЕНИЯ/u', $t))) {
            return self::PASSPORT;
        }

        return null;
    }

    /**
     * Документы собственника среди файлов машины, по видам.
     *
     * @param  Collection<int, Media>  $papers
     * @return Collection<int, Media>
     */
    public static function among(Collection $papers, ?string $kind = null): Collection
    {
        return $papers->filter(fn (Media $m) => ($k = self::kind($m)) !== null && ($kind === null || $k === $kind))->values();
    }
}
