{{-- Запланированное письмо поставщику у шага «Согласие менеджера» (27.11.2026, `Offers\PlannedLetters`): ждёт — «Письмо
     поставщику запланировано», серым «уйдёт, когда менеджер возьмёт в работу»; нажатие — шторка (кому, тема, текст, вложения) с
     «Отменить». Не ушло — красной строкой с «Написать поставщику». Нет плана, менеджера уже спросили — тихая «Запланировать письмо…». --}}
@props(['deal', 'offer', 'stage'])
@php
    use App\Offers\PlannedLetter;
    use App\Offers\PlannedLetters;
    $planned = PlannedLetters::shown($deal);
    $letter = $planned ? null : PlannedLetters::letterAfter($stage, $deal);
    $n = $offer->number;
    $id = 'planned-'.$deal->id;
@endphp
@if ($planned?->isPending())
    <div class="contents" data-controller="sheet">
        <button type="button" class="mt-2 flex w-full items-center gap-2 text-left" data-action="sheet#open" aria-controls="{{ $id }}">
            <x-ui.icon name="clock" class="size-4 shrink-0 text-accent-text"/>
            <span class="min-w-0 flex-1"><span class="block">Письмо поставщику запланировано</span><span class="block text-sm text-ink-muted">уйдёт, когда менеджер возьмёт в работу</span></span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </button>
        <x-ui.sheet :id="$id" title="Запланированное письмо">
            <div class="flex flex-col gap-4">
                <div class="list">
                    <div class="row"><span class="w-14 shrink-0 text-ink-muted">Кому</span><span class="min-w-0 flex-1 truncate">{{ $planned->to() }}</span></div>
                    <div class="row"><span class="w-14 shrink-0 text-ink-muted">Тема</span><span class="min-w-0 flex-1">{{ $planned->subject() }}</span></div>
                </div>
                <p class="whitespace-pre-line px-1">{{ $planned->text() }}</p>
                @if ($files = $planned->files())
                    <div class="flex flex-col gap-2">@foreach ($files as $name)<x-ui.file :name="$name"/>@endforeach</div>
                @endif
                <p class="px-1 text-sm text-ink-muted">Запланировал {{ $planned->author?->shortName() ?? 'сотрудник' }} {!! \App\Support\Ago::html($planned->created_at, true, words: false) !!}</p>
                <form method="post" action="/offers/{{ $n }}/planned-letter" data-turbo-confirm="Отменить письмо?" data-turbo-confirm-text="Не уйдёт и после «Беру в работу». Написать можно будет заново" data-turbo-confirm-label="Отменить письмо" data-turbo-confirm-danger="1">
                    @csrf @method('delete')
                    <button class="btn btn-quiet btn-block text-danger">Отменить письмо</button>
                </form>
            </div>
        </x-ui.sheet>
    </div>
@elseif ($planned)
    <div class="mt-2 flex items-start gap-2">
        <x-ui.icon name="mail" class="mt-0.5 size-4 shrink-0 text-danger"/>
        <span class="min-w-0 flex-1"><span class="block text-danger">Запланированное письмо не ушло</span>@if ($planned->error)<span class="block text-sm text-ink-muted">{{ $planned->error }}</span>@endif</span>
    </div>
@elseif ($letter && ($url = $offer->vendorLetterUrl($letter)))
    <x-mail.window-button :url="$url.'&plan=wait'" label="Запланировать письмо…" icon="mail" class="btn-s btn-quiet mt-2 self-start"/>
@endif
