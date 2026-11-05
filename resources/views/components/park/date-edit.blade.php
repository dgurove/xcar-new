{{-- Дата приёма или выдачи — сама выбор дня (владелец 10.10.2026: шторку «Даты» убрать, «сделай такой же
     календарик», править — только администратору парковки). Нажатие — календарь Cally (`x-ui.date chip`),
     закрыли с новым значением — уходит форма `x-park.date-forms` (`POST /cars/{v}/dates`, `SetDates`). Форма отдельно от
     кнопки: в деле дата стоит внутри формы текущего шага, а форма в форме не живёт. Класс — снаружи (тег, факт шага).
     Только день (05.11.2026, владелец: «всем похер на время»): время не спрашивается, `SetDates` ставит 12:00. --}}
@props(['vehicle', 'field', 'prefix' => null])
<x-ui.date chip submit :clearable="false" :name="$field" :id="'de-'.$field.'-'.$vehicle->id" :value="$vehicle->{$field}" :prefix="$prefix"
    :min="$field === 'released_at' ? $vehicle->accepted_at : null" :max="now()" :form="'dates-'.$vehicle->id.'-'.$field" {{ $attributes->only('class') }}/>
