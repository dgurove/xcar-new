{{-- Человек строкой без плашки: аватар, имя, под ним номер (`x-ui.tel` — нажатие «Скопировать / Позвонить», 11.11.2026;
     трубки и звонка всей строкой больше нет). Менеджер покупателя на странице ТС, в каталоге и в профиле. --}}
@props(['user', 'size' => 40])
<div {{ $attributes->class('flex items-center gap-3') }}>
    <x-ui.avatar :user="$user" :size="$size"/>
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $user->name }}</span>
        @if ($user->phone)<x-ui.tel :value="$user->phone" class="block w-fit text-sm"/>@endif
    </span>
</div>
