{{-- Кадры ТС парковки пачками по стадии (`Media\PhotoBlocks`, владелец 05.10.2026) — обложками (`x-ui.photo-pack`, 23.11.2026), кадры
     — плиткой в шторке. Один кусок на все места парковки (23.11.2026, владелец: «приводи всё к единым модулям»; были лента окна
     из «Заявок», развёрнутая плитка дела и карточки окон шага):
     - `mode=window` (по умолчанию) — окно «Наличия» и «Заявок»: смотреть, поворот — `canManagePark`, глаз — админу;
     - `mode=case` — дело ТС: в пачку, куда можно класть сейчас (`PhotoStage::addable`), — добавить, повернуть, удалить; в
       остальные — только смотреть; заглушки кадров письма, что ещё едут, — в «От страховой»;
     - `mode=view` + `only` — пройденный шаг на шкале дела: одна пачка только для просмотра;
     - `mode=step` + `only` — окно «Приём» / «Выдача»: одна пачка своей стадии, съёмка в шторке; `files` — той же кнопкой и
       файлы (PDF и прочее — в документы ТС, `any`/`other` у photos_controller), «Документ из фото» (`x-ui.photo-pdf`), под
       кадрами — файлы этого шага.
     Глаз — показ в продаже, только админу. Просмотр листает все пачки подряд (группа `car-N`). `root` — id обёртки: ответ
     загрузки (`photos-stream`) подменяет её целиком, открытая шторка остаётся (`app.js` бережёт `open`). --}}
@php
    use App\Park\PhotoStage;
    $me = auth()->user();
    $root ??= 'detail-photos';
    $mode ??= 'window';
    $only ??= null;
    $files = ($files ?? false) && $mode === 'step';
    $all = $vehicle->photos();
    $always = $only ? [$only] : ($mode === 'case' ? array_values(array_filter(PhotoStage::cases(), fn ($s) => $s->addable($vehicle))) : []);
    $blocks = \App\Media\PhotoBlocks::of($only ? $all->filter(fn ($m) => PhotoStage::of($m) === $only)->values() : $all, $always);
    $pending = $mode === 'case' ? \App\Mail\Jobs\ImportThreadFiles::pending($vehicle) : 0;
@endphp
<div id="{{ $root }}" class="photo-packs">
    @foreach ($blocks as ['stage' => $stage, 'photos' => $photos, 'when' => $when])
        @php
            $edit = match ($mode) { 'case' => $stage->addable($vehicle), 'step' => true, default => false };
            // Только смотреть (без перестановки и броска файлов): чужая стадия в деле и пройденный шаг. Окно — как было: глаз и поворот.
            $view = ($mode === 'case' && ! $edit) || $mode === 'view';
            $row = $root.'-'.$stage->value;
            // Окно выдачи — «Акт выдачи»: прикладывают акт, фото редко (06.11.2026, владелец).
            $title = $files && $stage === PhotoStage::Release ? 'Акт выдачи' : $stage->label();
        @endphp
        <x-ui.photo-pack :id="$row" :photos="$photos" :title="$title" :when="$when" :hide="$me->isAdmin()"
            data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-stage-value="{{ $stage->value }}" data-photos-group-value="car-{{ $vehicle->id }}"
            data-photos-order="{{ array_search($stage, PhotoStage::cases(), true) }}" :data-photos-readonly-value="$view ? 'true' : null"
            :data-photos-any-value="$files ? 'true' : null" :data-photos-other-value="$files ? 'papers' : null">
            @if ($edit)
                <input type="file" accept="image/*,.heic,.heif{{ $files ? ',.pdf,.doc,.docx,.xls,.xlsx' : '' }}" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
                <div hidden data-photos-target="progress" class="mb-3">
                    <div class="mb-1 text-sm text-ink-muted" data-label></div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                </div>
            @endif
            @if ($files)
                {{-- Окно приёма и выдачи (06.11.2026, Сергей; 23.11.2026, владелец: «меню очень криво»): «добавить» первой клеткой
                     плитки — фото или файл как есть (PDF и прочее — в документы шага), «Документ из фото» — словом справа в строке
                     счёта (`x-ui.photo-pdf`: несколько снимков одним PDF с названием). --}}
                <x-slot:bar>
                    <x-ui.photo-pdf :id="'pdf-'.$vehicle->id.'-'.$stage->value" :url="'/cars/'.$vehicle->id.'/report/pdf'" :stage="$stage->value" label="Документ из фото" always button="letters-act"
                        :name="$stage === PhotoStage::Release ? 'Акт выдачи' : ($stage === PhotoStage::Intake ? 'Акт приёма' : '')" :chips="['Акт выдачи', 'Акт приёма', 'АПП', 'Разрешение на выдачу', 'Доверенность']"/>
                </x-slot:bar>
            @endif
            <x-ui.photos :photos="$photos" :readonly="$view" :add="$edit" :add-label="$files ? 'Фото или файл' : 'Добавить фото'" :deletable="$edit && $me->canManagePark()" :hide="$me->isAdmin()"
                :rotatable="$me->canManagePark()" :main="false" :id="$row" grid :pending="$stage === PhotoStage::Vendor ? $pending : 0"/>
            @if ($files)<div class="mt-3 [&>.papers-list[hidden]]:hidden">@include('park.vehicles.papers', ['papersStage' => $stage])</div>@endif
        </x-ui.photo-pack>
    @endforeach
</div>
