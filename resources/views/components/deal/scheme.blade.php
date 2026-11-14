{{-- Кто продаёт и НДС договора — одним блоком первым в «Деньгах» (14.11.2026, владелец: «удобно указание прайм или рос или
     напрямую поставщику, всё в одном месте, компактно»; до того схема была строкой со шторкой, НДС — в карточке ДКП).
     Сегмент «ПРАЙМ | Р.О.С. | Напрямую | ДКП» (`DealScheme::short`, варианты поставщика — `casesFor`, умолчание первым)
     меняет схему сразу (`ChangeDealScheme`; не как обычно у поставщика — «Точно?» `data-scheme-guard`, «Исправить» возвращает
     прежнюю). Под ним — кто кому платит (`hint`) и кто выбрал. Совкомбанк — всегда ПРАЙМ (`casesFor`): без выбора, словом.
     По счёту уже платили — тоже словом. НДС — у договора нашего юрлица (ПРАЙМ, Р.О.С.): «Без НДС | 5 % | 22 %»
     (`DealContract::vatRate`), сразу. --}}
@props(['deal'])
@php
    use App\Offers\DealContract;
    use App\Offers\DealScheme;
    use App\Vendors\DealFormat;
    $scheme = $deal->schemeOf();
    $choices = DealScheme::casesFor($deal->offer->vendor, $deal->offer);
    $admin = auth()->user()?->canManageCrm();
    $editable = $deal->isActive() && count($choices) > 1 && $deal->moneyEditable() && $admin;
    $sovcom = $deal->offer->vendor?->deal_format === DealFormat::Commission;
    // Кто и когда: «Андрей К., 06.10 17:30»; без человека — «автоматически»; сменить нельзя — почему.
    $by = ($deal->schemeAuthor?->shortName() ?? 'автоматически').', '.\App\Support\Ago::at($deal->scheme_at ?? $deal->created_at);
    $why = match (true) {
        $sovcom && $scheme === DealScheme::Prime => 'у Совкомбанка всегда',
        $deal->isActive() && ! $deal->moneyEditable() && count($choices) > 1 => 'по счёту уже платили, сменить нельзя',
        default => null,
    };
    $contract = $deal->hasContract() && $scheme->paysCar() ? DealContract::for($deal) : null;
    $vatEditable = $contract && $deal->isActive() && $admin;
    $nb = fn (string $s) => str_replace(' ', "\u{00A0}", $s);
@endphp
<div class="list deal-scheme mb-3">
    <div class="row flex-col items-stretch gap-1.5">
        <span class="text-sm text-ink-muted">Кто продаёт</span>
        @if ($editable)
            <form method="post" action="/work/deals/{{ $deal->id }}/scheme" data-controller="autosubmit" data-scheme-guard>
                @csrf
                @if ($errors->has('scheme_check'))<input type="hidden" name="scheme_ok" value="1">@endif
                <div class="segment">
                    @foreach ($choices as $c)
                        <label><input type="radio" name="scheme" value="{{ $c->value }}" @checked($c === $scheme) data-action="change->autosubmit#submit" @if ($ask = DealScheme::unusual($deal->offer, $c)) data-ask="{{ $ask }}" @endif><span>{{ $c->short() }}</span></label>
                    @endforeach
                </div>
                @error('scheme')<p class="field-error">{{ $message }}</p>@enderror
            </form>
        @else
            <span class="block">{{ $scheme->short() }}@if ($why)<span class="text-ink-muted">, {{ $why }}</span>@endif</span>
        @endif
        <span class="row-sub">{{ $scheme->hint() }}</span>
        @if ($editable)<span class="row-sub">{{ $by }}</span>@endif
    </div>
    @if ($contract)
        <div class="row flex-col items-stretch gap-1.5">
            <span class="text-sm text-ink-muted">НДС в договоре</span>
            @if ($vatEditable)
                <form method="post" action="/work/deals/{{ $deal->id }}/contract" data-controller="autosubmit">
                    @csrf @method('put')
                    <x-ui.segment name="vat_rate" :options="collect(DealContract::VAT_RATES)->mapWithKeys(fn ($r) => [$r => $r ? $nb($r.' %') : $nb('Без НДС')])->all()" :value="$contract->vatRate()" data-action="change->autosubmit#submit"/>
                </form>
            @else
                <span class="block">{{ $contract->vatRate() ? $contract->vatRate().' %' : 'без НДС' }}</span>
            @endif
        </div>
    @endif
</div>
