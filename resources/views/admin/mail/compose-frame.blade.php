{{-- Редактор письма в окне писем: ответ в ленте (фрейм reply или reply-{id}) или новое письмо вендору (letters-frame).
     Форма отвечает в letters-frame: после отправки окно перечитывается, ушедшее письмо внизу ленты.
     «Отмена» у ответа возвращает кнопку в тот же фрейм (?cancel=1), у нового письма — окно ветки. --}}
<turbo-frame id="{{ $frame }}" target="_top">
    <form method="post" action="{{ $base }}" id="compose-{{ $frame }}" data-controller="photos draft" data-photos-url-value="{{ $base }}/file" data-turbo-frame="letters-frame">
        @include('admin.mail.compose-fields')
    </form>
</turbo-frame>
