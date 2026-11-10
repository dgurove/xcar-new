{{-- Шторка «Показать менеджеру» (`x-deal.reply-review`): текст писем разбора (только админу), пункты `Disclosures::proposals`
     с галками и карандашом, своё сообщение, галка шага. Ждёт переменные компонента: $id, $deal, $letters, $proposals, $step, $picked. --}}
@php
    use App\Offers\Actions\Disclose;
    use App\Offers\Disclosures;
    use App\Support\Ago;
    // Ждём счёт поставщика, а в письме файл (10.11.2026) — он «Счёт поставщика», отмечен сам; без него шаг не пройти.
    $billed = $step && \App\Offers\SupplierInvoice::needs($deal, $step);
    $guess = $billed ? \App\Offers\SupplierInvoice::guess($deal->offer->getMedia('papers')->whereIn('id', collect($proposals)->pluck('media')->filter())->sortByDesc('id')) : null;
    $picked = $picked ?: ($guess ? ['file:'.$guess->id] : []);
@endphp
{{-- `$line` — шторку открывает строка письма в шаге (10.11.2026): она и есть разбор — «Открыть» у письма, «Не нужно» в подвале,
     что уже показано — сверху. --}}
@php $line = ($line ?? false) && $letters->isNotEmpty(); @endphp
<x-ui.sheet :id="$id" :title="$line ? ($billed ? 'Счёт' : 'Письмо').' от '.($deal->offer->vendor?->name ?? 'поставщика') : 'Показать менеджеру'" tall>
    @if ($line)
        <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" id="{{ $id }}-skip" hidden data-turbo-confirm="Менеджер не узнает об этом письме" data-turbo-confirm-label="Не нужно">@csrf</form>
    @endif
    <form method="post" action="/work/deals/{{ $deal->id }}/disclose" class="flex flex-col gap-4" data-controller="disclose">
        @csrf
        @error('disclose')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
        @foreach ($letters as $m)
            {{-- Подпись и оговорки страховой бывают на экран — письмо листается внутри себя, пункты видны сразу. --}}
            <div class="max-h-64 overflow-y-auto rounded-(--radius-m) bg-surface-2 px-4 py-3">
                <p class="mb-1 flex items-baseline gap-3 text-sm text-ink-muted"><span class="min-w-0 flex-1">Письмо {{ Ago::html($m->date_at, true, words: false) }} — видно только вам</span>@if ($line)<button type="button" class="shrink-0 text-accent-text" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $deal->offer->number }}/letters?at={{ $m->id }}">Открыть</button>@endif</p>
                <p class="whitespace-pre-line break-words text-sm">{{ $m->replyText() }}</p>
            </div>
        @endforeach
        @if ($proposals)
            <div>
                <p class="field-label mb-2">Что показать менеджеру</p>
                <div class="list">
                    @foreach ($proposals as $key => $p)
                        @php $box = $id.'-'.$loop->index; @endphp
                        <div class="row items-start gap-3" data-disclose-target="row">
                            <span class="check mt-0.5"><input type="checkbox" id="{{ $box }}" name="on[]" value="{{ $key }}" @checked(in_array($key, $picked, true)) data-action="disclose#update"></span>
                            <div class="min-w-0 flex-1">
                                <label for="{{ $box }}" class="block cursor-pointer">
                                    <span class="block text-xs text-ink-muted">{{ $guess && $p['media'] === $guess->id ? 'Счёт поставщика' : Disclosures::KINDS[$p['kind']] }}</span>
                                    <span class="block break-words" data-disclose-target="text">{{ $p['kind'] === 'file' ? $p['value'] : old('value.'.$key, $p['value']) }}</span>
                                </label>
                                @if ($p['kind'] !== 'file')
                                    <input type="text" name="value[{{ $key }}]" value="{{ old('value.'.$key, $p['value']) }}" class="field-input mt-1" hidden data-disclose-target="input" data-action="input->disclose#typed">
                                @endif
                            </div>
                            @if ($p['kind'] === 'file')
                                <a href="/files/{{ $p['media'] }}" class="btn btn-ghost btn-s -my-1 px-1.5 text-ink-muted" aria-label="Открыть документ"><x-ui.icon name="eye" class="size-5"/></a>
                            @else
                                <button type="button" class="btn btn-ghost btn-s -my-1 px-1.5 text-ink-muted" aria-label="Изменить" data-action="disclose#edit"><x-ui.icon name="edit" class="size-4"/></button>
                            @endif
                        </div>
                    @endforeach
                </div>
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
