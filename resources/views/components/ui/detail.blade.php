{{-- Фрейм карточки строки рядом со списком (App\Support\Detail). Пустой — его нет, таблица во всю ширину (:empty, потому
     ни пробела внутри). От 1024 — колонка справа шириной телефона, липнет под шапкой во всю высоту экрана; до 1024 —
     нижний лист на полэкрана или во весь экран: высоту меняет только полоса сверху (detail_controller), внутри листается
     тело карточки. Формы внутри отвечают в фрейм, адрес
     страницы (?peek=) Turbo меняет сам — data-turbo-action у фрейма. --}}
@props(['open' => true])
<turbo-frame id="detail" class="split-detail" target="_top" data-turbo-action="replace" data-detail-target="frame">@if ($open)<div class="detail-card" role="region" aria-label="Карточка">{{ $slot }}</div>@endif</turbo-frame>
