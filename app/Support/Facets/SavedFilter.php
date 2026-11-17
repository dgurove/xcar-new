<?php

namespace App\Support\Facets;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сохранённый фильтр человека (17.11.2026, «Фильтр» шапки вкладок «Предложений»): название и адрес — вкладка (`preset`),
 * сортировка и все чипы её списка. Открыть — перейти по `url()`: пустые чипы стоят в адресе явно, и память `ListPrefs`
 * не подмешивает прошлый выбор. Только свои (владелец: «только свои»).
 *
 * @property array<string, string> $query
 */
#[Fillable(['user_id', 'list', 'name', 'query', 'position'])]
final class SavedFilter extends Model
{
    use FastAttributes;

    /** Сколько фильтров у человека в одном списке. */
    public const LIMIT = 20;

    /** Что в сохранённый адрес не идёт и в сравнении не участвует: страница, порция, поиск, карточка, сброс, вид. */
    public const DROP = ['page', 'part', 'rows', 'q', 'peek', 'reset', 'vid', 'per', 'letters'];

    /** Адрес списка (`list`) — колесо «Предложений». */
    public const PATHS = ['offers' => '/offers'];

    protected function casts(): array
    {
        return ['query' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return (self::PATHS[$this->list] ?? '/'.$this->list).'?'.str_replace(['%2C', '%21'], [',', '!'], http_build_query($this->query, '', '&', PHP_QUERY_RFC3986));
    }

    /** Тот же выбор, что сейчас на экране: вкладка и чипы (сортировка — не выбор, по ней фильтр не «другой»). */
    public function matches(array $query): bool
    {
        $norm = fn (array $q) => collect($q)->except(['sort', ...self::DROP])->map(fn ($v) => (string) $v)->filter(fn ($v) => $v !== '')->sortKeys()->all();

        return $norm($this->query) === $norm($query);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function of(User $user, string $list)
    {
        return self::where('user_id', $user->id)->where('list', $list)->orderBy('position')->orderBy('id')->get();
    }
}
