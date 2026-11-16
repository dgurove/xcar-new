{{-- Кто продаёт и договор нашего юрлица — строками первой группы «Денег» (14.11.2026, владелец: «прайм или рос или напрямую
     поставщику, всё в одном месте, компактно»). Строка «Покупатель платит» с настоящим названием и шторка вариантов (15.11.2026, `DealScheme::payeeName`, `note`; варианты
     поставщика — `casesFor`, умолчание первым) меняет схему сразу (`ChangeDealScheme`; не как обычно у поставщика — «Точно?»
     `data-scheme-guard`, «Исправить» возвращает прежнюю); под ним кто кому платит (`hint`) и кто выбрал. Выбора нет
     (Совкомбанк — всегда ПРАЙМ, по счёту уже платили) — в «Деньгах» строки нет, продавец — меткой у заголовка (15.11.2026,
     владелец: «ты реально считаешь, что это хороший дизайн?» — «ПРАЙМ, у Совкомбанка всегда» и абзац занимали место формы);
     `fixed` — показать его строкой (окно сделки модератора). У договора нашего юрлица (ПРАЙМ, Р.О.С.) — строка «Договор»
     (`x-deal.contract-price`: сумма и НДС, шторкой). `bare` — только строки, группу `.list` ставит «Деньги». --}}
@props(['deal', 'bare' => false, 'fixed' => true, 'price' => true])
@php
    use App\Offers\DealContract;
    use App\Offers\DealScheme;
    $scheme = $deal->schemeOf();
    $choices = DealScheme::casesFor($deal->offer->vendor, $deal->offer);
    $admin = auth()->user()?->canManageCrm();
    $editable = $deal->isActive() && count($choices) > 1 && $deal->moneyEditable() && $admin;
    $contract = $deal->hasContract() && $scheme->paysCar() ? DealContract::for($deal)->loadMissing('priceAuthor') : null;
@endphp
@if ($editable || $fixed || ($contract && $price))
    @unless ($bare)<div class="list">@endunless
    @php
        $owner = $deal->contract?->seller?->name;
        $payee = $scheme->payeeName($deal->offer, $owner);
        $locked = $deal->isActive() && ! $deal->moneyEditable() && count($choices) > 1;
    @endphp
    @if ($editable)
        {{-- Вариант А (15.11.2026, владелец): строкой «Покупатель платит — название ›», варианты с одной строкой смысла —
             шторкой; кто и когда выбрал — в «Истории». Выбор отправляется сразу; необычный — «Точно?» (`data-scheme-guard`). --}}
        <div data-controller="sheet" class="contents">
            <x-ui.sheet-row>
                {{-- Название целиком: переносится подпись слева, а не «АльфаСтрахов-ание» посреди слова. --}}
                <span class="min-w-0 flex-1 text-ink-muted">Кому</span>
                <span class="max-w-[65%] shrink-0 text-right">{{ $payee }}</span>
                <x-ui.chevron/>
            </x-ui.sheet-row>
            <x-ui.sheet :id="'scheme-'.$deal->id" title="Кому платит покупатель" :open="$errors->has('scheme') || $errors->has('scheme_check')">
                <form method="post" action="/work/deals/{{ $deal->id }}/scheme" data-controller="autosubmit" data-scheme-guard data-sheet-clean>
                    @csrf
                    @if ($errors->has('scheme_check'))<input type="hidden" name="scheme_ok" value="1">@endif
                    <div class="list">
                        @foreach ($choices as $c)
                            <label class="row cursor-pointer">
                                <input type="radio" name="scheme" value="{{ $c->value }}" class="sr-only peer" @checked($c === $scheme) data-action="change->autosubmit#submit" @if ($ask = DealScheme::unusual($deal->offer, $c)) data-ask="{{ $ask }}" @endif>
                                <span class="min-w-0 flex-1"><span class="block break-words">{{ $c->payeeName($deal->offer, $owner) }}</span><span class="row-sub">{{ $c->note() }}</span></span>
                                @if ($c === $scheme)<x-ui.icon name="check" class="size-5 shrink-0 text-accent-text"/>@endif
                            </label>
                        @endforeach
                    </div>
                    @error('scheme')<p class="field-error mt-2">{{ $message }}</p>@enderror
                </form>
            </x-ui.sheet>
        </div>
    @elseif ($fixed)
        {{-- Выбора нет (Совкомбанк, по счёту платили) — та же строка без «›». --}}
        <div class="row">
            <span class="min-w-0 flex-1 text-ink-muted">Кому</span>
            <span class="max-w-[65%] shrink-0 text-right">{{ $payee }}@if ($locked)<span class="row-sub">сменить нельзя, по счёту платили</span>@endif</span>
            <x-ui.chevron-space/>
        </div>
    @endif
    @if ($contract && $price)<x-deal.contract-price :deal="$deal" :contract="$contract"/>@endif
    @unless ($bare)</div>@endunless
@endif
