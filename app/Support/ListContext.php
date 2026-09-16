<?php

namespace App\Support;

use App\Offers\CatalogQuery;
use App\Offers\Offer;
use Illuminate\Http\Request;

/**
 * Откуда пришли на страницу оффера: список (каталог или галерея), фильтры,
 * сортировка, вид. Едет в адресе параметром `list` и теми же ключами, что у
 * каталога, — стрелки листают ровно тот список, который человек видел.
 */
final class ListContext
{
    public function __construct(public readonly string $list, public readonly array $filters, public readonly ?string $view) {}

    public static function forList(bool $gallery, array $filters, ?string $view): self
    {
        return new self($gallery ? 'gallery' : 'catalog', array_filter($filters, fn ($v) => $v !== null && $v !== ''), $view);
    }

    public static function fromRequest(Request $request): ?self
    {
        $list = $request->query('list');
        if (! in_array($list, ['catalog', 'gallery'], true)) {
            return null;
        }

        return new self($list, array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== ''), ListView::fromRequest($request));
    }

    public function isGallery(): bool
    {
        return $this->list === 'gallery';
    }

    public function query(): array
    {
        return array_filter(['list' => $this->list, ...$this->filters, ListView::PARAM => $this->view], fn ($v) => $v !== null && $v !== '');
    }

    public function offerUrl(Offer|int $offer): string
    {
        $number = $offer instanceof Offer ? $offer->number : $offer;

        return '/offers/'.$number.'?'.http_build_query($this->query());
    }

    public function backUrl(): string
    {
        $query = array_diff_key($this->query(), ['list' => 1]);

        return ($this->isGallery() ? '/gallery' : '/').($query ? '?'.http_build_query($query) : '');
    }
}
