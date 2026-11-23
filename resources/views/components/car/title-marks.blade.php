{{-- Что идёт за названием машины в шапке страницы и окна — одна дверь (23.11.2026, владелец: «значок связи гигантский, номер
     слишком мелкий, с вёрсткой беда»): госномер (копируется), ключи, значок связи и слот (аукцион, «ждёт парковку», маржа).
     Пробел перед группой — место переноса: не влезла — встаёт с новой строки без отступа (было `ml-1.5` у номера, и на
     телефоне вторая строка начиналась сдвигом). Группа ушла на вторую строку — значок связи остаётся за названием
     (`title_marks_controller`; владелец: «если помещается и то и то — после номера»). Размер — от заголовка
     (`.head-title .title-marks`, `.detail-title …`), руками не ставится. Значок типа ТС перед названием — `x-ui.cat-icon`
     (слот `lead` у `x-ui.shell` и `x-ui.row-card`). Стоит сразу за названием (`x-ui.copy-text`) — по нему контроллер и мерит. --}}
@props(['plate' => null, 'keys' => null, 'keysLabel' => false, 'offer' => null, 'vehicle' => null, 'transport' => null])
&#32;<span class="title-marks" data-controller="title-marks">@if (filled($plate))<x-ui.plate :value="$plate" copy/>@endif<x-car.keys :count="$keys" :label="$keysLabel"/><x-ui.links :offer="$offer" :vehicle="$vehicle" :transport="$transport" data-title-marks-target="links"/>{{ $slot }}</span>
