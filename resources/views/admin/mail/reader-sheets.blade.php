{{-- Читалка «Завести» (Scan\Reader::live, x-mail.reader-body): строка хода и документы строками — то, что reader_controller
     перерисовывает морфом по событию scan. Строка хода — пока читается, ждёт очереди или остановлено: «Читаю 2 из 4» с
     полосой и «Стоп»; остановлено — «Прочитано 2 из 4» и «Дочитать» («Повторить», если осталось только не прочтённое).
     Документ — строкой файла, ход на ней самой (`data-scan`: по значку бежит полоса скана, прочитанный — с галкой). Превью
     нет: в редакторе предложения строк читалки не видно вовсе, ход ставится на строки его «Документов» по отпечатку
     (`data-scan-key`), в разборе письма (data-keep) строки читалки и есть документы письма. --}}
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
<div class="reader-files">
    @foreach ($files as $f)
        @php $href = $f instanceof Paper ? '/files/'.$f->media->id : $subject->mail().'/attachments/'.$f->scanId(); @endphp
        <x-ui.file :name="$f->scanName()" :mime="$f->scanMime()" :href="$href" :auto="$f === $first" data-scan-key="{{ \App\Mail\Scan\Reader::mark($f) }}" data-scan="{{ $states[$f->scanId()] }}"/>
    @endforeach
</div>
