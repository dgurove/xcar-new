<turbo-frame id="{{ $id }}-items">
    @foreach ($links as $link)
        <span class="menu-item cursor-pointer" role="menuitem" tabindex="0" data-href="{{ $link['href'] }}" data-action="click->menu#go keydown.enter->menu#go">
            <span class="min-w-0 flex-1 py-2"><span class="block">{{ $link['title'] }}</span><span class="block text-xs text-ink-muted">{{ $link['state'] }}</span></span>
            <x-ui.chevron/>
        </span>
    @endforeach
</turbo-frame>
