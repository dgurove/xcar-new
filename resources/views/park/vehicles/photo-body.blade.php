{{-- Начинка карточки кадров: выбор файлов и полоса прогресса у тех, куда можно добавлять, и сама сетка. --}}
@if ($edit)
    <input type="file" accept="image/*,.heic,.heif" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
@endif
@php $me = auth()->user(); @endphp
@if (($collapsed ?? false) && $me->isAdmin())<div class="photos-over"><x-ui.photos-all/></div>@endif
{{-- Глаз — показ в продаже, только админу: в деле ТС видны все кадры, скрытый только сереет. --}}
<x-ui.photos :photos="$photos" :readonly="! $edit" grid :hide="$me->isAdmin()" :rotatable="$me->canManagePark()" :deletable="$me->canManagePark()" :main="false" :id="'gallery-'.$stage->value" :pending="$pending ?? 0"/>
