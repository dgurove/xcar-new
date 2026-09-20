{{-- Окошко ветки: тема, участники, ТС или предложение; все письма целиком с вложениями. «Открыть» — страница ветки, там ответы. --}}
@php $linked = $thread->vehicle ?? $thread->offer; $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3); @endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$base.'/'.$thread->id" :title="$thread->subject ?: '(без темы)'" :photo="false" action="Открыть">
        <x-slot:marks>
            @foreach ($who as $name)<span class="tag">{{ $name }}</span>@endforeach
            @if ($thread->vehicle)<a href="{{ \App\Support\Surface::Park->url('/cars/'.$thread->vehicle->id) }}" class="tag" @if ($crm) data-turbo="false" @endif>{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref && $thread->vehicle->brand_id) <span class="nums text-ink-muted">{{ $thread->vehicle->ref }}</span>@endif</a>@endif
            @if ($thread->offer)<a href="{{ \App\Support\Surface::Crm->url('/offers/'.$thread->offer->number) }}" class="tag" @if (!$crm) data-turbo="false" @endif>{{ $thread->offer->title() }} <span class="nums text-ink-muted">№ {{ $thread->offer->number }}</span></a>@endif
            <span class="tag">{{ $thread->account->title }}</span>
        </x-slot:marks>
        <div class="mt-4"><x-mail.panel :messages="$thread->messages" :base="$base"/></div>
        <x-slot:row><x-mail.thread-row :thread="$thread" :base="$base"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
