{{-- Под сообщением площадки «Когда покупатель сможет подписать?» (`AskSigningTime`, `chat_messages.ask = signing`, 11.11.2026,
     владелец: «как „Берём?“ — кнопками в сообщении»): менеджеру, пока поставщик готовит договор и время не названо, — день и
     время плитками (`x-deal.signing-when`, ответ — `SaveSigningWish`, назад в чат). Названо — строкой с галкой; сотруднику —
     то же без нажатия, а пока ждём — «Ждём ответа менеджера». --}}
@props(['chat', 'user' => null, 'readonly' => false])
@php
    use App\Offers\Signing;
    $deal = $chat->offer_id && $chat->user_id && ! $chat->manager_id
        ? \App\Offers\Deal::where('offer_id', $chat->offer_id)->where('buyer_id', $chat->user_id)->where('state', 'active')->latest('id')->first() : null;
    $manager = $user && $deal && $deal->buyer_id === $user->id && ! $readonly;
    $at = $deal ? ($deal->signing_at ?? Signing::wishAt($deal)) : null;
    $asks = $deal && ! $at && Signing::asksTime($deal->offer?->position()?->stage?->loadMissing('exits.to.exits'));
@endphp
@if ($deal)
    <div class="msg-step">
        @if ($at)
            <p class="msg-step-done"><x-ui.icon name="check" class="size-4 shrink-0"/><span class="min-w-0">{{ ($deal->signing_at ? 'Записываем на ' : 'Покупатель может ').Signing::short($at) }}</span></p>
        @elseif ($asks && $manager)
            <x-deal.signing-when :deal="$deal" :action="'/deals/'.$deal->id.'/signing-wish'" :back="'/deals/chats/'.$chat->id"/>
        @elseif ($asks)
            <div class="msg-step-off"><span>Ждём ответа менеджера</span></div>
        @endif
    </div>
@endif
