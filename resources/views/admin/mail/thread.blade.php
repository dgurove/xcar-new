@php $park = $base === '/mail'; @endphp
<x-ui.shell :title="\App\Mail\Topic::of($thread->messages) ?? ($thread->subject ?: '(без темы)')" :back="['Почта', $base]">
    {{-- Ящик — текстом, ТС или предложение — ссылкой (10.10.2026: «что-то чипами, что-то текстом»). Привязка и «Не прочитано» —
         в «⋯» письма, как везде (17.11.2026: две кнопки с ml-auto стояли посреди строки). --}}
    <div class="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span class="text-sm text-ink-muted">{{ $thread->account->title }}</span>
        @if ($park && $thread->vehicle)
            <a href="/cars/{{ $thread->vehicle->id }}" class="text-sm text-accent-text">{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref && $thread->vehicle->brand_id) <span class="nums opacity-70">{{ $thread->vehicle->ref }}</span>@endif</a>
        @elseif (!$park && $thread->offer)
            <a href="{{ $thread->offer->crmPath() }}" class="text-sm text-accent-text">{{ $thread->offer->title() }} <span class="nums opacity-70">№ {{ $thread->offer->number }}</span></a>
        @endif
    </div>

    {{-- Страница ветки — та же цепочка писем, что в окне и справа во «Входящих» (17.11.2026: была старым видом — серый лист,
         пустые строки, цитата всей переписки, подпись не свёрнута). --}}
    <div class="box">
        <x-mail.chain :messages="$thread->messages" :base="$base" :candidate="$thread->candidate" :focus="true" :car="false" reply/>
    </div>
</x-ui.shell>
