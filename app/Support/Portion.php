<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * Таблица порциями (31.10.2026, владелец: «страницы тяжёлые, как будто при скролле сотни раз рендерится»; замер: «Все» CRM
 * в 739 строк — 3,7 МБ и 41 тыс. узлов, телефон 30–50 с, сервер на проде 4–6 с). Сначала первые `rows` строк (по
 * умолчанию сотня), следующая сотня — когда до конца остаётся экран (`endless_controller`, хвост `x-ui.table`). Сколько
 * уже догружено — в адресе (`rows=`, клиент пишет сам): перечитывание, «Назад» и живое обновление рисуют столько же.
 * Номеров страниц у порций нет — `x-ui.pager` пустой.
 */
final class Portion extends LengthAwarePaginator
{
    public const PER = 100;

    public const ROWS = 'rows';

    /**
     * Номер сотни в адресе хвоста. Не `page`: с ним `ListPrefs` не подставляет запомненные чипы, и вторая сотня считалась
     * бы по другому фильтру (снятое «Кроме Каркаде» вставало обратно: «Все» — 685 строк из 739).
     */
    public const PART = 'part';

    /** Больше строк одним ответом не рисуем (страница целиком — 4–6 с на двух ядрах). */
    private const MAX = 2000;

    public static function of(Request $request, Builder|Relation $q, int $count): self
    {
        $page = max(1, (int) $request->query(self::PART, 1));
        // Первая порция — сколько уже было догружено (`rows`), круглыми сотнями; следующие — по сотне (адрес хвоста без
        // `rows`, номер — от сотни).
        $per = $page > 1 ? self::PER : (int) min(max((int) ceil((int) $request->query(self::ROWS) / self::PER) * self::PER, self::PER), self::MAX);
        // Последним — ключ строки: у порядка списка бывают равные (`tab_at` пустой у сотен строк), и без него Postgres
        // отдаёт смещения в разном порядке — строка попадала в две порции, другая ни в одну («Все»: 685 из 739).
        $q->orderBy(($q instanceof Relation ? $q->getRelated() : $q->getModel())->getQualifiedKeyName());
        $p = $q->paginate($per, ['*'], 'page', $page, total: $count);

        return new self($p->getCollection(), $count, $per, $page, ['path' => $request->url()]);
    }

    /** Строк до конца этой порции включительно — столько клиент пишет в `rows=` после неё. */
    public function upto(): int
    {
        return min($this->total(), $this->currentPage() * $this->perPage());
    }

    /** Адрес следующей сотни; null — дальше строк нет. Без `rows` и открытой карточки (`peek`): только строки. */
    public function next(): ?string
    {
        if ($this->upto() >= $this->total()) {
            return null;
        }
        $query = Arr::except(request()->query(), [self::ROWS, self::PART, 'page', 'peek']);

        return '/'.ltrim(request()->path(), '/').'?'.Arr::query($query + [self::PART => intdiv($this->upto(), self::PER) + 1]);
    }

    /** Сколько строк ещё за хвостом. */
    public function rest(): int
    {
        return max(0, $this->total() - $this->upto());
    }

    // Страниц нет — полоса страниц не рисуется, `@if ($x->hasPages())` её пропускает.
    public function hasPages(): bool
    {
        return false;
    }

    public function render($view = null, $data = []): HtmlString
    {
        return new HtmlString('');
    }
}
