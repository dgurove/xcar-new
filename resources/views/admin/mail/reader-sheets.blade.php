{{-- Читалка «Завести» (Scan\Reader::live, x-mail.reader-body): строка хода и лента листов — то, что reader_controller
     перерисовывает морфом по событию scan. Строка хода — пока читается, ждёт очереди или остановлено: «Читаю 2 из 4» с
     полосой и «Стоп»; остановлено — «Прочитано 2 из 4» и «Дочитать» («Повторить», если осталось только не прочтённое).
     Лист — документ в порядке чтения: первая страница, у читаемого — колдующая искра и бегущая полоса, прочитанный —
     с галкой, ждущий — притушен; нажатие открывает его в шторке документов. Когда всё прочитано, ленту прячет CSS
     (кроме разбора письма — там лента и есть список документов письма). --}}
@php
    use App\Mail\Scan\Paper;
    use App\Support\Docs;
    $total = $files->count();
    $ready = $states->filter(fn ($s) => in_array($s, ['done', 'empty', 'lost'], true))->count();
    $onlyLost = $states->contains('lost') && ! $states->contains(fn ($s) => in_array($s, ['idle', 'wait', 'busy'], true));
    $auto ??= false;
    $first = $auto ? $files->first(fn ($f) => $f->isPdf()) : null;
@endphp
@if (in_array($state, ['reading', 'queued', 'stopped'], true))
    <div class="reader-head">
        <span class="nums shrink-0">
            @if ($state === 'reading')Читаю {{ min($ready + 1, $total) }} из {{ $total }}
            @elseif ($state === 'queued')Ждёт очереди
            @else Прочитано {{ $ready }} из {{ $total }}
            @endif
        </span>
        <span class="scan-bar mt-0 min-w-8 flex-1" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $ready }}"><span style="width: {{ $total ? max(4, round(100 * $ready / $total)) : 0 }}%"></span></span>
        @if ($state === 'stopped')
            <button type="button" class="btn btn-s btn-quiet shrink-0" data-action="reader#read">{{ $onlyLost ? 'Повторить' : 'Дочитать' }}</button>
        @else
            <button type="button" class="btn btn-s btn-quiet shrink-0" data-action="reader#stop">Стоп</button>
        @endif
    </div>
@endif
<div class="reader-sheets">
    @foreach ($files as $f)
        @php
            $s = $states[$f->scanId()];
            $doc = $f instanceof Paper ? Docs::media($f->media) : Docs::attachment($f, $subject->mail());
            $thumb = $f instanceof Paper ? '/files/'.$f->media->id.'?thumb=1' : $subject->mail().'/attachments/'.$f->scanId().'?thumb=1';
        @endphp
        <x-ui.doc :doc="$doc" :auto="$f === $first" class="scan-doc scan-doc--{{ $s }}" id="reader-{{ $f->scanId() }}">
            <span class="scan-sheet">
                <x-ui.file-icon :name="$f->scanName()" :mime="$f->scanMime()" class="scan-icon"/>
                <img src="{{ $thumb }}" alt="" loading="lazy" onerror="this.remove()">
                @if ($s === 'busy')<span class="scan-veil spark-busy" aria-label="Читается"><x-ui.spark class="size-7"/></span>
                @elseif ($s === 'done')<span class="scan-check"><x-ui.icon name="check" class="size-3.5"/></span>
                @endif
            </span>
            <span class="scan-name">
                @if ($s === 'empty')<span class="text-ink-dim">текста нет</span>
                @elseif ($s === 'lost')<span class="text-danger">не прочитан</span>
                @else{{ $f->isPhoto() ? 'Фото' : Docs::label($f->scanName()) }}@endif
            </span>
        </x-ui.doc>
    @endforeach
</div>
