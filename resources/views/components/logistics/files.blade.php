{{-- Фото и документы машины в задаче вывоза (19.10.2026, админы и Татьяна: «не хватает документов и фоток из писем»).
     Кто что видит — `Logistics\FileAccess`: ведущие — всё и глазом решают за логиста (без логиста — за исполнителя), логист —
     открытое ему и глазом решает за исполнителя, исполнитель — открытое ему, без глаз. Глаз кадра — тот же, что в ряду кадров
     (`x-ui.photos` с `hideBy`, `photos#hide` → `/transports/{id}/files/{media}/hide`), у документа — круглая кнопка в строке.
     Ответ на глаз — этот блок целиком (`files-stream`). Пусто — блока нет.
     22.10.2026 (владелец со слов Татьяны: «зачем фотки два раза», «логист не может прикреплять» — исполнитель шлёт ей фото
     почтой и мессенджерами): ленты сверху карточки больше нет, кадры машины — здесь всем, кто их видит; ниже «Фото при
     вывозе» (`x-logistics.photos`, `pickup` — добавляют `pickup`: ведущие и логист всегда, исполнитель до «Забрал»; пока
     открыта шторка «Забрал» — копия `view`, снимают в ней) и «+ Добавить» (лаймом, как в CRM и на парковке) ведущим и логисту (`TransportController::paper`). --}}
@props(['t'])
@php
    use App\Logistics\FileAccess;
    $me = auth()->user();
    $files = FileAccess::files($t);
    $mine = fn ($m) => FileAccess::visible($t, $me, $m);
    // За кого решает глаз этого человека; кто без глаза — видит открытое ему, без глаз.
    $who = FileAccess::toggleFor($t, $me);
    $photos = $files['photos']->filter($mine)->values();
    // Ведёт задачу за исполнителя (ведущие и логист): кадры и документы добавляет и после «Забрал» — их присылают потом.
    $keeps = ($me->leadsLogistics() && ! $t->isOrder()) || $t->hasLogist($me);
    $pickupEdit = $t->isPickup() && $t->isHandledBy($me) && $t->executor_id && ($keeps ? $t->state !== \App\Logistics\TransportState::Cancelled : $t->isOpen() && ! $t->state->picked());
    // Шторка «Забрал» открыта к нажатию — снимают в ней, здесь копия для просмотра (свой id, поток загрузки меняет обе).
    $pickupView = $t->state === \App\Logistics\TransportState::Agreed && ($t->isExecutedBy($me) || $keeps) && ! $t->pickupBlocker($me);
    $pickup = $t->isPickup() && ($pickupEdit || $t->photos('pickup')->isNotEmpty());
    $addsPapers = $t->car && $keeps;
    $papers = $files['papers']->filter($mine)->values();
    $eye = $who === 'logist' ? 'Видит логист' : 'Видит исполнитель';
