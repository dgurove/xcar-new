{{-- Лицо на конвейере: аватар человека, логотип вендора или значок группы. --}}
@switch($who['type'])
    @case('user')
        <x-ui.avatar :user="$who['model']" :size="28"/>
        @break
    @case('vendor')
        <x-vendor.logo :vendor="$who['model']" class="face-logo"/>
        @break
    @default
        <span class="avatar face-ico"><x-ui.icon :name="$who['icon']" class="size-4"/></span>
@endswitch
