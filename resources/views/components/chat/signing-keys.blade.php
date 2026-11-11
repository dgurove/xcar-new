{{-- Под сообщением площадки «Когда покупатель сможет подписать?» (`AskSigningTime`, `chat_messages.ask = signing`, 11.11.2026,
     владелец: «как „Берём?“ — кнопками в сообщении»): менеджеру, пока поставщик готовит договор и время не названо, — кнопка
     «Выбрать время» на шаг сделки (сетка «день × час», `x-deal.signing-slots`; владелец: «в сообщении просто кнопка»). Названо — строкой с галкой; сотруднику —
     то же без нажатия, а пока ждём — «Ждём ответа менеджера». --}}
@props(['chat', 'user' => null, 'readonly' => false])
@php
    use App\Offers\Signing;
    $deal = $chat->offer_id && $chat->user_id && ! $chat->manager_id
        ? \App\Offers\Deal::where('offer_id', $chat->offer_id)->where('buyer_id', $chat->user_id)->where('state', 'active')->latest('id')->first() : null;
    $manager = $user && $deal && $deal->buyer_id === $user->id && ! $readonly;
    $said = $deal ? ($deal->signing_at ? 'Записываем на '.Signing::short($deal->signing_at) : (($w = Signing::wishLine($deal)) ? 'Покупатель может '.$w : null)) : null;
    $asks = $deal && ! $said && Signing::asksTime($deal->offer?->position()?->stage?->loadMissing('exits.to.exits'));
@endphp
@if ($deal)
    <div class="msg-step">
        @if ($said)
            <p class="msg-step-done !items-start"><x-ui.icon name="check" class="mt-0.5 size-4 shrink-0"/>@if ($deal->signing_at)<span class="min-w-0">{{ $said }}</span>@else<x-deal.signing-wish-lines :deal="$deal"/>@endif</p>
        @elseif ($asks && $manager)
            <x-ui.button :href="$deal->href().'#step'" variant="primary" block>Выбрать время</x-ui.button>
        @elseif ($asks)
            <div class="msg-step-off"><span>Ждём ответа менеджера</span></div>
        @endif
    </div>
@endif
