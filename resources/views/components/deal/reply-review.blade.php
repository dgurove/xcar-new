{{-- «Разобрать ответ» (03.11.2026, владелец: «пришло письмо — менеджеру вообще ничего; админ заходит в сделку, ему чётко видно,
     что пришёл такой-то ответ, и что система предлагает показать — пунктами с галками, каждый можно поправить карандашиком»).
     Ждёт разбора — плашка: «Письмо», «Ничего не показывать», «Показать менеджеру…». Шторка: текст писем (только админу),
     пункты `Offers\Disclosures::proposals` — не отмечено ничего, карандаш правит значение, своё сообщение, галка шага, который
     раньше проходило письмо само (`Disclose::stepExit`). Разбирать нечего — строкой, что менеджер видит из писем, и «Показать…»
     руками: пункты из машины и её документы. `compact` — только плашка, когда есть что разбирать (окошко предложения). --}}
@props(['deal', 'compact' => false])
@php
    use App\Offers\Actions\Disclose;
    use App\Offers\Disclosures;
    use App\Support\Ago;
    $reviews = \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->with('message.attachments')->orderByDesc('id')->get();
    $letters = $reviews->pluck('message')->filter()->values();
    $shown = $compact ? collect() : Disclosures::shown($deal);
    $id = 'disclose-'.$deal->id;
    $offer = $deal->offer;
@endphp
@if ($deal->buyer_id && $deal->isActive() && ($letters->isNotEmpty() || ! $compact))
    @php
        $proposals = Disclosures::proposals($deal, $letters);
        $step = Disclose::stepExit($deal, $letters);
        $picked = old('_sheet') === $id ? (array) old('on', []) : [];
    @endphp
    <div {{ $attributes }} data-controller="sheet">
        @if ($letters->isNotEmpty())
            <div class="proposal">
                <div class="proposal-body">
                    <span class="proposal-mark" aria-hidden="true"><x-ui.icon name="mail" class="size-4"/></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-ink">Ответ страховой{{ $letters->count() > 1 ? ', писем: '.$letters->count() : '' }}</p>
                        <p class="text-sm text-ink-muted">{{ Ago::html($letters->first()->date_at, true) }}, менеджер о нём не знает</p>
                    </div>
                </div>
                <div class="proposal-acts flex-wrap">
                    <x-mail.window-button :url="'/offers/'.$offer->number.'/letters?at='.$letters->first()->id" label="Письмо" class="btn-s"/>
                    <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" data-turbo-confirm="Менеджер не узнает об этом ответе" data-turbo-confirm-label="Ничего не показывать">
                        @csrf
                        <button class="btn btn-s btn-quiet">Не показывать</button>
                    </form>
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open" aria-controls="{{ $id }}">Показать менеджеру…</button>
                </div>
            </div>
        @endif
        @unless ($compact)
            @if ($shown->isNotEmpty())
                <div @class(['flex flex-col gap-1', 'mt-3' => $letters->isNotEmpty()])>
                    @foreach ($shown->take(3) as $d)
                        <p class="text-sm"><span class="text-ink-muted">Менеджеру показано {{ Ago::html($d->created_at, true, words: false) }}:</span> {{ $d->summary() }}</p>
                    @endforeach
                    @if ($letters->isEmpty())<button type="button" class="self-start text-sm text-accent-text" data-action="sheet#open" aria-controls="{{ $id }}">Показать ещё…</button>@endif
                </div>
            @elseif ($letters->isEmpty())
                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-muted">Из писем менеджер ничего не видит
                    <button type="button" class="text-accent-text" data-action="sheet#open" aria-controls="{{ $id }}">Показать менеджеру…</button></p>
            @endif
        @endunless
        <x-ui.sheet :id="$id" title="Показать менеджеру" tall>
            <form method="post" action="/work/deals/{{ $deal->id }}/disclose" class="flex flex-col gap-4" data-controller="disclose">
                @csrf
                @error('disclose')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
                @foreach ($letters as $m)
                    {{-- Подпись и оговорки страховой бывают на экран — письмо листается внутри себя, пункты видны сразу. --}}
                    <div class="max-h-64 overflow-y-auto rounded-(--radius-m) bg-surface-2 px-4 py-3">
                        <p class="mb-1 text-sm text-ink-muted">Письмо {{ Ago::html($m->date_at, true, words: false) }} — видно только вам</p>
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
                                            <span class="block text-xs text-ink-muted">{{ Disclosures::KINDS[$p['kind']] }}</span>
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
                        <input type="checkbox" switch class="switch" name="step" value="{{ $step->id }}" @checked(old('_sheet') === $id ? old('step') : Disclose::stepByDefault($step, $letters)) data-action="disclose#update" data-disclose-target="step">
                    </label>
                @endif
                <x-ui.sheet-foot type="submit" data-disclose-target="submit">Показать менеджеру</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif
