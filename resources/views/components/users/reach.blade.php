{{-- Как до человека доходят уведомления — админу в карточке человека (20.10.2026: у логиста 9 уведомлений лежали без
     доставки, и никто не знал). Telegram и каждое устройство пушей: проверено («Пришло?» — «Да»), не пришло («Нет»),
     ошибка доставки, не подтверждено. Ни одного канала — красным. Логистике и парковке — «Отправить инструкцию»: ссылка
     `?reach=1` открывает шторку Telegram и карточку подключения, даже если её скрыли. --}}
@props(['user'])
@php
    use App\Support\Ago;
    use App\Support\Surface;
    $subs = \App\Push\Subscription::where('user_id', $user->id)->latest('id')->get();
    $carrier = $user->isLogist() || $user->isContractor() || $user->isDriver();
    $surface = $carrier ? Surface::Logistics : (($user->isParking() || $user->isParkAdmin()) ? Surface::Park : null);
    $url = $surface?->url($surface === Surface::Logistics ? '/transports?reach=1' : '/requests?reach=1');
    // Устройство, на котором пробное «не пришло» или доставка упала, — не канал (21.10.2026).
    $working = $subs->reject(fn ($s) => $s->failed_at || ($s->silent_at && (! $s->confirmed_at || $s->silent_at->gt($s->confirmed_at))));
    $none = ! $user->telegram_chat_id && $working->isEmpty();
    $text = 'Чтобы не пропускать '.($surface === Surface::Park ? 'заявки' : 'вывозы').": откройте ссылку, нажмите «Подключить Telegram», а на компьютере и телефоне ещё «Включить» уведомления и ответьте «Да», когда придёт пробное";
@endphp
<section class="mt-5">
    <h2 class="list-head">Уведомления</h2>
    <div class="list">
        @if ($none)
            <div class="row text-danger">Не доходят: нет Telegram и рабочих уведомлений на устройствах</div>
        @endif
        <div class="row">
            <span class="min-w-0 flex-1">Telegram</span>
            @if ($user->telegram_chat_id)
                <span class="text-ink-muted">{{ $user->telegram_username ? '@'.$user->telegram_username : 'подключён' }}{{ $user->wantsTelegram() ? '' : ', выключен' }}</span>
            @else
                <span class="text-ink-dim">не подключён</span>
            @endif
        </div>
        @foreach ($subs as $sub)
            <div class="row">
                <span class="min-w-0 flex-1 truncate">{{ $sub->device() }}</span>
                @if ($sub->silent_at && (! $sub->confirmed_at || $sub->silent_at->gt($sub->confirmed_at)))
                    <span class="text-danger">не пришло {{ Ago::stamp($sub->silent_at) }}</span>
                @elseif ($sub->failed_at)
                    <span class="text-danger">ошибка {{ Ago::stamp($sub->failed_at) }}</span>
                @elseif ($sub->confirmed_at)
                    <span class="text-ink-muted">проверено {{ Ago::stamp($sub->confirmed_at) }}</span>
                @else
                    <span class="text-ink-dim">не проверено{{ $sub->last_sent_at ? ', ушло '.Ago::stamp($sub->last_sent_at) : '' }}</span>
                @endif
            </div>
        @endforeach
        @if ($url && ($none || ! $user->telegram_chat_id))
            <x-ui.share-link variant="row" :url="$url" title="Уведомления XCar" done="Инструкция в буфере" :text="$text" icon="bell" label="Инструкция для подключения"/>
        @endif
    </div>
</section>
