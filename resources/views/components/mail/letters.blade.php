{{-- Карточка «Письма» — в сделке CRM, карточке и редакторе предложения, деле ТС и карточке заявки: все письма по машине
     строками, как дело в «Почте» (x-mail.rail), строка открывает окно писем на этом письме. Под ними одна кнопка —
     «Ответить», после нашего письма «Написать»: окно с редактором (?reply=1). Заголовок с числом ставит место.
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
        @if ($main->isNotEmpty())<x-mail.rail :messages="$main" :url="$url" :asks="$asks" :stages="$stages"/>@endif
        @if ($reply)
            <x-mail.window-button :url="$url.(str_contains($url, '?') ? '&' : '?').'reply=1'" :label="$ours ? 'Написать' : 'Ответить'" :icon="$ours ? 'mail' : 'reply'" class="btn-s mt-1"/>
        @endif
        @if ($park->isNotEmpty())
            <p class="mt-4 mb-1 text-sm text-ink-dim">Письма парковки <span class="nums">{{ \App\Mail\Chains\NodeTitle::count($park) }}</span></p>
            <x-mail.rail :messages="$park" :url="$url" :asks="$asks" :stages="$stages"/>
        @endif
    </div>
@endif
