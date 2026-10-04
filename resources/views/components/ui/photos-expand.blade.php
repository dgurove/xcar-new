{{-- «Развернуть» / «Свернуть» у ленты кадров: лента становится плиткой и обратно, выбор помнится на устройстве
     (photos_controller, localStorage). Меньше двух кадров — кнопки нет. --}}
<button type="button" {{ $attributes->merge(['class' => 'photos-all']) }} data-photos-target="expand" data-action="photos#expand" hidden>Развернуть</button>
