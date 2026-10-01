{{-- «✨ Распознать» (ScanController) у цепочки «Из писем» и в деле ТС ($subject — Scan\Subject) — содержимое окна
     x-mail.scan-window, три шага одного фрейма:
     files — документы (миниатюра первой страницы) и фото плитками, документы отмечены, у фото «Выбрать все»;
     reading — отмеченные списком с полосой готовности, у каждого кольцо или галка; фрейм перечитывается по событию
     scan (scan_controller, морфом — без мигания); fields — что изменится, тремя группами `.list` (ScanFields::of):
     «Новое» — переключатель включён, «Расходится» — «было → в документе», выключен; спор документов — выбор
     галкой; «Совпадает» — свёрнуто. Кнопка считает, сколько полей изменится. Кнопки — полосой у края шторки. --}}
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
        @php
            $groups = collect($rows)->groupBy('state', true);
            $sub = fn (string $label, array $from) => '<span>'.e($label).'</span><span>'.e(implode(', ', $from)).'</span>';
        @endphp
        <form method="post" action="{{ $url }}/apply" data-turbo-frame="_top" data-action="change->scan#tally">
            @csrf
            @foreach ($ids as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach

            @if ($groups->has('new'))
                <div class="list-head">Новое</div>
                <div class="list">
                    @foreach ($groups['new'] as $field => $row)
                        @if (count($row['options']) === 1)
                            @php $o = $row['options'][0]; @endphp
                            <label class="row row-switch">
                                <span class="min-w-0 flex-1"><span class="scan-value">@include('park.requests.scan-value', ['field' => $field, 'text' => $o['text']])</span><span class="row-sub">{!! $sub($row['label'], $o['from']) !!}</span></span>
                                <input type="checkbox" switch class="switch shrink-0" name="pick[{{ $field }}]" value="{{ $o['text'] }}" checked>
                            </label>
                        @else
                            {{-- Документы называют поле по-разному: выбрать одно или оставить пустым. --}}
                            @foreach ($row['options'] as $i => $o)
                                <label class="row row-check">
                                    <span class="min-w-0 flex-1"><span class="scan-value">@include('park.requests.scan-value', ['field' => $field, 'text' => $o['text']])</span><span class="row-sub">{!! $sub($row['label'], $o['from']) !!}</span></span>
                                    <span class="check"><input type="radio" name="pick[{{ $field }}]" value="{{ $o['text'] }}" @checked($i === 0)></span>
                                </label>
                            @endforeach
                            <label class="row row-check">
                                <span class="min-w-0 flex-1 text-ink-muted">Не заполнять<span class="row-sub"><span>{{ $row['label'] }}</span></span></span>
                                <span class="check"><input type="radio" name="pick[{{ $field }}]" value=""></span>
                            </label>
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($groups->has('differs'))
                <div class="list-head">Расходится</div>
                <div class="list">
                    @foreach ($groups['differs'] as $field => $row)
                        @if (count($row['options']) === 1)
                            @php $o = $row['options'][0]; @endphp
                            <label class="row row-switch">
                                <span class="min-w-0 flex-1">
                                    <span class="scan-value scan-change"><span class="text-ink-muted">@include('park.requests.scan-value', ['field' => $field, 'text' => $row['current']['text']])</span><span class="text-ink-dim" aria-hidden="true">→</span>@include('park.requests.scan-value', ['field' => $field, 'text' => $o['text']])</span>
                                    <span class="row-sub">{!! $sub($row['label'], $o['from']) !!}</span>
                                </span>
                                <input type="checkbox" switch class="switch shrink-0" name="pick[{{ $field }}]" value="{{ $o['text'] }}">
                            </label>
                        @else
                            <label class="row row-check">
                                <span class="min-w-0 flex-1"><span class="scan-value">@include('park.requests.scan-value', ['field' => $field, 'text' => $row['current']['text']])</span><span class="row-sub">{!! $sub($row['label'], $row['current']['from']) !!}</span></span>
                                <span class="check"><input type="radio" name="pick[{{ $field }}]" value="" checked></span>
                            </label>
                            @foreach ($row['options'] as $o)
                                <label class="row row-check">
                                    <span class="min-w-0 flex-1"><span class="scan-value scan-change"><span class="text-ink-dim" aria-hidden="true">→</span>@include('park.requests.scan-value', ['field' => $field, 'text' => $o['text']])</span><span class="row-sub">{!! $sub($row['label'], $o['from']) !!}</span></span>
                                    <span class="check"><input type="radio" name="pick[{{ $field }}]" value="{{ $o['text'] }}"></span>
                                </label>
                            @endforeach
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($groups->has('same'))
                <details class="scan-same">
                    <summary class="list-head">Совпадает <span class="nums">{{ $groups['same']->count() }}</span><x-ui.icon name="chevron-down" class="scan-chevron size-4 text-ink-dim"/></summary>
                    <div class="list">
                        @foreach ($groups['same'] as $field => $row)
                            <div class="row"><span class="min-w-0 flex-1"><span class="scan-value">@include('park.requests.scan-value', ['field' => $field, 'text' => $row['current']['text']])</span><span class="row-sub">{!! $sub($row['label'], $row['current']['from']) !!}</span></span></div>
                        @endforeach
                    </div>
                </details>
            @endif

            @if (! $groups->has('new') && ! $groups->has('differs'))
                <x-ui.empty class="py-8">{{ $groups->has('same') ? 'Документы подтверждают то, что есть' : 'Ничего не нашлось' }}</x-ui.empty>
            @endif

            <div class="scan-foot flex items-center gap-2">
                <a href="{{ $url }}?{{ http_build_query(['ids' => $ids, 'files' => 1]) }}" class="btn btn-quiet !px-3" data-turbo-frame="scan-frame" aria-label="Файлы" title="Файлы"><x-ui.icon name="chevron-left" class="size-5"/></a>
                @if ($subject->creates())
                    <button name="then" value="stay" class="btn btn-quiet flex-1" data-scan-target="apply">Подставить <span class="nums" data-scan-target="changes"></span></button>
                    <button name="then" value="create" class="btn btn-accent flex-1">Завести</button>
                @else
                    <button name="then" value="stay" class="btn btn-accent flex-1" data-scan-target="apply">Подставить <span class="nums" data-scan-target="changes"></span></button>
                @endif
            </div>
        </form>
    @endif
</div>
</turbo-frame>
