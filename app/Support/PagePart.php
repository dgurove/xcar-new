<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Страница плиток порциями (18.11.2026, сайт как приложение: у 14 из 52 активных людей стоит «по 96» — страница
 * каталога в 510–710 КБ и 4,5 тысячи узлов, которую сервер рисует 1–1,5 с, а телефон разбирает при входе, клонирует
 * при уходе и собирает заново при «Назад»). Страницы, их номера и «по N» остаются как были — меняется доставка: первая
 * порция страницы сразу, остальные её плитки — когда до конца остаётся экран (`endless_controller`, хвост
 * `site/catalog-part`). Сколько плиток страницы уже есть — в адресе (`rows=`, клиент пишет сам): перечитывание, «Назад»
 * и живое обновление рисуют столько же. Таблица порциями — `Portion` (там страниц нет вовсе).
 *
 * Лента без страниц (тот же день, владелец: «в предложениях, галерее, сделке и гараже убрать вообще страницы, всегда
 * бесконечная подгружающаяся лента»): «страница» — весь список (`per` = числу машин, `ListView::paginate(endless:)`),
 * номеров и «по N» нет. Одним ответом сервер рисует не больше `MAX` плиток — и по `rows=` из адреса тоже: длинную ленту
 * целиком держит снимок страницы у клиента (память разделов), а не перечитывание.
 */
final class PagePart extends LengthAwarePaginator
{
    /** Плиток в порции: три-четыре экрана телефона, полтора экрана ПК. */
    public const CHUNK = 24;

    /** Больше плиток одним ответом не рисуем: столько весила самая тяжёлая страница «по 96». */
    public const MAX = 96;

    private int $from = 0;

    private int $chunk = self::CHUNK;

    private bool $partial = false;

    /** Плитка перед порцией хвоста — чтобы подпись пачки («До 17:00 сегодня») не повторялась посреди пачки. */
    public ?Model $before = null;

    public static function of(Request $request, Builder|Relation $q, int $count, int $per, int $chunk = self::CHUNK): self
    {
        $page = max(1, (int) $request->query('page', 1));
        // Номер порции — только у догрузки хвоста (`X-List`) и дальше запроса не идёт: ссылки плиток, пилюль и страниц
        // собираются из адреса запроса (как у `Portion`).
        $part = $request->headers->has('X-List') ? max(1, (int) $request->query(Portion::PART, 1)) : 1;
        $request->query->remove(Portion::PART);
        $onPage = max(0, min($per, $count - ($page - 1) * $per));
        if ($part > 1) {
            $from = min($onPage, ($part - 1) * $chunk);
            $take = min($chunk, $onPage - $from);
        } else {
            $from = 0;
            // Первая порция — сколько уже было догружено (`rows`), целыми порциями.
            $take = (int) min($onPage, self::MAX, max($chunk, (int) ceil((int) $request->query(Portion::ROWS) / $chunk) * $chunk));
        }
        // Последним — ключ: у порядка списка бывают равные, и без него порции Postgres отдаёт внахлёст.
        $q->orderBy(($q instanceof Relation ? $q->getRelated() : $q->getModel())->getQualifiedKeyName());
        $offset = ($page - 1) * $per + $from;
        $before = null;
        if ($part > 1 && $take > 0) {
            // С предыдущей плиткой одним запросом: она — только для подписи пачки.
            $items = (clone $q)->offset($offset - 1)->limit($take + 1)->get();
            $before = $items->shift();
        } else {
            $items = $take > 0 ? (clone $q)->offset($offset)->limit($take)->get() : ($q instanceof Relation ? $q->getRelated() : $q->getModel())->newCollection();
        }
        $self = new self($items, $count, $per, $page, [
            'path' => $request->url(),
            'query' => Arr::except($request->query(), [Portion::ROWS, Portion::PART, 'peek']),
        ]);
        $self->from = $from;
        $self->chunk = $chunk;
        $self->partial = $part > 1;
        $self->before = $before;

        return $self;
    }

    /** Плиток страницы до конца этой порции включительно. */
    public function upto(): int
    {
        return $this->from + $this->count();
    }

    /** Сколько плиток на этой странице всего. */
    public function onPage(): int
    {
        return (int) max(0, min($this->perPage(), $this->total() - ($this->currentPage() - 1) * $this->perPage()));
    }

    /** Ответ — порция хвоста, а не начало страницы. */
    public function partial(): bool
    {
        return $this->partial;
    }

    /** Адрес следующей порции этой страницы; null — страница показана целиком. */
    public function next(): ?string
    {
        if ($this->upto() >= $this->onPage()) {
            return null;
        }
        $query = Arr::except(request()->query(), [Portion::ROWS, Portion::PART, 'peek']);

        // Номер страницы — только у списка со страницами: у ленты (`per` = всему списку) страница одна, и `page` в адресе
        // хвоста выключил бы запомненные чипы (`ListPrefs::sync`).
        return '/'.ltrim(request()->path(), '/').'?'.Arr::query(Arr::except($query, ['page']) + ($this->currentPage() > 1 ? ['page' => $this->currentPage()] : []) + [Portion::PART => intdiv($this->upto(), $this->chunk) + 1]);
    }

    /** Сколько плиток ещё за этой порцией. */
    public function rest(): int
    {
        return max(0, $this->onPage() - $this->upto());
    }

    /** Сколько плиток страницы будет после следующей порции — это клиент пишет в `rows=`. */
    public function after(): int
    {
        return min($this->onPage(), $this->upto() + $this->chunk);
    }
}