@endphp
<section id="transport-files-{{ $t->id }}" @if ($photos->isEmpty() && $papers->isEmpty() && ! $pickup && ! $addsPapers) hidden @endif class="mt-5">
    {{-- Кадры пачками, как в CRM и на парковке (23.11.2026, владелец: «в окнах логистики всё ещё горизонтальная лента»): по стадии
         машины с глазом «Видит логист / исполнитель» (`hideBy` — `FileAccess::sees`), и «При погрузке» — кадры вывоза, сюда же
         добавляют, пока можно (`$pickupEdit`; пока открыта шторка «Забрал» — снимают в ней, здесь только смотреть). --}}
    @php
        $blocks = $photos->isNotEmpty() ? \App\Media\PhotoBlocks::of($photos) : [];
        $shots = $pickup ? $t->photos('pickup') : collect();
        $shoots = $pickupEdit && ! $pickupView;
        $hideBy = $who ? fn ($m) => ! FileAccess::sees($m, $who) : null;
    @endphp
    @if ($blocks || $pickup)
        <div class="photo-packs">
            @foreach ($blocks as $i => ['stage' => $stage, 'photos' => $own, 'when' => $when])
                @php $row = 'transport-'.$t->id.'-'.$stage->value; @endphp
                <x-ui.photo-pack :id="$row" :photos="$own" :title="$stage->label()" :when="$when" :hide="(bool) $who" :hide-by="$hideBy" :all="false"
                    data-photos-url-value="/transports/{{ $t->id }}/files" data-photos-readonly-value="true" data-photos-group-value="transport-{{ $t->id }}" data-photos-order="{{ $i }}">
                    <x-ui.photos :photos="$own" readonly :hide="(bool) $who" :hide-by="$hideBy" :eye-title="$eye" :main="false" :id="$row" grid/>
                </x-ui.photo-pack>
            @endforeach
            @if ($pickup)
                @php $row = 'transport-'.$t->id.'-pickup'; @endphp
                <x-ui.photo-pack :id="$row" :photos="$shots" :title="\App\Park\PhotoStage::Pickup->label()" :when="\App\Media\PhotoBlocks::day($shots)" :hide="false"
                    data-photos-url-value="/transports/{{ $t->id }}/media" data-photos-stage-value="pickup" :data-photos-readonly-value="$shoots ? null : 'true'"
                    data-photos-group-value="transport-{{ $t->id }}" data-photos-order="{{ count($blocks) }}">
                    @if ($shoots)
                        <input type="file" accept="image/*,.heic,.heif" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
                        <div hidden data-photos-target="progress" class="mb-3">
                            <div class="mb-1 text-sm text-ink-muted" data-label></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                        </div>
                    @endif
                    <x-ui.photos :photos="$shots" :readonly="! $shoots" :hide="false" :main="false" :id="$row" grid/>
                </x-ui.photo-pack>
            @endif
        </div>
    @endif
    @if ($papers->isNotEmpty() || $addsPapers)
        {{-- Шапка как у документов в CRM и на парковке (`x-car.papers-head`): подпись слева, «+ Добавить» справа; файлы уходят своей
             формой задачи (`TransportController::paper`, видны логисту сразу). --}}
        <div class="letters-head letters-head--acts mt-5">
            <h2 class="!text-sm !font-medium">Документы</h2>
            @if ($addsPapers)
                <form method="post" action="/transports/{{ $t->id }}/papers" enctype="multipart/form-data" data-controller="autosubmit" class="letters-acts">@csrf
                    <label class="letters-act relative cursor-pointer"><x-ui.icon name="plus" class="size-4"/>Добавить<input type="file" name="files[]" multiple class="sr-only" tabindex="-1" data-action="change->autosubmit#submit"></label>
                </form>
            @endif
        </div>
        <div class="flex flex-col">
            {{-- Как документы в CRM и на парковке (23.11.2026, владелец: «сделать как в CRM — «⋯» с тумблером»): строка открывает,
                 под именем — «скрыто» (перечёркнутый глаз) или «видит исполнитель» (лаймом), справа «⋯» без плашки: тумблер «Показывать
                 исполнителю» (логисту — за кого решает этот человек, `FileAccess::toggleFor`) и «Переименовать» — ведущим и логисту. --}}
            @foreach ($papers as $m)
                @php
                    $open = $who ? FileAccess::sees($m, $who) : true;
                    $docName = \App\Media\DocName::of($m);
                    $base = '/transports/'.$t->id.'/files/'.$m->id;
                    $target = $who === 'logist' ? 'логисту' : 'исполнителю';
                @endphp
                <x-ui.file :name="$docName" :mime="$m->mime_type" :bytes="$m->size" :href="'/files/'.$m->id">
                    @if ($who || $keeps)
                        <span class="contents" data-controller="file-rename" data-file-rename-url-value="{{ $base }}" data-file-rename-name-value="{{ $docName }}">
                            <x-ui.more :id="'transport-paper-'.$t->id.'-'.$m->id" label="Действия с документом" class="more-pill--bare" data-file-rename-target="edit">
                                @if ($who)
                                    <form method="post" action="{{ $base }}/eye">@csrf
                                        <button class="menu-item" role="menuitemcheckbox" aria-checked="{{ $open ? 'true' : 'false' }}"><x-ui.icon name="user" class="size-5 shrink-0 text-ink-muted"/><span class="flex-1 whitespace-nowrap text-left">Показывать {{ $target }}</span><span @class(['menu-switch', 'is-on' => $open]) aria-hidden="true"></span></button>
                                    </form>
                                @endif
                                @if ($keeps)<button type="button" class="menu-item" role="menuitem" data-action="file-rename#start"><x-ui.icon name="edit" class="size-5 text-ink-muted"/>Переименовать</button>@endif
                            </x-ui.more>
                            <button type="button" class="btn btn-ghost btn-s px-2 text-accent-text" data-file-rename-target="save" data-action="file-rename#save" aria-label="Сохранить имя" hidden><x-ui.icon name="check" class="size-5"/></button>
                        </span>
                    @endif
                    <x-slot:under>
                        @if ($who)<span @class(['paper-access', 'is-open' => $open])>@if ($open){{ mb_strtolower($eye) }}@else<span class="flex items-center gap-1"><x-ui.icon name="eye-off" class="size-3.5 shrink-0"/>скрыто</span> @endif</span>@endif
                    </x-slot:under>
                </x-ui.file>
            @endforeach
        </div>
    @endif
</section>
