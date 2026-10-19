{{-- «Выставить в продажу» и «Отдать» оценённого черновика — под «Показом» (19.10.2026, владелец: «перед выставлением в
     продажу указывается, до какой даты и кому показывать»): тронутые «Приём до» и «Кому показывать» сначала сохраняются
     (save-bar перед чужой формой), публикация слотом вписанный срок приёма не затирает. Кнопки шлют события — шторки стоят вне
     формы карточки: «Выставить в продажу» — `x-offer.publish-sheet` (`publish-{n}:open`), «Отдать» — `x-offer.give-sheet`. --}}
<div class="flex gap-2">
    <button type="button" class="btn btn-s btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="publish-{{ $n }}:open">Выставить в продажу</button>
    @if ($give)<button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="give-{{ $n }}:open">Отдать</button>@endif
</div>
