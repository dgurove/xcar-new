{{-- Схема сделки строкой (03.11.2026, владелец: у Альфы стоял «ПРАЙМ по счёту», «а кто нажал, что ПРАЙМ, я не понял; должна быть
     возможность поменять удобно и посмотреть, кто это выбрал»): схема, кто и когда выбрал (`deals.scheme_by|scheme_at`), нажатие —
     шторка «Схема сделки»: варианты строками с фразой, кто кому платит (`DealScheme::hint`), «Сменить» (`ChangeDealScheme`).
     По счёту уже платили — без шторки, словом почему. Оптом схема одна — только строка. --}}
@props(['deal'])
@php
    use App\Offers\DealScheme;
    $scheme = $deal->schemeOf();
    $who = $deal->schemeAuthor?->shortName();
    $choices = DealScheme::choiceFor($deal->offer->vendor?->deal_format) ? DealScheme::casesFor($deal->offer->vendor, $deal->offer) : [];
    $editable = $deal->isActive() && count($choices) > 1 && $deal->moneyEditable() && auth()->user()?->canManageCrm();
    $sheet = 'deal-scheme-'.$deal->id;
    // Кто и когда: «Андрей К., 06.10 17:30»; без человека — «автоматически»; сменить нельзя — почему.
    $by = ($who ?? 'автоматически').', '.\App\Support\Ago::at($deal->scheme_at ?? $deal->created_at)
        .($deal->isActive() && ! $deal->moneyEditable() && count($choices) > 1 ? ', сменить нельзя — по счёту уже платили' : '');
@endphp
<div @if ($editable) data-controller="sheet" @endif class="list mb-3">
    @if ($editable)
        <x-ui.sheet-row>
            <span class="min-w-0 flex-1">
                <span class="block">{{ $scheme->label() }}</span>
                <span class="row-sub">{{ $by }}</span>
            </span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
    @else
        <div class="row">
            <span class="min-w-0 flex-1">
                <span class="block">{{ $scheme->label() }}</span>
                <span class="row-sub">{{ $by }}</span>
            </span>
        </div>
    @endif
    @if ($editable)
        <x-ui.sheet :id="$sheet" title="Схема сделки">
            <form method="post" action="/work/deals/{{ $deal->id }}/scheme" class="flex flex-col gap-4">
                @csrf
                <div class="list">
                    @foreach ($choices as $c)
                        <label class="row row-check">
                            <span class="min-w-0 flex-1"><span class="block">{{ $c->label() }}</span><span class="row-sub">{{ $c->hint() }}</span></span>
                            <span class="check"><input type="radio" name="scheme" value="{{ $c->value }}" @checked($c === $scheme)></span>
                        </label>
                    @endforeach
                </div>
                <x-ui.sheet-foot>Сменить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    @endif
</div>
