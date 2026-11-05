{{-- Человек или группа в фильтре «Ответственный»: аватар (у группы — значок) и имя. --}}
<span class="space-filter-face" data-key="{{ $p['key'] }}">
    @if ($p['user'])
        <x-ui.avatar :user="$p['user']" :size="24"/>
    @else
        <span class="avatar face-ico face-ico-s"><x-ui.icon :name="$p['icon']" class="size-3.5"/></span>
    @endif
    <span class="truncate">{{ $p['user'] ? $p['user']->firstName() : $p['name'] }}</span>
</span>
