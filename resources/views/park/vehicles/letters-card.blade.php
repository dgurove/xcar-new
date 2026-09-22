{{-- Блок «Письма» в деле над таймлайном: последнее письмо ленты словами — кто, смысл тегом, дата, свои слова письма,
     файлы; письмо, которое ждёт ответа, помечено оранжевой точкой. «Вся цепочка» и «Ответить» открывают окно писем
     (x-mail.window), «Ответить» — сразу с редактором (?reply=1). --}}
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    $m = $lastLetter;
    $ours = $m->isOurs();
    $intent = ! $ours ? Intent::tryFrom((string) $m->intent) : null;
    $waits = $ask && $ask->id === $m->id;
    $text = $m->ownText();
    $files = $m->files()->count();
@endphp
<x-ui.card title="Письма" :count="$letters">
    <div class="flex items-start gap-3">
        @if ($ours && $m->author)<x-ui.avatar :user="$m->author" :size="40"/>@elseif ($ours)<x-chat.avatar :user="null" :size="40"/>@else<x-ui.avatar :name="$m->from_name" :email="$m->from_email" :size="40"/>@endif
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-medium">@if ($waits)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ NodeTitle::who($m) }}</span>
                @if ($intent?->short())<span class="tag {{ $intent->tone() }}">{{ $intent->short() }}</span>@elseif ($ours)<span class="tag tag-dim">Мы</span>@endif
                <span class="nums text-sm text-ink-dim">{{ $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
            </div>
            <div class="mt-1 text-sm text-ink-muted">{{ NodeTitle::for($m, $candidate) }}</div>
            @if ($text !== '')<p class="mt-2 line-clamp-5 whitespace-pre-line text-sm">{{ $text }}</p>@endif
            @if ($files)<span class="chip nums mt-2"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
        </div>
    </div>
    <div class="mt-3 flex gap-2">
        <x-mail.window-button :url="'/cars/'.$vehicle->id.'/letters'" label="Вся цепочка" :count="$letters" class="btn-s flex-1 sm:flex-none"/>
        @unless ($vehicle->state->isFinal())<x-mail.window-button :url="'/cars/'.$vehicle->id.'/letters?reply=1'" label="Ответить" class="btn-s btn-accent flex-1 sm:flex-none"/>@endunless
    </div>
</x-ui.card>
