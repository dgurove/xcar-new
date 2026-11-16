{{-- Карточка «Письма» — в сделке CRM, карточке и редакторе предложения, деле ТС и карточке заявки: все письма по машине
     строками, как дело в «Почте» (x-mail.rail), свежие сверху, строка открывает окно писем на этом письме. Заголовок свой
     (17.11.2026, владелец): тема писем вместо «Письма», число и справа значок «Ответить», как у письма в окне (`mail-reply`) —
     окно с редактором (?reply=1); после нашего письма подсказка «Написать».
     `split` (CRM, 09.11.2026, владелец: письма storage@ о приёме ТС шли одной цепочкой с письмом deal@ «Выражаем интерес» —
     казалось, что письмо по сделке не ушло): письма парковки — своей лентой «Письма парковки» ниже, кнопка — по письмам
     сделки. --}}
@props(['messages', 'url', 'asks' => [], 'stages' => [], 'reply' => true, 'split' => false])
@php
    $all = collect($messages)->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values();
    $park = $split ? $all->filter(fn ($m) => $m->account?->scope === \App\Mail\Scope::Park)->values() : collect();
    $main = $all->diff($park)->values();
    $last = $main->last();
    $ours = ! $last || ($last->isOurs() && ! $last->isForwardedByStaff());
@endphp
@if ($all->isNotEmpty())
    <div {{ $attributes }}>
        <div class="letters-head">
            <h2>{{ \App\Mail\Topic::of($main->isNotEmpty() ? $main : $all) ?? 'Письма' }} <span class="nums font-normal text-ink-dim">{{ \App\Mail\Chains\NodeTitle::count($main->isNotEmpty() ? $main : $all) }}</span></h2>
            @if ($reply)
                <button type="button" class="letter-ib -my-2 -mr-2" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $url.(str_contains($url, '?') ? '&' : '?').'reply=1' }}" title="{{ $ours ? 'Написать' : 'Ответить' }}" aria-label="{{ $ours ? 'Написать' : 'Ответить' }}"><x-ui.icon name="mail-reply" class="size-5"/></button>
            @endif
        </div>
        @if ($main->isNotEmpty())<x-mail.rail :messages="$main" :url="$url" :asks="$asks" :stages="$stages"/>@endif
        @if ($park->isNotEmpty())
            <p class="mt-4 mb-1 text-sm text-ink-dim">Письма парковки <span class="nums">{{ \App\Mail\Chains\NodeTitle::count($park) }}</span></p>
            <x-mail.rail :messages="$park" :url="$url" :asks="$asks" :stages="$stages"/>
        @endif
    </div>
@endif
