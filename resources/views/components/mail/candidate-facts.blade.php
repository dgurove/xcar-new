{{-- Что общий разбор вынул из письма любого вендора: срок ответа, страхователь, признаки ТС, категория, документы, держатель. --}}
@props(['v'])
@if ($v('answer_by'))
    @php $by = \Illuminate\Support\Carbon::parse($v('answer_by')); @endphp
    <x-ui.pill :tone="$by->isPast() ? 'danger' : 'urgent'" class="!min-h-0 !py-0.5 text-xs nums">до {{ $by->timezone('Europe/Moscow')->translatedFormat('j M H:i') }}</x-ui.pill>
@endif
@if ($v('category'))<span class="chip">{{ \App\Cars\Category::labelOf($v('category')) }}</span>@endif
@foreach (\App\Offers\Flag::fromList($v('flags')) as $flag)<span class="chip">{{ $flag->label() }}</span>@endforeach
@if ($v('vat') !== null)<span class="chip">{{ $v('vat') ? 'с НДС' : 'без НДС' }}</span>@endif
@if ($v('holder'))<span class="chip">{{ $v('holder') }}</span>@endif
@if ($v('insured_phone'))<a href="tel:+{{ preg_replace('/\D+/', '', $v('insured_phone')) }}" class="tag nums max-w-full"><x-ui.icon name="phone" class="size-3.5 shrink-0"/><span class="truncate">{{ $v('insured_name') ? $v('insured_name').' ' : '' }}{{ $v('insured_phone') }}</span></a>@elseif ($v('insured_name'))<span class="tag">{{ $v('insured_name') }}</span>@endif
@foreach ($v('docs_required') ?? [] as $d)@if ($doc = \App\Vendors\DocRequirement::tryFrom($d))<span class="tag">{{ $doc->label() }}</span>@endif @endforeach
