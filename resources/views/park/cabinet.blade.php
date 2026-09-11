<x-ui.cabinet title="Кабинет" surface="park">
    @php
        $expected = \App\Park\Vehicle::where('state', \App\Park\VehicleState::Expected)->count();
        $stored = \App\Park\Vehicle::where('state', \App\Park\VehicleState::Stored)->count();
        $requests = \App\Park\Request::where('state', \App\Park\RequestState::New)->count();
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ([[$stored, 'на стоянке', '/mashiny'], [$expected, 'ожидается', '/mashiny?preset=expected'], [$requests, 'новых заявок', '/zayavki']] as [$value, $label, $href])
            <a href="{{ $href }}" class="box transition-colors hover:bg-accent-soft">
                <div class="nums text-[40px] leading-none">{{ $value }}</div>
                <div class="mt-3 text-sm text-ink-muted">{{ $label }}</div>
            </a>
        @endforeach
    </div>
    <form method="post" action="/vyhod" class="mt-8">@csrf<x-ui.button variant="secondary"><x-ui.icon name="exit" class="size-5"/> Выйти</x-ui.button></form>
</x-ui.cabinet>
