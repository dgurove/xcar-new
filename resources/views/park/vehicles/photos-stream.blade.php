{{-- Ответ на загрузку, поворот, удаление и перестановку кадров: карточки всех стадий и чек-лист приёма.
     Что на странице есть — подменится, чего нет — пропустится: блок стадии стоит либо в ленте своего шага,
     либо справа. Кнопка камеры возвращается только той стадии, откуда пришёл запрос: править можно лишь
     в ленте шага, а там стадия одна. --}}
@php use App\Park\PhotoStage; @endphp
{{-- Пачки — одним куском (`park.vehicles.detail-photos`) в трёх местах: дело (`case-photos`), окно шага (`step-photos` — стадия
     запроса, со скрепкой, если загрузка пришла из «Фото, файл»), окно строки (`detail-photos`). --}}
<turbo-stream action="replace" target="case-photos"><template>@include('park.vehicles.detail-photos', ['vehicle' => $vehicle, 'root' => 'case-photos', 'mode' => 'case'])</template></turbo-stream>
@if ($stage && ($one = PhotoStage::tryFrom($stage)) && in_array($one, [PhotoStage::Intake, PhotoStage::Release], true))
<turbo-stream action="replace" target="step-photos"><template>@include('park.vehicles.detail-photos', ['vehicle' => $vehicle, 'root' => 'step-photos', 'mode' => 'step', 'only' => $one, 'files' => $files ?? false])</template></turbo-stream>
@endif
{{-- Карточка строки «Наличия» — блоки кадров целиком (на странице дела её нет — пропустится). --}}
<turbo-stream action="replace" target="detail-photos"><template>@include('park.vehicles.detail-photos', ['vehicle' => $vehicle])</template></turbo-stream>
