{{-- Фрейм карточки строки рядом со списком (App\Support\Detail). Пустой — его нет, таблица во всю ширину (:empty, потому
     ни пробела внутри). От 1024 — колонка справа шириной телефона, липнет под шапкой во всю высоту экрана; до 1024 —
     нижний лист на нативной прокрутке: прокладка на высоту экрана с точкой «полэкрана» и карточка (точка «во весь
     экран»), тянется пальцем без скрипта, у самого низа закрывается (detail_controller). Формы внутри отвечают в фрейм, адрес
     страницы (?peek=) Turbo меняет сам — data-turbo-action у фрейма. --}}
@props(['open' => true])
<turbo-frame id="detail" class="split-detail" target="_top" data-turbo-action="replace" data-detail-target="frame">@if ($open)<div class="detail-pad" aria-hidden="true"><i class="detail-half"></i></div><div class="detail-card" role="region" aria-label="Карточка">{{ $slot }}</div>@endif</turbo-frame>
