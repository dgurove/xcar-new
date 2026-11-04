{{-- Сколько задач у этапа и сколько из них горит. --}}
<span class="col-count">{{ $stage['total'] }}</span>
@if ($stage['hot'])
    <span class="col-hot"><x-ui.icon name="fire" class="size-3.5"/>{{ $stage['hot'] }}</span>
@endif
