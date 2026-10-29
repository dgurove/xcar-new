<?php

namespace App\Support;

use App\Vendors\Kind;
use App\Vendors\Vendor;

/**
 * Названия вендоров в чужом тексте (15.10.2026, владелец: менеджеры и покупатели «никогда не должны видеть название
 * вендора нигде»). Корни имён справочника и бренды, которыми вендоры подписываются (АльфаСтрах, Совкомбанк, Т-Банк…),
 * — одной регуляркой; справочник в памяти процесса минуту.
 */
final class VendorNames
{
    /** Бренды страховых, банков и лизинга, как их пишут в письмах, — корнями. */
    private const BRANDS = ['совком', 'sovcom', 'alpha', 'т-страх', 'тстрах', 'т-банк', 'tbank', 't-bank', 'тинькоф',
        'tinkoff', 'ингосстрах', 'ingos', 'ресо-', 'reso-', 'росгосстрах', 'согаз', 'sogaz', 'ренессанс', 'renins', 'каркаде', 'carcade',
        'сбербанк', 'сбер страх', 'sberbank', 'зетта', 'zetta', 'югори', 'энергогарант', 'soglasie', 'абсолют страх', 'absolut',
        'мигторг', 'migtorg', 'битавто', 'bitauto', 'гайде', 'макс-страх', 'инсайт', 'интери'];

    /** Короткие — только целым словом: «вск» — не «вскоре». */
    private const WORDS = ['вск', 'vsk', 'ргс', 'rgs', 'ресо', 'reso', 'сбер', 'sber', 'эрго', 'ergo', 'пари', 'согласие', 'т-банк'];

    /** Слова названий, которые сами по себе не выдают вендора. */
    private const PLAIN = ['ооо', 'пао', 'зао', 'оао', 'страхование', 'страховая', 'компания', 'банк', 'москва', 'спб', 'санкт',
        'петербург', 'группа', 'лизинг', 'страхования', 'страховой', 'общество', 'акционерное', 'проба', 'вёрстки'];

    private static ?string $pattern = null;

    private static int $at = 0;

    public static function matches(?string $text): bool
    {
        return $text !== null && $text !== '' && preg_match(self::pattern(), $text) === 1;
    }

    /** Текст без названий вендоров: слово целиком уходит, хвосты пробелов и пустые кавычки — тоже. */
    public static function strip(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = (string) preg_replace(self::pattern(), '', $text);

        return trim((string) preg_replace(['/«\s*»|"\s*"|\(\s*\)/u', '/[ \t]{2,}/u', '/[ \t]+([,.;:)])/u', '/^[ \t,;:—-]+/mu'], ['', ' ', '$1', ''], $text));
    }

    private static function pattern(): string
    {
        if (self::$pattern !== null && time() - self::$at < 60) {
            return self::$pattern;
        }
        $roots = self::BRANDS;
        try {
            // Физлицо и свой транспорт — не бренд: имя человека из текста не вырезаем.
            foreach (Vendor::query()->whereNotIn('kind', [Kind::Person, Kind::Own])->with('party:id,name')->get(['name', 'party_id']) as $vendor) {
                foreach ([$vendor->name, $vendor->party?->name] as $name) {
                    foreach (preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower((string) $name)) ?: [] as $word) {
                        if (mb_strlen($word) > 3 && ! in_array($word, self::PLAIN, true)) {
                            $roots[] = $word;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // База недоступна (сборка образа) — хватит брендов.
        }
        $roots = array_unique($roots);
        usort($roots, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$at = time();

        $quote = fn ($r) => str_replace(' ', '\\s+', preg_quote($r, '/'));
        // Альфа — кроме Alfa Romeo: марку машины из описания не вырезаем.
        $alfa = '(?:альфа|alfa)(?![\s-]*(?:ромео|romeo))';

        // Слово, начинающееся с корня, целиком («АльфаСтрахование», «Совкомбанк»), и короткие — словом («ВСК»).
        return self::$pattern = '/(?<![\p{L}\p{N}])(?:(?:'.$alfa.'|'.implode('|', array_map($quote, $roots)).')[\p{L}\p{N}-]*|(?:'
            .implode('|', array_map($quote, self::WORDS)).')(?![\p{L}\p{N}]))/iu';
    }
}
