{{-- Окно писем ТС: все ветки, у каждой строка темы и письма под ней (новые ветки сверху), «Ответить» под письмом. --}}
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-5">
        @forelse ($threads as $thread)
            <div>
                <div class="mb-2 flex flex-wrap items-center gap-1.5">
                    <h3 class="min-w-0 flex-1 truncate text-base font-medium">{{ $thread->subject ?: '(без темы)' }}</h3>
                    @if ($thread->messages_count > 1)<span class="chip nums">{{ $thread->messages_count }}</span>@endif
                    <button type="button" class="chip" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/mail/{{ $thread->id }}/window">Ветка</button>
                </div>
                <x-mail.panel :messages="$thread->messages" base="/mail" reply/>
            </div>
        @empty
            <x-ui.empty>Писем нет</x-ui.empty>
        @endforelse
    </div>
</turbo-frame>
