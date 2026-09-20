{{-- Редактор письма страницей — со списка почты («Написать»). Ответ и письмо вендору с дела — в окне писем (compose-frame). --}}
<x-ui.shell :title="match($mode) { 'reply' => 'Ответ', 'all' => 'Ответ всем', 'forward' => 'Пересылка', default => 'Новое письмо' }">
    <form method="post" action="{{ $base }}" id="compose" class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]" data-controller="photos draft" data-photos-url-value="{{ $base }}/file">
        @include('admin.mail.compose-fields')
    </form>
    <x-ui.action-bar>
        <x-ui.button form="compose" class="min-w-0 flex-1">Отправить</x-ui.button>
    </x-ui.action-bar>
</x-ui.shell>
