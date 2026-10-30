{{-- «Настройки → Доставка» (`DeliveryController`, 30.10.2026): что ушло людям за период и открыли ли. Пилюли — «По видам» и
     «По людям», под ними период; у людей строка открывает карточку с последними уведомлениями. Числа — из журнала
     `notice_deliveries`: «Получили» — уведомлений (одно на человека), «Пушей» — принятых сервисом пушей, «Открыли» — доля
     получивших, кто нажал пуш, кнопку Telegram, письмо, карточку или строку ленты. --}}
@php
    use App\Http\Admin\DeliveryController;
    $url = fn (array $set) => '/settings/deliveries?'.http_build_query(array_filter(array_merge(request()->only('view', 'period', 'sort'), $set)));
    $pct = fn ($r) => $r->sent ? round($r->opened * 100 / $r->sent).' %' : '—';
    $perDay = fn ($r) => str_replace('.', ',', (string) round($r->sent / $days, 1));
@endphp
<x-ui.cabinet title="Доставка" :detail="$detail">
    <x-ui.toolbar :sort="$sort" :pills="DeliveryController::VIEWS" :pill="$view" pill-param="view" name="deliveries"/>
    <x-ui.pills class="mt-3">
        @foreach (DeliveryController::PERIODS as $key => $label)
            <x-ui.pill :href="$url(['period' => $key === '7d' ? null : $key])" :current="$period === $key" :tone="$period === $key ? 'accent' : null">{{ $label }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    <div class="mt-4" id="list">
        @if ($rows->isEmpty())
            <x-ui.empty>За этот период уведомлений не было</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deliveries">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @foreach ($rows as $r)
                    @if ($view === 'people')
                        @include('admin.deliveries.person-row')
                    @else
                        @include('admin.deliveries.kind-row')
                    @endif
                @endforeach
            </x-ui.table>
        @endif
    </div>
</x-ui.cabinet>
