{{-- Обзор вендора в CRM: условия продажи и почта продажи строками, переход к карточке на
     парковке — тем, у кого она есть. Предложения — своей пилюлей (15.10.2026). --}}
@php use App\Support\Money; use App\Vendors\RewardKind; @endphp
<div class="flex flex-col gap-2">
    <div class="list">
        <div class="row justify-between"><span class="text-ink-muted">Формат сделки</span><span>{{ $vendor->deal_format->label() }}</span></div>
        @if ($vendor->reward_kind)<div class="row justify-between"><span class="text-ink-muted">Вознаграждение</span><span class="nums">{{ $vendor->reward_kind === RewardKind::Difference ? 'Разница цен' : ($vendor->reward_kind === RewardKind::Percent ? $vendor->reward_value.' %' : Money::rub($vendor->reward_value)) }}</span></div>@endif
        @if ($vendor->answer_hours)<div class="row justify-between"><span class="text-ink-muted">Ответ</span><span class="nums">{{ $vendor->answer_hours }} ч</span></div>@endif
        @if ($vendor->binding_days)<div class="row justify-between"><span class="text-ink-muted">Предложение держим</span><span class="nums">{{ $vendor->binding_days }} дн</span></div>@endif
        <div class="row justify-between"><span class="text-ink-muted">НДС</span><span>{{ $vendor->offers_include_vat ? 'Цены с НДС' : 'Без НДС' }}</span></div>
        @if ($vendor->defaultTags->isNotEmpty())
            <div class="row justify-between gap-3"><span class="shrink-0 text-ink-muted">Метки</span><span class="flex flex-wrap justify-end gap-1">@foreach ($vendor->defaultTags as $t)<span class="tag" style="{{ \App\Offers\Tag::style($t->color) }}">{{ $t->name }}</span>@endforeach</span></div>
        @endif
        @if ($park)<a href="{{ $park }}" class="row justify-between" data-turbo="false"><span class="text-accent-text">Реквизиты, хранение, прайс</span><span class="text-accent-text">↗</span></a>@endif
    </div>
    @if ($vendor->mailAccount || $vendor->senders)
        <div class="list-head">Почта</div>
        <div class="list">
            @if ($vendor->mailAccount)<div class="row justify-between"><span class="shrink-0 text-ink-muted">Отвечаем с</span><span class="min-w-0 truncate">{{ $vendor->mailAccount->email }}</span></div>@endif
            @if ($vendor->senders)<div class="row justify-between"><span class="shrink-0 text-ink-muted">Предложения от</span><span class="min-w-0 text-right">{{ implode(', ', $vendor->senders) }}</span></div>@endif
        </div>
    @endif
</div>
