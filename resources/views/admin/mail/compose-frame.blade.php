{{-- Редактор письма в окне писем: ответ в ленте (фрейм reply или reply-{id}) или новое письмо вендору (letters-frame).
     Форма отвечает в letters-frame: после отправки окно перечитывается, ушедшее письмо внизу ленты.
     «Отмена» у ответа возвращает кнопку в тот же фрейм (?cancel=1), у нового письма — окно ветки. --}}
<turbo-frame id="{{ $frame }}" target="_top">
    <form method="post" action="{{ $base }}" id="compose-{{ $frame }}" class="flex flex-col gap-3" data-controller="photos draft" data-photos-url-value="{{ $base }}/file" data-turbo-frame="letters-frame">
        @include('admin.mail.compose-fields', ['inFrame' => true])
        <div class="flex gap-2">
            <x-ui.button class="flex-1">Отправить</x-ui.button>
            @if ($parent && str_starts_with($frame, 'reply'))<a href="{{ $base }}/{{ $thread->id }}/reply/{{ $parent->id }}?cancel=1" class="btn btn-ghost" data-turbo-frame="{{ $frame }}">Отмена</a>
            @elseif ($thread)<a href="{{ $base }}/{{ $thread->id }}/window" class="btn btn-ghost" data-turbo-frame="letters-frame">Отмена</a>@endif
        </div>
    </form>
</turbo-frame>
