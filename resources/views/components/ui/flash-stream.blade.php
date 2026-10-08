{{-- Тост flash-сообщения потоком (StreamActions.toast) — ответ, который рисуется во фрейме, а не страницей: карточка
     строки (x-ui.row-card), окно писем. Красный — toast-danger, «Отменить» — toast-undo. --}}
@if (session('toast') || session('toast-danger'))<turbo-stream action="toast" data-message="{{ session('toast-danger') ?? session('toast') }}" data-kind="{{ session('toast-danger') ? 'danger' : '' }}" @if (session('toast-undo')) data-undo @endif></turbo-stream>@endif
