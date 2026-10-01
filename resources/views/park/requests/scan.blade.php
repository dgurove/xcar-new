{{-- «✨ Распознать» (ScanController) у цепочки «Из писем» и в деле ТС ($subject — Scan\Subject) — содержимое окна
     x-mail.scan-window, три шага одного фрейма:
     files — документы (миниатюра первой страницы) и фото плитками, документы отмечены, у фото «Выбрать все»;
     reading — отмеченные списком с полосой готовности, у каждого кольцо или галка; фрейм перечитывается по событию
     scan (scan_controller, морфом — без мигания); fields — найденное строками списка: одно значение — строкой с
     источником, несколько — строка раскрывается вариантами с галкой. Кнопки — полосой у нижнего края шторки. --}}
@php
    use App\Support\Docs;
    $url = $subject->url();
    $docs = $files->reject->isPhoto();
    $photos = $files->filter->isPhoto();
    $label = fn ($a) => $a->isPhoto() ? 'Фото' : Docs::label((string) $a->filename);
@endphp
<turbo-frame id="scan-frame">
<div data-controller="scan" data-scan-subject-value="{{ $subject->key() }}" data-scan-max-value="{{ $max }}" data-scan-url-value="{{ $url }}?{{ http_build_query(['ids' => $ids]) }}" @if ($step === 'reading' && $reading) data-scan-busy-value="true" @endif class="scan">
    <div class="scan-for">
        <span class="font-medium {{ $subject->hasCar() ? 'text-ink' : 'text-ink-muted' }}">{{ $subject->title() }}</span>
        <x-vendor.ref :vendor="$subject->vendor()" :ref="$subject->ref()"/>
    </div>

    @if ($step === 'files')
        @if ($files->isEmpty())
            <x-ui.empty class="py-8">Файлов нет</x-ui.empty>
        @else
            <form method="post" action="{{ $url }}" data-action="change->scan#count">
                @csrf
                @if ($docs->isNotEmpty())
                    <div class="list-head">Документы <span class="nums">{{ $docs->count() }}</span></div>
                    <div class="scan-docs">
                        @foreach ($docs as $a)
                            <label class="scan-doc" title="{{ $a->filename }}">
                                <input type="checkbox" name="ids[]" value="{{ $a->id }}" @checked(in_array($a->id, $checked, true))>
                                <span class="scan-sheet">
                                    <x-ui.file-icon :name="$a->filename" :mime="$a->mime" class="scan-icon"/>
                                    <img src="/mail/attachments/{{ $a->id }}?thumb=1" alt="" loading="lazy" onerror="this.remove()">
                                    <span class="scan-check"><x-ui.icon name="check" class="size-3.5"/></span>
                                </span>
                                <span class="scan-name">{{ $label($a) }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @if ($photos->isNotEmpty())
                    <div class="list-head">Фото <span class="nums">{{ $photos->count() }}</span>
                        <button type="button" class="ml-auto text-base font-normal text-accent-text" data-action="scan#all" data-scan-target="all">Выбрать все</button>
                    </div>
                    <div class="scan-grid">
                        @foreach ($photos as $a)
                            <label class="scan-tile" title="{{ $a->filename }}">
                                <input type="checkbox" name="ids[]" value="{{ $a->id }}" data-photo @checked(in_array($a->id, $checked, true))>
                                <img src="/mail/attachments/{{ $a->id }}?thumb=1" alt="" loading="lazy">
                                <span class="scan-check"><x-ui.icon name="check" class="size-3.5"/></span>
                            </label>
                        @endforeach
                    </div>
                @endif
                <div class="scan-foot">
                    <button class="btn btn-accent w-full" data-scan-target="submit" @disabled(! $checked)>Распознать <span class="nums" data-scan-target="count">{{ count($checked) }}</span></button>
                </div>
            </form>
        @endif

    @elseif ($step === 'reading')
        @php
            $ready = $texts->reject(fn ($t) => $t === null)->count();
            $lost = $reading ? collect() : $picked->filter(fn ($a) => $texts[$a->id] === null);
        @endphp
        <div class="scan-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $picked->count() }}" aria-valuenow="{{ $ready }}"><span style="width: {{ $picked->count() ? round(100 * $ready / $picked->count()) : 0 }}%"></span></div>
        <div class="list mt-4">
            @foreach ($picked as $a)
                @php $text = $texts[$a->id]; @endphp
                <div class="row">
                    <span class="row-photo row-photo-s"><img src="/mail/attachments/{{ $a->id }}?thumb=1" alt="" loading="lazy" onerror="this.remove()"></span>
                    <span class="min-w-0 flex-1 truncate">{{ $label($a) }}</span>
                    @if ($text !== null && trim($text) !== '')
                        <x-ui.icon name="check-circle" class="size-5 shrink-0 text-accent-text"/>
                    @elseif ($text !== null)
                        <x-ui.state>пусто</x-ui.state>
                    @elseif (in_array($a->id, $reading, true))
                        <span class="scan-spin" aria-label="Читается"></span>
                    @else
                        <x-ui.state tone="danger">не прочитан</x-ui.state>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($lost->isNotEmpty())
            <form method="post" action="{{ $url }}" class="scan-foot">
                @csrf
                @foreach ($picked as $a)<input type="hidden" name="ids[]" value="{{ $a->id }}">@endforeach
                <button class="btn btn-accent w-full">Повторить</button>
            </form>
        @endif

    @else
        <form method="post" action="{{ $url }}/apply" data-turbo-frame="_top">
            @csrf
            @foreach ($ids as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
            @if ($rows)
                <div class="list">
                    @foreach ($rows as $field => $row)
                        @php $pick = $row['options'][$row['pick']]; @endphp
                        @if (count($row['options']) === 1)
                            <div class="row">
                                <span class="scan-label">{{ $row['label'] }}</span>
                                @include('park.requests.scan-value', ['field' => $field, 'option' => $pick])
                                <input type="hidden" name="pick[{{ $field }}]" value="{{ $pick['text'] }}">
                            </div>
                        @else
                            <details class="scan-pick" open>
                                <summary class="row">
                                    <span class="scan-label">{{ $row['label'] }}</span>
                                    <span class="contents" data-scan-shown="{{ $field }}">@include('park.requests.scan-value', ['field' => $field, 'option' => $pick])</span>
                                    <x-ui.icon name="chevron-down" class="scan-chevron size-4 shrink-0 text-ink-dim"/>
                                </summary>
                                @foreach ($row['options'] as $i => $o)
                                    <label class="row row-check scan-opt">
                                        <span class="min-w-0 flex-1" data-scan-option>@include('park.requests.scan-value', ['field' => $field, 'option' => $o])</span>
                                        <span class="check"><input type="radio" name="pick[{{ $field }}]" value="{{ $o['text'] }}" @checked($i === $row['pick']) data-action="scan#pick"></span>
                                    </label>
                                @endforeach
                            </details>
                        @endif
                    @endforeach
                </div>
            @else
                <x-ui.empty class="py-8">Ничего не нашлось</x-ui.empty>
            @endif
            <div class="scan-foot flex items-center gap-2">
                <a href="{{ $url }}?{{ http_build_query(['ids' => $ids, 'files' => 1]) }}" class="btn btn-quiet !px-3" data-turbo-frame="scan-frame" aria-label="Файлы" title="Файлы"><x-ui.icon name="chevron-left" class="size-5"/></a>
                @if ($subject->creates())
                    @if ($rows)<button name="then" value="stay" class="btn btn-quiet flex-1">Подставить</button>@endif
                    <button name="then" value="create" class="btn btn-accent flex-1">Завести</button>
                @elseif ($rows)
                    <button name="then" value="stay" class="btn btn-accent flex-1">Подставить</button>
                @endif
            </div>
        </form>
    @endif
</div>
</turbo-frame>
