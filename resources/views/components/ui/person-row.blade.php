{{-- Человек строкой без плашки: аватар, имя, телефон; с телефоном вся строка звонит, значок трубки справа. Менеджер
     покупателя на странице ТС, в каталоге и в профиле. --}}
@props(['user', 'size' => 40])
@php $tag = $user->phone ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($user->phone) href="tel:+{{ $user->phone }}" @endif {{ $attributes->class('flex items-center gap-3') }}>
    <x-ui.avatar :user="$user" :size="$size"/>
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $user->name }}</span>
        @if ($user->phone)<span class="nums block text-sm text-ink-muted">{{ $user->phoneFormatted() }}</span>@endif
    </span>
    @if ($user->phone)<x-ui.icon name="phone" class="size-5 shrink-0 text-ink-muted"/>@endif
</{{ $tag }}>
