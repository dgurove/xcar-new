@php use App\Mail\{Direction, ParseState, SendState}; $park = $base === '/pochta'; @endphp
<x-ui.shell :title="$thread->subject ?: '(без темы)'">
    <div class="mb-4 flex flex-wrap items-center gap-2" data-controller="sheet">
        <span class="chip">{{ $thread->account->title }}</span>
        @if ($park && $thread->vehicle)
            <a href="/mashiny/{{ $thread->vehicle->id }}" class="chip bg-accent-soft text-accent-text">{{ $thread->vehicle->titleWithYear() }}{{ $thread->vehicle->ref ? ' · '.$thread->vehicle->ref : '' }}</a>
        @elseif (!$park && $thread->offer)
            <a href="/predlozheniya/{{ $thread->offer->number }}" class="chip bg-accent-soft text-accent-text">№ {{ $thread->offer->number }} · {{ $thread->offer->title() }}</a>
        @endif
        <button type="button" class="chip" data-action="sheet#open">{{ ($park ? $thread->vehicle : $thread->offer) ? 'Перепривязать' : ($park ? 'Привязать к машине' : 'Привязать к предложению') }}</button>
        <x-ui.sheet id="link" :title="$park ? 'Машина' : 'Предложение'" :open="$errors->has('number') || $errors->has('vehicle_id')">
            <form method="post" action="{{ $base }}/{{ $thread->id }}/privyazka" class="flex flex-col gap-3">
                @csrf
                @if ($park)
                    <x-ui.combobox name="vehicle_id" label="Машина" url="/spravochnik/mashiny" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/>
                @else
                    <x-ui.field name="number" label="Номер предложения" inputmode="numeric" :value="$thread->offer?->number" autofocus/>
                @endif
                <div class="flex gap-2">
                    <x-ui.button class="flex-1">Привязать</x-ui.button>
                    @if ($park ? $thread->vehicle : $thread->offer)<x-ui.button variant="ghost" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
                </div>
            </form>
        </x-ui.sheet>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/neprochitano" class="ml-auto">@csrf<x-ui.button variant="ghost" size="sm">Не прочитано</x-ui.button></form>
    </div>

    <div class="flex flex-col gap-4">
        @foreach ($messages as $message)
            @php $out = $message->direction === Direction::Out; @endphp
            <x-ui.card class="{{ $out ? 'md:ml-8' : 'md:mr-8' }}" id="msg-{{ $message->id }}">
                <div class="mb-3 flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium">{{ $out ? ($message->author?->name ?? $message->from_name ?? 'Мы') : ($message->from_name ?: $message->from_email) }}</span>
                            @if (!$out && $message->from_name)<span class="text-sm text-ink-muted">{{ $message->from_email }}</span>@endif
                        </div>
                        <div class="text-sm text-ink-muted">Кому: {{ $message->to_preview ?: '—' }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <span class="text-sm text-ink-dim tabular-nums">{{ $message->date_at?->translatedFormat('j M, H:i') }}</span>
                        <form method="post" action="{{ $base }}/pisma/{{ $message->id }}/flag">@csrf<button class="btn btn-ghost btn-s px-1.5 {{ $message->is_flagged ? 'text-urgent' : 'text-ink-dim' }}" aria-label="Отметить"><x-ui.icon name="flag" class="size-5"/></button></form>
                    </div>
                </div>

                @if ($out && $message->send_state !== SendState::Sent)
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="chip {{ $message->send_state === SendState::Failed ? 'bg-danger-soft text-danger' : 'bg-urgent-soft text-urgent' }}">{{ $message->send_state?->label() }}</span>
                        @if ($message->send_error)<span class="text-sm text-danger">{{ $message->send_error }}</span>@endif
                        @if ($message->send_state === SendState::Failed)<form method="post" action="{{ $base }}/pisma/{{ $message->id }}/snova">@csrf<x-ui.button size="sm" variant="secondary">Отправить снова</x-ui.button></form>@endif
                    </div>
                @elseif ($out && !$message->appended_to_sent_at)
                    <div class="mb-3 text-sm text-ink-muted">Ушло, но копия в «Отправленных» ящика не сохранилась.</div>
                @endif

                @if ($message->parse_state === ParseState::Failed)
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="chip bg-danger-soft text-danger">Не разобралось</span>
                        <span class="text-sm text-ink-muted">{{ $message->parse_error }}</span>
                        <form method="post" action="{{ $base }}/pisma/{{ $message->id }}/razbor">@csrf<x-ui.button size="sm" variant="secondary">Разобрать снова</x-ui.button></form>
                    </div>
                @elseif ($message->parse_state === ParseState::Pending)
                    <div class="mb-3 text-sm text-ink-muted">Разбирается…</div>
                @else
                    <div data-controller="frame" class="overflow-hidden rounded-(--radius-l) bg-white">
                        <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $documents($message) }}" title="Письмо" class="block w-full" style="height:120px" data-frame-target="frame" data-action="load->frame#fit"></iframe>
                    </div>
                    @if (!request()->boolean('kartinki') && $renderer->hasRemoteImages($message))
                        <a href="{{ request()->fullUrlWithQuery(['kartinki' => 1]) }}#msg-{{ $message->id }}" class="btn btn-ghost btn-s mt-2">Показать картинки</a>
                    @endif
                @endif

                @if ($message->files()->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($message->files() as $file)
                            {{-- Миниатюра — только у закреплённого файла: незакреплённый лежит в ящике, за ним ходят по клику. --}}
                            <a href="{{ $base }}/vlozheniya/{{ $file->id }}" target="_blank" class="flex max-w-full items-center gap-2 rounded-(--radius-m) bg-surface-2 p-2 pr-3">
                                <x-ui.file-icon :name="$file->filename" :mime="$file->mime" :thumb="$file->isImage() && $file->isPinned() ? $base.'/vlozheniya/'.$file->id : null"/>
                                <span class="min-w-0"><span class="block truncate text-sm">{{ $file->filename }}</span><span class="text-xs text-ink-muted">{{ $file->humanSize() }}</span></span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ $base }}/{{ $thread->id }}/otvet/{{ $message->id }}" class="btn btn-quiet btn-s">Ответить</a>
                    <a href="{{ $base }}/{{ $thread->id }}/otvet/{{ $message->id }}?rezhim=all" class="btn btn-ghost btn-s">Всем</a>
                    <a href="{{ $base }}/{{ $thread->id }}/otvet/{{ $message->id }}?rezhim=forward" class="btn btn-ghost btn-s">Переслать</a>
                </div>
            </x-ui.card>
        @endforeach
    </div>
</x-ui.shell>
