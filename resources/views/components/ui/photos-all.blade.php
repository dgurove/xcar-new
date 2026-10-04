{{-- «Показать все» / «Скрыть все» у ряда кадров: подпись и видимость ставит photos_controller (хоть один скрыт — показать
     все; меньше двух кадров с глазом — кнопки нет). Стоит внутри data-controller="photos". --}}
<button type="button" {{ $attributes->merge(['class' => 'photos-all']) }} data-photos-target="all" data-action="photos#all" hidden>Показать все</button>
