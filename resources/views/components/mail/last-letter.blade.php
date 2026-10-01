{{-- Карточка «Письма» — одна на дело ТС, редактор и окошко предложения: последнее письмо словами — кто (наше — сотрудник или
     имя ящика, пересланное сотрудником — настоящий отправитель), смысл тегом, дата, заголовок узла (если он не первые слова),
     свои слова, файлы; письмо, которое ждёт ответа, с оранжевой точкой. «Вся переписка» открывает окно писем
     (x-mail.window, url — лента всех веток), «Ответить» (после нашего письма — «Написать») — окно сразу с редактором
     (?reply=1). Карточку вокруг ставит страница (`x-ui.card title="Письма"`); compact — в окошке строки: текст в три строки. --}}
@props(['message', 'count', 'url', 'asks' => collect(), 'candidate' => null, 'reply' => true, 'compact' => false])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    $m = $message;
    $ours = $m->isOurs() && ! $m->isForwardedByStaff();
    $intent = Intent::tryFrom((string) $m->intent);
    $waits = collect($asks)->contains(fn ($a) => $a->id === $m->id);
    $text = $m->ownText();
    $files = $m->files()->count();
    $title = NodeTitle::titled($m, $candidate) ? NodeTitle::for($m, $candidate) : null;
    $sep = str_contains($url, '?') ? '&' : '?';
@endphp
<div {{ $attributes }}>
    <div class="flex items-start gap-3">
        <x-mail.sender-avatar :message="$m" :size="$compact ? 32 : 40"/>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="min-w-0 truncate font-medium">@if ($waits)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ NodeTitle::who($m) }}</span>
                @if ($waits)<span class="tag tag-urgent">Ждёт ответа</span>@endif
                @if ($intent?->short())<span class="tag {{ $ours ? 'tag-dim' : ($intent === Intent::Intake ? 'tag-accent' : '') }}">{{ $intent->short() }}</span>@endif
                <span class="nums text-sm text-ink-dim">{{ $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
            </div>
            @if ($title && $title !== $intent?->short())<div class="mt-1 truncate text-sm text-ink-muted">{{ $title }}</div>@endif
            @if ($text !== '')<p class="mt-2 {{ $compact ? 'line-clamp-3' : 'line-clamp-5' }} whitespace-pre-line text-sm">{{ $text }}</p>@endif
            @if ($files)<span class="chip nums mt-2"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
        </div>
    </div>
    <div class="mt-3 flex gap-2">
        <x-mail.window-button :url="$url" label="Вся переписка" :count="$count" class="btn-s flex-1 sm:flex-none"/>
        @if ($reply)<x-mail.window-button :url="$url.$sep.'reply=1'" :label="$ours ? 'Написать' : 'Ответить'" class="btn-s btn-accent flex-1 sm:flex-none"/>@endif
    </div>
</div>
