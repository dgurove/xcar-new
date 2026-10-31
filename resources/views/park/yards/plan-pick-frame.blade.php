{{-- Схема выбранной парковки под полями «Парковка» и «Место» (`spots_controller`, цель plan): грузится, когда шторка
     открыта, и меняется вместе с парковкой; у парковки без схемы пусто. $pick — ТС, которую ставят (её место можно
     выбрать снова), $at — её место сейчас. --}}
<turbo-frame id="plan-pick-{{ $pick ?: 'new' }}" class="plan-pick-frame" loading="lazy" data-spots-target="plan" data-pick="{{ $pick ?: 0 }}" data-at="{{ $at ?? '' }}"></turbo-frame>
