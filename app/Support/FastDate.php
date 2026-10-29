<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Даты приложения (`Date::use` в AppServiceProvider): та же Carbon, только `translatedFormat` простых форматов — «j M»,
 * «j M, H:i», «j F Y», «F Y» — без перевода на каждый вызов (29.10.2026: 17–30 мкс на дату, а в видах их 150 мест,
 * строка таблицы пишет две-три). Формат разбирается раз, слово месяца — раз на язык, формат и месяц в запросе: его
 * отдаёт сама Carbon на пробной дате того же месяца, и пробная дата, собранная заново, обязана совпасть с её ответом —
 * иначе этот формат идёт обычным путём. Дни недели, AM/PM и прочее — обычным путём.
 */
class FastDate extends Carbon
{
    private const NUMBERS = 'jdnmYyHGis';

    /** @var array<string, list<array{0: string, 1: string}>|false> формат => части */
    private static array $plans = [];

    /**
     * Слова месяцев живут в контейнере (`scoped`): Octane и очередь сбрасывают их между запросами и задачами — переводы,
     * поменянные на ходу, подхватываются со следующего запроса, а не после перезапуска воркера.
     */
    public const WORDS = 'fast-date.words';

    public function translatedFormat(string $format): string
    {
        $plan = self::$plans[$format] ??= self::plan($format);
        if ($plan === false) {
            return parent::translatedFormat($format);
        }
        $month = (int) $this->format('n');
        $locale = $this->locale();
        $memo = app(self::WORDS);
        $words = $memo[$locale.'|'.$format.'|'.$month] ??= self::words($plan, $format, $month, $locale);
        if ($words === false) {
            return parent::translatedFormat($format);
        }
        $out = '';
        $w = 0;
        foreach ($plan as [$kind, $value]) {
            $out .= match ($kind) {
                'text' => $value,
                'number' => $this->format($value),
                default => $words[$w++],
            };
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}>|false */
    private static function plan(string $format): array|false
    {
        $plan = [];
        $chars = mb_str_split($format);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\') {
                $plan[] = ['text', $chars[++$i] ?? ''];
            } elseif ($c === 'M' || $c === 'F') {
                $plan[] = ['word', $c];
            } elseif (str_contains(self::NUMBERS, $c)) {
                $plan[] = ['number', $c];
            } elseif (preg_match('/[A-Za-z]/', $c)) {
                return false;
            } else {
                $plan[] = ['text', $c];
            }
        }

        return $plan;
    }

    /**
     * Слова месяца — из ответа самой Carbon на пробной дате: она же и проверка (собранная по частям строка обязана
     * совпасть). Не совпала — false, формат идёт обычным путём.
     *
     * @param  list<array{0: string, 1: string}>  $plan
     * @return list<string>|false
     */
    private static function words(array $plan, string $format, int $month, string $locale): array|false
    {
        $probe = Carbon::create(2001, $month, 9, 7, 5, 3)->locale($locale);
        $real = $probe->translatedFormat($format);
        $pattern = '';
        foreach ($plan as [$kind, $value]) {
            $pattern .= match ($kind) {
                'text' => preg_quote($value, '/'),
                'number' => preg_quote($probe->format($value), '/'),
                default => '(\pL[\pL.\-]*)',
            };
        }
        if (! preg_match('/^'.$pattern.'$/u', $real, $m)) {
            return false;
        }

        return array_slice($m, 1);
    }
}
