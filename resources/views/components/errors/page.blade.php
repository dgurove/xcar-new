{{-- Ошибка в оболочке приложения: фраза и одна кнопка — вместо белого листа Laravel,
     из которого в установленном приложении нет пути назад. --}}
@props(['title', 'href' => '/', 'button' => 'На главную'])
<x-ui.shell :title="$title" :heading="false">
    <div class="box px-6 py-24 text-center">
        <p class="text-xl">{{ $title }}</p>
        @if (isset($slot) && trim($slot) !== '')<div class="mt-2 text-ink-muted">{{ $slot }}</div>@endif
        <div class="mt-8 flex justify-center">
            <a href="{{ $href }}" class="btn btn-quiet">{{ $button }}</a>
        </div>
    </div>
</x-ui.shell>
