{{-- Редактор письма страницей — со списка почты («Написать»). Ответ и письмо вендору с дела — в окне писем (compose-frame). --}}
<x-ui.shell :title="match($mode) { 'reply' => 'Ответ', 'all' => 'Ответ всем', 'forward' => 'Пересылка', default => 'Новое письмо' }">
    <form method="post" action="{{ $base }}" id="compose" class="max-w-3xl" data-controller="photos draft" data-photos-url-value="{{ $base }}/file">
        @include('admin.mail.compose-fields')
    </form>
</x-ui.shell>
