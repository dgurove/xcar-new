{{-- Карточка «Письма» — одна на дело ТС, редактор и карточка предложения: последнее письмо словами — кто (наше — сотрудник или
     имя ящика, пересланное сотрудником — настоящий отправитель), смысл тегом (ждёт ответа — тегом «Ждёт ответа», без точки:
     одно и то же дважды), под именем «время адрес», заголовок узла — только у письма без тега, свои слова, файлы. «Вся переписка» открывает окно писем
     (x-mail.window, url — лента всех веток), «Ответить» (после нашего письма — «Написать») — окно сразу с редактором
     (?reply=1). Карточку вокруг ставит страница (`x-ui.card title="Письма"`); compact — в карточке строки: текст в три строки. --}}
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
    {{-- Кружок — только у шапки (имя с тегом, ниже «время адрес» одной строкой); слова письма, примечание и скрепка — во
         всю ширину карточки, а не колонкой под кружком (владелец 07.10.2026: «зачем такой огромный отступ слева»). Строки
         заголовка узла («Заявка на приём») нет, если смысл уже сказан тегом («Заявка»). --}}
    <div class="flex items-center gap-3">
        <x-mail.sender-avatar :message="$m" :size="$compact ? 32 : 40"/>
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2">
                <span class="min-w-0 truncate font-medium">{{ NodeTitle::who($m) }}</span>
                @if ($waits)<span class="tag tag-urgent shrink-0">Ждёт ответа</span>@endif
                @if ($intent?->short())<span class="tag shrink-0 {{ $ours ? 'tag-dim' : ($intent === Intent::Intake ? 'tag-accent' : '') }}">{{ $intent->short() }}</span>@endif
            </div>
            <div class="flex items-baseline gap-2 text-sm text-ink-muted">
                <span class="nums shrink-0 text-ink-dim">{{ $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
                <x-mail.address :email="NodeTitle::email($m)"/>
            </div>
        </div>
    </div>
    @if ($title && ! $intent?->short())<div class="mt-2 truncate text-sm text-ink-muted">{{ $title }}</div>@endif
    @if ($note = $m->forwardNote())<div class="history-note mt-2 text-sm"><div class="text-ink-muted">{{ NodeTitle::forwarder($m) }}</div><div class="{{ $compact ? 'line-clamp-3' : '' }} whitespace-pre-line">{{ $note }}</div></div>@endif
    @if ($text !== '')<p class="mt-2 {{ $compact ? 'line-clamp-3' : 'line-clamp-5' }} whitespace-pre-line text-sm">{{ $text }}</p>@endif
    @if ($files)<span class="chip nums mt-2"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
    <div class="mt-3 flex gap-2">
        <x-mail.window-button :url="$url" label="Вся переписка" :count="$count" class="btn-s flex-1 sm:flex-none"/>
        @if ($reply)<x-mail.window-button :url="$url.$sep.'reply=1'" :label="$ours ? 'Написать' : 'Ответить'" class="btn-s btn-accent flex-1 sm:flex-none"/>@endif
    </div>
</div>
