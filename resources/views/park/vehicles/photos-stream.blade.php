{{-- Ответ на загрузку, поворот, удаление и перестановку кадров: карточки всех стадий и чек-лист приёма.
     Что на странице есть — подменится, чего нет — пропустится: блок стадии стоит либо в ленте своего шага,
     либо справа. Кнопка камеры возвращается только той стадии, откуда пришёл запрос: править можно лишь
     в ленте шага, а там стадия одна. --}}
@php use App\Park\PhotoStage; @endphp
@foreach (PhotoStage::cases() as $one)
    <turbo-stream action="replace" target="photos-{{ $one->value }}"><template><x-park.photos :vehicle="$vehicle" :stage="$one" :edit="$one->value === $stage" :collapsed="$one === PhotoStage::Release && $one->value === $stage"/></template></turbo-stream>
@endforeach
<turbo-stream action="update" target="photo-slots"><template><x-park.photo-slots :vehicle="$vehicle" stage="intake" :slots="App\Park\PhotoSlot::cases()"/></template></turbo-stream>
