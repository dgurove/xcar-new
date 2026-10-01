{{-- «✨ Распознать» (ScanController) — содержимое окна x-mail.scan-window. Два шага в одном фрейме:
     файлы цепочки плитками (документы отмечены, фото нет; читаемые крутятся, scan_controller перечитывает фрейм по
     событию scan) и, когда всё прочитано, поля: варианты чипами, источник — тусклым словом в чипе. «Подставить» —
     выбор в цепочку и назад к списку, «Завести» — сразу в разбор письма с подставленным. --}}
@php use App\Support\Docs; $c = $candidate; $url = "/requests/from-mail/{$c->id}/scan"; @endphp
<turbo-frame id="scan-frame">
<div data-controller="scan" data-scan-url-value="{{ $url }}?{{ http_build_query(['ids' => $ids]) }}" @if ($waiting) data-scan-busy-value="true" @endif>
    @if ($rows !== null)
        <form method="post" action="{{ $url }}/apply" data-turbo-frame="_top">
            @csrf
            @foreach ($ids as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
            @if ($rows)
                <div class="list">
                    @foreach ($rows as $field => $row)
                        <div class="row items-start">
                            <span class="w-24 shrink-0 py-2 text-sm text-ink-dim">{{ $row['label'] }}</span>
                            <div class="flex min-w-0 flex-1 flex-wrap gap-1.5 {{ $field === 'vin' || $field === 'plate' ? 'nums' : '' }}">
                                @foreach ($row['options'] as $i => $o)
                                    @if (count($row['options']) === 1)
                                        <span class="py-2">{{ $o['text'] }} <span class="ml-1 text-sm text-ink-dim">{{ implode(', ', $o['from']) }}</span></span>
                                    @else
                                        <label class="choice"><input type="radio" name="pick[{{ $field }}]" value="{{ $o['text'] }}" @checked($i === $row['pick'])><span>{{ $o['text'] }}<small class="ml-1.5 text-[length:inherit] opacity-60">{{ implode(', ', $o['from']) }}</small></span></label>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty class="py-6">Ничего не нашлось</x-ui.empty>
            @endif
            <div class="scan-foot flex items-center gap-2">
                <a href="{{ $url }}?{{ http_build_query(['ids' => $ids, 'files' => 1]) }}" class="btn btn-quiet" data-turbo-frame="scan-frame">Файлы</a>
                @if ($rows)<button name="then" value="stay" class="btn btn-quiet ml-auto">Подставить</button>@endif
                <button name="then" value="create" class="btn btn-accent {{ $rows ? '' : 'ml-auto' }}">Завести</button>
            </div>
        </form>
    @elseif ($files->isEmpty())
        <x-ui.empty class="py-6">Файлов нет</x-ui.empty>
    @else
        <form method="post" action="{{ $url }}" data-action="change->scan#count">
            @csrf
            <div class="scan-grid">
                @foreach ($files as $a)
                    @php $photo = $isPhoto($a); @endphp
                    <label class="scan-tile {{ $photo ? 'scan-tile-photo' : '' }}" title="{{ $a->filename }}" @if (in_array($a->id, $busy, true)) aria-busy="true" @endif>
                        <input type="checkbox" name="ids[]" value="{{ $a->id }}" @checked(in_array($a->id, $picked, true)) @disabled($waiting)>
                        @if ($a->isImage())
                            <img src="/mail/attachments/{{ $a->id }}?thumb=1" alt="" loading="lazy">
                        @else
                            <x-ui.file-icon :name="$a->filename" :mime="$a->mime" class="scan-icon"/>
                        @endif
                        @unless ($photo)<span class="scan-name">{{ Docs::label((string) $a->filename) }}</span>@endunless
                        <span class="scan-check"><x-ui.icon name="check" class="size-3.5"/></span>
                    </label>
                @endforeach
            </div>
            <div class="scan-foot"><button class="btn btn-accent w-full" data-scan-target="submit" @if ($waiting) aria-busy="true" disabled @endif>Распознать <span class="nums" data-scan-target="count">{{ count($picked) }}</span></button></div>
        </form>
    @endif
</div>
</turbo-frame>
