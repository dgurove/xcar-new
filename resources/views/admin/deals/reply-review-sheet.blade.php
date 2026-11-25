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
{{-- `$line` — шторку открывает строка письма в шаге (10.11.2026): она и есть разбор — «Открыть» у письма, «Пропустить письмо» в подвале
     (17.11.2026: кнопки под строкой в шаге больше нет),
     что уже показано — сверху. --}}
@php $line = ($line ?? false) && $letters->isNotEmpty(); @endphp
@php $card = $card ?? false; @endphp
<x-ui.sheet :id="$id" :title="$line ? ($billed ? 'Счёт' : 'Письмо').' от '.($deal->offer->vendor?->name ?? 'поставщика') : ($card ? ($cardTitle ?? null ?: 'Показать контакты менеджеру') : 'Показать менеджеру')" :open="$open ?? false" tall>
    @if ($line)
        <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" id="{{ $id }}-skip" hidden data-turbo-confirm="Пропустить письмо?" data-turbo-confirm-text="Менеджер о нём не узнает" data-turbo-confirm-label="Пропустить">@csrf</form>
    @endif
    {{-- top — шторка в окне писем (во фрейме ленты): ответ — всей странице. --}}
    <form method="post" action="/work/deals/{{ $deal->id }}/disclose" class="flex flex-col gap-4" data-controller="disclose" @if ($top ?? false) data-turbo-frame="_top" @endif>
        @csrf
        @error('disclose')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
        @if ($message ?? null)<input type="hidden" name="message" value="{{ $message->id }}">@endif
        {{-- card — пункты без писем («Показать контакт менеджеру», `x-deal.contact-shown`): сервер сверяет с ними же, а письмо, что ждёт
             разбора, остаётся ждать (25.11.2026: сверял с ждущим письмом — документов машины там нет, «Пункты изменились»). --}}
        @if ($card)<input type="hidden" name="card" value="1">@endif
        {{-- Сначала готовое сообщение (16.11.2026, владелец: «не понимаю, что нажать, чтобы отправить менеджеру полный текст письма
             без приветствия и подписи и отредактировать перед отправкой»): поле заполнено текстом письма (`Disclosures::draft` —
             письмо, с которого открыли, иначе самое новое), уходит то, что в поле. Ниже — что приложить галками и другие письма
             с «В сообщение». --}}
        @php
            $sorted = $letters->sortByDesc(fn ($m) => $m->date_at)->values();
            // Показ контакта (`card`): письма — только откуда телефоны, их текст в сообщение не просится.
            $draftLetter = $card ? null : ((isset($message) ? $sorted->firstWhere('id', $message->id) : null) ?? $sorted->first());
            $draft = $draftLetter ? Disclosures::draft($sorted, $draftLetter->id) : '';
            $others = $card ? collect() : $sorted->reject(fn ($m) => $m->is($draftLetter))->map(fn ($m) => ['m' => $m, 'text' => Disclosures::letterText($m)])->filter(fn ($o) => $o['text'] !== '')->values();
            $groups = array_values(array_filter(Disclosures::groups($proposals, $sorted, $draftLetter?->id), fn ($g) => $g['items']));
        @endphp
        {{-- Показ контакта (`card`): сначала контакты, своё сообщение — после «Другого контакта». --}}
        @unless ($card)
        <div>
            <x-ui.field type="textarea" name="text" :label="$draftLetter ? 'Сообщение менеджеру' : 'Своё сообщение менеджеру'" :id="$id.'-text'" :value="old('_sheet') === $id ? old('text') : $draft" rows="7" data-action="input->disclose#update" data-disclose-target="note"/>
            @if ($draftLetter && $draft !== '')
                <p class="mt-1 text-xs text-ink-muted">Текст письма <span class="nums">{!! Ago::html($draftLetter->date_at, true, words: false) !!}</span> без приветствия и подписи, можно править</p>
            @endif
        </div>
        @endunless
        @if ($groups)
            <div>
                <p class="field-label mb-1">Приложить</p>
                <div class="disclose-list">
                    @foreach ($groups as $g)
                        @if (count($groups) > 1)
                            <p class="disclose-sub">@if ($g['letter'])Письмо <span class="nums">{!! Ago::html($g['letter']->date_at, true, words: false) !!}</span>@else Из карточки ТС @endif</p>
                        @endif
                        @foreach ($g['items'] as $key => $p)
                            @php $box = $id.'-'.md5($key); @endphp
                            <div class="disclose-item" data-disclose-target="row">
                                <span class="check"><input type="checkbox" id="{{ $box }}" name="on[]" value="{{ $key }}" @checked(in_array($key, $picked, true)) data-action="disclose#update"></span>
                                @if ($p['kind'] === 'photos')
                                    {{-- Все кадры письма одной строкой: по одному их не выбирают, смотрят в письме. --}}
                                    <label for="{{ $box }}" class="disclose-photos">
                                        @foreach (\Spatie\MediaLibrary\MediaCollections\Models\Media::whereIn('id', array_slice($p['media'], 0, 4))->get() as $f)
                                            <img src="/files/{{ $f->id }}" alt="" loading="lazy">
                                        @endforeach
                                        <span class="min-w-0 flex-1 truncate">Фото {{ count($p['media']) }}</span>
                                    </label>
                                @elseif ($p['kind'] === 'file')
                                    <label for="{{ $box }}" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                                        <x-ui.file-icon :name="$p['value'].'.pdf'" class="file-icon-s"/>
                                        <span class="min-w-0 flex-1 truncate">{{ $guess && $p['media'] === $guess->id ? 'Счёт поставщика' : $p['value'] }}</span>
                                    </label>
                                    <a href="/files/{{ $p['media'] }}" target="_blank" class="disclose-tool" aria-label="Открыть документ"><x-ui.icon name="eye" class="size-4"/></a>
                                @else
                                    <label for="{{ $box }}" class="min-w-0 flex-1 cursor-pointer">
                                        <span class="disclose-kind">{{ $p['label'] ?? Disclosures::KINDS[$p['kind']] }}</span>
                                        <span class="block break-words" data-disclose-target="text">{{ old('edit.'.$key, $p['value']) }}</span>
                                    </label>
                                    <input type="text" name="edit[{{ $key }}]" value="{{ old('edit.'.$key, $p['value']) }}" class="field-input mt-1 w-full" hidden data-disclose-target="input" data-action="input->disclose#typed">
                                    <button type="button" class="disclose-tool" aria-label="Изменить" data-action="disclose#edit"><x-ui.icon name="edit" class="size-4"/></button>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif
        @if ($card)
            {{-- Контакта нет ни в карточке, ни в письмах (или он другой) — вписать: уйдёт менеджеру и встанет в карточку, если там
                 пусто (25.11.2026, владелец: «нужно здесь тоже сделать ввод контактов»). --}}
            <div>
                <p class="field-label mb-1">Другой контакт</p>
                <div class="grid grid-cols-2 gap-2">
                    <x-ui.field name="contact_name" label="Имя" :id="$id.'-name'" :value="old('_sheet') === $id ? old('contact_name') : null" autocomplete="off" data-action="input->disclose#update" data-disclose-target="own"/>
                    <x-ui.field name="contact_phone" type="tel" label="Телефон" :id="$id.'-phone'" :value="old('_sheet') === $id ? old('contact_phone') : null" data-action="input->disclose#update" data-disclose-target="own"/>
                </div>
            </div>
        <div>
            <x-ui.field type="textarea" name="text" :label="$draftLetter ? 'Сообщение менеджеру' : 'Своё сообщение менеджеру'" :id="$id.'-text'" :value="old('_sheet') === $id ? old('text') : $draft" rows="3" data-action="input->disclose#update" data-disclose-target="note"/>
            @if ($draftLetter && $draft !== '')
                <p class="mt-1 text-xs text-ink-muted">Текст письма <span class="nums">{!! Ago::html($draftLetter->date_at, true, words: false) !!}</span> без приветствия и подписи, можно править</p>
            @endif
        </div>
        @endif
        @if ($others->isNotEmpty())
            <div>
                <p class="field-label mb-1">Другие письма</p>
                <div class="disclose-list">
                    @foreach ($others as $o)
                        <div class="disclose-other">
                            <span class="min-w-0 flex-1"><span class="nums block">{!! Ago::html($o['m']->date_at, true, words: false) !!}</span><span class="block truncate text-ink-muted">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', $o['text']), 120) }}</span></span>
                            <button type="button" class="disclose-add" data-action="disclose#append" data-text="{{ $o['text'] }}">В сообщение</button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if ($step)
            <label class="row row-switch rounded-(--radius-m) bg-surface-2 px-4">
                <span class="min-w-0 flex-1">Пройти шаг «{{ $step->label }}»</span>
                <input type="checkbox" switch class="switch" name="step" value="{{ $step->id }}" @checked(old('_sheet') === $id ? old('step') : Disclose::stepByDefault($step, $letters, $deal)) data-action="disclose#update" data-disclose-target="step">
            </label>
        @endif
        @if ($line)
            {{-- Главное — «Отправить менеджеру» во всю ширину; «Пропустить письмо» тихой кнопкой под ней (17.11.2026, владелец: пропуск —
                 внутри шторки, а не большой кнопкой в шаге). --}}
            <div class="sheet-foot flex flex-col gap-1">
                <x-ui.button type="submit" block data-disclose-target="submit">Отправить менеджеру</x-ui.button>
                <x-ui.button type="submit" variant="ghost" form="{{ $id }}-skip" block class="text-ink-muted">Пропустить письмо</x-ui.button>
            </div>
        @else
            <x-ui.sheet-foot type="submit" data-disclose-target="submit">Отправить менеджеру</x-ui.sheet-foot>
        @endif
    </form>
</x-ui.sheet>
