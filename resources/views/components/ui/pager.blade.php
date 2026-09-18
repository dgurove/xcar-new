{{-- Полоса страниц списка: номера всем, справа «По сколько» — из набора sizes (ListView::perSizes
     по виду карточного списка, ListView::PER_ROWS у плотных); :sizes="[]" — без выбора. --}}
@props(['of', 'sizes' => \App\Support\ListView::PER_ROWS])
{{ $of->links('vendor.pagination.xcar', ['sizes' => $sizes]) }}
