{{-- «Отмена» в ленте: кнопка ответа снова в своём фрейме. У письма справа во «Входящих» (reply-pane-{id}) кнопок внизу нет —
     фрейм просто пустеет, ответ снова со значка сверху. --}}
@if (str_starts_with($frame, 'reply-pane'))
    <turbo-frame id="{{ $frame }}"></turbo-frame>
@else
    <x-mail.reply-button :message="$message" :base="$base" :frame="$frame"/>
@endif
