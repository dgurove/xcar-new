{{-- Шторка «Показать менеджеру» (`x-deal.reply-review`, окно писем): пункты `Disclosures::proposals` карточками писем
     (`Disclosures::groups`) с галками и карандашом, своё сообщение, галка шага. Ждёт: $id, $deal, $letters, $proposals, $step, $picked,
     необязательно $message (письмо, с которого открыли), $line, $top. --}}
@php
    use App\Offers\Actions\Disclose;
    use App\Offers\Disclosures;
    use App\Support\Ago;
    // Ждём счёт поставщика, а в письме файл (10.11.2026) — он «Счёт поставщика», отмечен сам; без него шаг не пройти.
    $billed = $step && \App\Offers\SupplierInvoice::needs($deal, $step);
    $guess = $billed ? \App\Offers\SupplierInvoice::guess($deal->offer->getMedia('papers')->whereIn('id', collect($proposals)->pluck('media')->filter())->sortByDesc('id')) : null;
    $picked = $picked ?: ($guess ? ['file:'.$guess->id] : array_keys(array_filter($proposals, fn ($p) => $p['pick'] ?? false)));
@endphp
{{-- `$line` — шторку открывает строка письма в шаге (10.11.2026): она и есть разбор — «Открыть» у письма, «Не нужно» в подвале,
     что уже показано — сверху. --}}
