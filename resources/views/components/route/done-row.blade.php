{{-- Пройденное в пути сделки строкой решения (24.11.2026, владелец: «написано, что Андрей решил или менеджер решил, а ЧТО РЕШИЛ —
     никак не посмотреть»): исход («Время согласовано»), под ним что решили (`Workflow\ExitWhat`: время, адрес, поля сотрудника,
     платёжка), справа кто и когда; нажал сотрудник за менеджера — «за менеджера». Подпункты текущего шага и пройденные блоки. --}}
@props(['name', 'what' => [], 'at' => null, 'by' => null, 'for' => null])
<li class="substep substep--done">
    <span class="substep-dot"><x-ui.icon name="check" class="size-2.5"/></span>
    <div class="substep-body">
        <div class="substep-head">
            <span class="substep-title">{{ $name }}</span>
            {{-- Что решили — сразу за исходом, темнее (`ExitWhat`): «Время согласовано чт 26.11 в 11:00», кто и когда — за ним серым. --}}
            @if ($what)
                <span class="substep-what">{{ implode(', ', $what) }}</span>
            @endif
            @if ($at || $by)
                <span class="substep-meta">{{ $by }}{{ $by && $for === 'manager' ? ' за менеджера' : '' }}{{ $by && $at ? ', ' : '' }}@if ($at)<span class="nums">{{ \App\Support\Ago::html($at, true, words: false) }}</span>@endif</span>
            @endif
        </div>
        {{ $slot }}
    </div>
</li>
