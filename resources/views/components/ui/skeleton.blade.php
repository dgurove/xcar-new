{{-- Скелетон списка: пока фрейм грузится, форма содержимого уже на месте. --}}
@props(['rows' => 3])
<div class="flex flex-col gap-3 py-1" aria-hidden="true">
    @for ($i = 0; $i < $rows; $i++)
        <div class="flex flex-col gap-2">
            <span class="skeleton h-4 {{ $i % 2 ? 'w-2/3' : 'w-3/4' }}"></span>
            <span class="skeleton h-3 w-1/2"></span>
        </div>
    @endfor
</div>
