{{-- «Входящие» и «Отправленные» — письма строками (x-mail.inbox-row), одной плашкой; строка открывает письмо справа
     (`Detail`). Хвост «Ещё письма…» — следующая порция (`endless`): $more — ответ хвосту, только строки и новый хвост. --}}
@php $more ??= false; @endphp
@if ($rows->isEmpty() && ! $more)
    <x-ui.empty class="py-6">{{ $filtered ? 'Ничего не нашлось' : ($box === 'sent' ? 'Отправленных нет' : 'Писем нет') }}</x-ui.empty>
@else
    @unless ($more)<div class="inbox-list" data-controller="mail-row">@endunless
    @foreach ($rows as $m)
        <x-mail.inbox-row :message="$m" :files="$files[$m->id] ?? 0" :sent="$box === 'sent'" :park="$park" :size="$sizes[$m->id] ?? 1" :base="$base" :queue="$queue"/>
    @endforeach
    @if ($page->hasMorePages())
        <div class="inbox-more" data-endless-next="{{ $page->nextPageUrl() }}">Ещё письма…</div>
    @endif
    @unless ($more)</div>@endunless
@endif
