{{-- Фото или файл менеджера в чате сделки, пока шаг просит документ (10.11.2026, владелец: чек кассы двумя фото в чат): у
     него — «Это чек об оплате» (файлы — в шаг, и ответ шага, если он один), у сотрудника в CRM на шаге оплаты — «Приложить
     за менеджера» (`Admin\PaymentProofController` с номером сообщения: скачивать и грузить заново не нужно). --}}
@props(['message', 'ask', 'user' => null, 'chat'])
@php
    use App\Chats\StepAsk;
    use App\Support\Surface;
    $word = StepAsk::documentWord($ask);
    $mine = $user && $ask->user_id === $user->id;
    $staff = ! $mine && $user?->canManageCrm() && Surface::current() === Surface::Crm && $ask->stage?->isPayStep();
@endphp
@if ($mine)
    <form method="post" action="/deals/{{ $ask->deal_id }}/chat-files" class="msg-step" data-turbo-confirm="Приложить к шагу как {{ $word }}?" data-turbo-confirm-label="Приложить">
        @csrf
        <input type="hidden" name="message" value="{{ $message->id }}">
        <input type="hidden" name="back" value="/deals/chats/{{ $chat->id }}">
        <button class="btn btn-s btn-quiet msg-step-chip"><x-ui.icon name="clip" class="size-4"/>Это {{ $word }}</button>
    </form>
@elseif ($staff)
    <form method="post" action="/work/deals/{{ $ask->deal_id }}/payment-proof" class="msg-step" data-turbo-confirm="Приложить как документ об оплате и пройти шаг за менеджера?" data-turbo-confirm-label="Приложить">
        @csrf
        <input type="hidden" name="message" value="{{ $message->id }}">
        <input type="hidden" name="pass" value="1">
        <button class="btn btn-s btn-quiet msg-step-chip"><x-ui.icon name="clip" class="size-4"/>Приложить за менеджера</button>
    </form>
@endif