@php $line = ($line ?? false) && $letters->isNotEmpty(); @endphp
<x-ui.sheet :id="$id" :title="$line ? ($billed ? 'Счёт' : 'Письмо').' от '.($deal->offer->vendor?->name ?? 'поставщика') : 'Показать менеджеру'" tall>
    @if ($line)
        <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" id="{{ $id }}-skip" hidden data-turbo-confirm="Менеджер не узнает об этом письме" data-turbo-confirm-label="Не нужно">@csrf</form>
    @endif
    {{-- top — шторка в окне писем (во фрейме ленты): ответ — всей странице. --}}
    <form method="post" action="/work/deals/{{ $deal->id }}/disclose" class="flex flex-col gap-4" data-controller="disclose" @if ($top ?? false) data-turbo-frame="_top" @endif>
        @csrf
        @error('disclose')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
        @if ($message ?? null)<input type="hidden" name="message" value="{{ $message->id }}">@endif
        {{-- По письмам карточками (16.11.2026, владелец: «абсолютно безобразное, особенно документы», «по письмам группировать»,
             «должны раскрываться, а не все раскрытые»): раскрыта одна — письмо, с которого открыли, иначе самое новое; свёрнутая —
             кто и когда, что в ней есть; кружок слева — отмечено всё, часть или ничего, нажатие — отметить всё письмо. --}}
        @php $groups = Disclosures::groups($proposals, $letters, isset($message) ? $message->id : null); @endphp
        @if ($groups)
            <div class="flex flex-col gap-2">
                @foreach ($groups as $g)
                    @php
                        $m = $g['letter'];
                        $sum = Disclosures::summary($g['items']);
                        // Имя ящика («implementation») — не человек: тогда только дата.
                        $who = $m ? \App\Mail\Chains\NodeTitle::who($m) : null;
                        $who = $who && str_contains(trim($who), ' ') ? $who : null;
                        // Вторая строка: что в письме, кроме текста, а если только текст — его начало без приветствия.
                        $rest = trim(preg_replace('/^текст(, )?/u', '', $sum));
                        $said = collect($g['items'])->firstWhere('kind', 'text')['value'] ?? '';
                        $preview = collect(preg_split('/\R/u', $said))->map(fn ($l) => trim($l))->first(fn ($l) => $l !== '' && ! preg_match('/^(добрый|здравствуй|коллеги|привет)/iu', $l)) ?? '';
                        $photos = collect($g['items'])->firstWhere('kind', 'photos');
                    @endphp
                    <details class="disclose-group" data-disclose-target="group" @if ($g['open'] || count($groups) === 1) open @endif>
                        <summary class="disclose-head">
                            <button type="button" class="disclose-mark" data-action="disclose#all" aria-label="Отметить всё письмо" @disabled(! $g['items'])></button>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">@if ($m)<span class="nums">{!! Ago::html($m->date_at, true, words: false) !!}</span>@if ($who), {{ $who }}@endif @else Из карточки ТС @endif</span>
                                <span class="block truncate text-ink-muted">{{ $rest !== '' ? $rest : ($preview !== '' ? $preview : 'нечего показать') }}</span>
                            </span>
                            <x-ui.icon name="chevron-down" class="disclose-chev size-4 shrink-0 text-ink-dim"/>
                        </summary>
                        <div class="disclose-body">
                            @foreach ($g['items'] as $key => $p)
                                @php $box = $id.'-'.md5($key); $long = $p['kind'] === 'text'; @endphp
                                <div @class(['disclose-item', 'disclose-item--text' => $long]) data-disclose-target="row">
                                    <span class="check"><input type="checkbox" id="{{ $box }}" name="on[]" value="{{ $key }}" @checked(in_array($key, $picked, true)) data-action="disclose#update"></span>
                                    @if ($p['kind'] === 'photos')
                                        {{-- Все кадры письма одной строкой: по одному их не выбирают, смотрят в письме. --}}
                                        <label for="{{ $box }}" class="disclose-photos">
                                            @foreach (\Spatie\MediaLibrary\MediaCollections\Models\Media::whereIn('id', array_slice($p['media'], 0, 4))->get() as $f)
                                                <img src="/files/{{ $f->id }}" alt="" loading="lazy">
                                            @endforeach
                                            <span class="min-w-0 flex-1 truncate">Фото {{ count($p['media']) }}</span>
                                        </label>
                                    @else
                                        <label for="{{ $box }}" class="min-w-0 flex-1 cursor-pointer">
                                            @unless ($long)<span class="disclose-kind">{{ $guess && $p['media'] === $guess->id ? 'Счёт поставщика' : ($p['label'] ?? Disclosures::KINDS[$p['kind']]) }}</span>@endunless
                                            <span @class(['break-words', 'block' => ! $long, 'disclose-text' => $long, 'truncate' => $p['kind'] === 'file']) data-disclose-target="text">{{ $p['kind'] === 'file' ? $p['value'] : old('value.'.$key, $p['value']) }}</span>
                                        </label>
                                        @if ($long)
                                            <textarea name="value[{{ $key }}]" rows="6" class="field-input mt-1 w-full" hidden data-disclose-target="input" data-action="input->disclose#typed">{{ old('value.'.$key, $p['value']) }}</textarea>
                                        @elseif ($p['kind'] !== 'file')
                                            <input type="text" name="value[{{ $key }}]" value="{{ old('value.'.$key, $p['value']) }}" class="field-input mt-1 w-full" hidden data-disclose-target="input" data-action="input->disclose#typed">
                                        @endif
                                        @if ($p['kind'] === 'file')
                                            <a href="/files/{{ $p['media'] }}" target="_blank" class="disclose-tool" aria-label="Открыть документ"><x-ui.icon name="eye" class="size-4"/></a>
                                        @else
                                            <button type="button" class="disclose-tool" aria-label="Изменить" data-action="disclose#edit"><x-ui.icon name="edit" class="size-4"/></button>
                                        @endif
                                    @endif
                                </div>
                                @if ($long)<button type="button" class="disclose-more" data-action="disclose#more">Весь текст</button>@endif
                            @endforeach
                            @if ($m)
                                <button type="button" class="disclose-more" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $deal->offer->number }}/letters?at={{ $m->id }}">Открыть письмо</button>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
        <div class="fields">
            <x-ui.field type="textarea" name="text" label="Своё сообщение менеджеру" :id="$id.'-text'" :value="old('_sheet') === $id ? old('text') : null" rows="3" data-action="input->disclose#update" data-disclose-target="note"/>
        </div>
        @if ($step)
            <label class="row row-switch rounded-(--radius-m) bg-surface-2 px-4">
                <span class="min-w-0 flex-1">Пройти шаг «{{ $step->label }}»</span>
                <input type="checkbox" switch class="switch" name="step" value="{{ $step->id }}" @checked(old('_sheet') === $id ? old('step') : Disclose::stepByDefault($step, $letters, $deal)) data-action="disclose#update" data-disclose-target="step">
            </label>
        @endif
        @if ($line)
            <div class="sheet-foot flex gap-2">
                <x-ui.button type="submit" variant="secondary" form="{{ $id }}-skip" class="flex-1">Не нужно</x-ui.button>
                <x-ui.button type="submit" class="flex-[2]" data-disclose-target="submit">Показать менеджеру</x-ui.button>
            </div>
        @else
            <x-ui.sheet-foot type="submit" data-disclose-target="submit">Показать менеджеру</x-ui.sheet-foot>
        @endif
    </form>
</x-ui.sheet>
