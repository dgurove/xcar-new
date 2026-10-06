<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Карточка строки рядом со списком (фрейм `detail`, x-ui.detail): `?peek=ключ` в адресе списка. Строка таблицы — ссылка
 * на тот же список с ключом в этот фрейм (`url`); Turbo грузит фрейм и сам меняет адрес. Контроллер списка отвечает
 * одним фреймом, когда его просит фрейм (`framed` — заголовок `Turbo-Frame: detail`), не строя список; иначе отдаёт
 * карточку шеллу (`x-ui.shell :detail`), и перезагрузка открывает ту же. Resolver получает ключ и возвращает вид
 * карточки (`…/detail.blade.php`: x-ui.detail с x-ui.row-card) или null — ключ не нашёлся.
 */
final class Detail
{
    public const FRAME = 'detail';

    private View|HtmlString|null $html = null;

    private bool $rendered = false;

    private function __construct(private Request $request, private Closure $resolve, private string $keys) {}

    /** $keys — какие ключи пускать к resolver: по умолчанию только цифры; «Оплаты» пускают ещё `t{id}` — поступление выписки. */
    public static function of(Request $request, Closure $resolve, string $keys = '/^\d+$/'): self
    {
        return new self($request, $resolve, $keys);
    }

    public static function key(?Request $request = null): ?string
    {
        $key = ($request ?? request())->query('peek');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function framed(): bool
    {
        return $this->request->header('Turbo-Frame') === self::FRAME;
    }

    /** Фрейм с карточкой или пустой: пустой фрейм — таблица во всю ширину. */
    public function html(): View|HtmlString
    {
        if (! $this->rendered) {
            $this->rendered = true;
            $key = self::key($this->request);
            // Ключи строк — номера и id: прочее (first, мусор в адресе) карточки не даёт и в запрос не идёт.
            $this->html = $key === null || ! preg_match($this->keys, $key) ? null : ($this->resolve)($key);
        }

        return $this->html ?? self::empty();
    }

    public function open(): bool
    {
        return ! $this->html() instanceof HtmlString;
    }

    /** Ответ фрейму (Turbo-Frame: detail) — только карточка, без списка и шелла. */
    public function response()
    {
        return response($this->html() instanceof View ? $this->html()->render() : (string) $this->html());
    }

    public static function empty(): HtmlString
    {
        return new HtmlString(view('components.ui.detail', ['slot' => new HtmlString(''), 'open' => false])->render());
    }

    /**
     * Адрес списка, от которого строятся ссылки строк и «Закрыть»: страница списка или (автосохранение, поток после
     * формы) — адрес, откуда пришли. Поиск лупой и номер подгруженной страницы в адрес не едут: перезагрузка с ними
     * показала бы не тот список. Возвращает путь и параметры (с ?peek=).
     */
    private static function context(): array
    {
        $request = request();
        $page = $request->isMethod('GET') && ! $request->expectsJson();
        $url = $page ? $request->getRequestUri() : (string) $request->header('Referer');
        $parts = parse_url($url) ?: [];
        parse_str($parts['query'] ?? '', $query);

        return [$parts['path'] ?? '/', array_diff_key($query, ['q' => 1, 'page' => 1])];
    }

    private static function to(array $query): string
    {
        [$path] = self::context();

        return $path.($query ? '?'.http_build_query($query) : '');
    }

    /** Ссылка строки: список с этой карточкой. */
    public static function url(string|int $key): string
    {
        [, $query] = self::context();

        return self::to(['peek' => (string) $key] + array_diff_key($query, ['peek' => 1]));
    }

    /** «Закрыть» — тот же список без карточки. */
    public static function close(): string
    {
        [, $query] = self::context();

        return self::to(array_diff_key($query, ['peek' => 1]));
    }

}
