{{-- Окно действия сделало своё: закрыть, освежить карточку или страницу, письмо вендору — окном писем (`act-done`). --}}
<turbo-frame id="act-frame" class="block">
    <x-ui.flash-stream/>
    <turbo-stream action="act-done" @if ($letters) url="{{ $letters }}" @endif></turbo-stream>
</turbo-frame>
