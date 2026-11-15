{{-- Кто продаёт и договор нашего юрлица — строками первой группы «Денег» (14.11.2026, владелец: «прайм или рос или напрямую
     поставщику, всё в одном месте, компактно»). Сегмент «ПРАЙМ | Р.О.С. | Напрямую | ДКП» (`DealScheme::short`, варианты
     поставщика — `casesFor`, умолчание первым) меняет схему сразу (`ChangeDealScheme`; не как обычно у поставщика — «Точно?»
     `data-scheme-guard`, «Исправить» возвращает прежнюю); под ним кто кому платит (`hint`) и кто выбрал. Выбора нет
     (Совкомбанк — всегда ПРАЙМ, по счёту уже платили) — в «Деньгах» строки нет, продавец — меткой у заголовка (15.11.2026,
     владелец: «ты реально считаешь, что это хороший дизайн?» — «ПРАЙМ, у Совкомбанка всегда» и абзац занимали место формы);
     `fixed` — показать его строкой (окно сделки модератора). У договора нашего юрлица (ПРАЙМ, Р.О.С.) — строка «Договор»
     (`x-deal.contract-price`: сумма и НДС, шторкой). `bare` — только строки, группу `.list` ставит «Деньги». --}}
@props(['deal', 'bare' => false, 'fixed' => true])
@php
    use App\Offers\DealContract;
    use App\Offers\DealScheme;
    $scheme = $deal->schemeOf();
    $choices = DealScheme::casesFor($deal->offer->vendor, $deal->offer);
    $admin = auth()->user()?->canManageCrm();
    $editable = $deal->isActive() && count($choices) > 1 && $deal->moneyEditable() && $admin;
    // Кто и когда: «Андрей Кузнецов, 06.10 17:30»; без человека — «автоматически».
    $by = ($deal->schemeAuthor?->shortName() ?? 'автоматически').', '.\App\Support\Ago::at($deal->scheme_at ?? $deal->created_at);
    $contract = $deal->hasContract() && $scheme->paysCar() ? DealContract::for($deal)->loadMissing('priceAuthor') : null;
@endphp
@if ($editable || $fixed || $contract)
    @unless ($bare)<div class="list">@endunless
    @if ($editable)
        <div class="row flex-col items-stretch gap-1.5">
            <span class="text-sm text-ink-muted">Кто продаёт</span>
            <form method="post" action="/work/deals/{{ $deal->id }}/scheme" data-controller="autosubmit" data-scheme-guard class="deal-scheme">
                @csrf
                @if ($errors->has('scheme_check'))<input type="hidden" name="scheme_ok" value="1">@endif
                <div class="segment">
                    @foreach ($choices as $c)
                        <label><input type="radio" name="scheme" value="{{ $c->value }}" @checked($c === $scheme) data-action="change->autosubmit#submit" @if ($ask = DealScheme::unusual($deal->offer, $c)) data-ask="{{ $ask }}" @endif><span>{{ $c->short() }}</span></label>
                    @endforeach
                </div>
                @error('scheme')<p class="field-error">{{ $message }}</p>@enderror
            </form>
            <span class="row-sub"><span>{{ $scheme->hint() }}</span><span class="text-ink-dim">{{ $by }}</span></span>
        </div>
    @elseif ($fixed)
        <div class="row">
            <span class="min-w-0 flex-1 text-ink-muted">Кто продаёт</span>
            <span class="shrink-0">{{ $scheme->short() }}</span>
        </div>
    @endif
    @if ($contract)<x-deal.contract-price :deal="$deal" :contract="$contract"/>@endif
    @unless ($bare)</div>@endunless
@endif
