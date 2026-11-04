{{-- Лицо держателя: аватар человека, логотип вендора, робот или значок. --}}
@php $size ??= 28; @endphp
@switch($who['type'])
    @case('user')
        @if ($who['model'])
            <x-ui.avatar :user="$who['model']" :size="$size"/>
        @endif
        @break
    @case('vendor')
        @if ($who['model'])
            <x-vendor.logo :vendor="$who['model']" class="face-logo" style="width: {{ $size }}px; height: {{ $size }}px"/>
        @endif
        @break
    @case('robot')
        <span class="avatar avatar-system face-ico" style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon name="bot" class="size-4"/></span>
        @break
    @default
        <span class="avatar face-ico" style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon :name="$who['icon']" class="size-4"/></span>
@endswitch
