<?php

namespace Tests\Unit;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use Tests\TestCase;

/**
 * Номер убытка Совкомбанка в теме (12.10.2026): от него зависит, во вкладку «Из таблицы Кати» или в рабочую встаёт
 * черновик из письма. На проде 9 из 80 писем не давали номера — слитно «делу590706/2026», 4, 5 и 7 цифр.
 */
class CodeMatcherTest extends TestCase
{
    public function test_sovcombank_forms(): void
    {
        $cases = [
            'Fwd: Вывоз ГОТС по делу590706/2026,: г Пермь' => ['590706/2026'],
            'Fwd: Вывоз ГОТС по делу 1197110/2025, г. Екатеринбург, лизинг' => ['1197110/2025'],
            'Fwd: 49251-26 JAC T8 Комсомольск-на-Амуре' => ['49251/2026'],
            'Fwd: Вывоз ГОТС по делу 5025/2026, г. Великий Новгород' => ['5025/2026'],
            'Fwd: Вывоз ГОТС по делу 637086 /2026, Ува' => ['637086/2026'],
            'Fwd: 666916-26 ЛАДА ЛАРГУС' => ['666916/2026'],
            'Fwd: 110927-26 и 110945-26 Мерседес Актрос' => ['110927/2026', '110945/2026'],
            'Отчёт за 2025-2026 год' => [],
            'Fwd: Вывоз ГОТС 771027 г Кемерово' => [],
        ];
        $matcher = new CodeMatcher;
        foreach ($cases as $subject => $codes) {
            $this->assertSame($codes, array_map(fn ($c) => Code::normalize($c), $matcher->findAll($subject)), $subject);
        }
    }

    /** Т-Страхование отделяет номер от машины чем придётся (16.10.2026: « // » номер не давал). */
    public function test_tinsurance_separators(): void
    {
        $matcher = new CodeMatcher;
        foreach (['Fwd: AUT-26-878170 // 2024 Belgee X50 (Р226КЕ977 / Y4K8622Z6RB920776)', 'Fwd: AUT-26-878170\\ 2024 Belgee',
            'Fwd: AUT-26-878170 \\\\ 2024 Belgee', 'Fwd: AUT-26-878170  || 2024 Belgee', 'Fwd: AUT-26-878170 | 2024 | Belgee'] as $subject) {
            $this->assertSame(['AUT-26-878170'], $matcher->findAll($subject), $subject);
        }
    }
}
