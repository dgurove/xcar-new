{{-- Кнопки шага под сообщением площадки в чате сделки (10.11.2026, владелец: «сообщение с кнопками, как в Telegram»;
     `Chats\StepAsk`). Менеджеру, пока просьба открыта, — те же ответы, что на шаге (`x-deal.ask-action`); дело уже другое
     (сначала цена, шаг сменился) — «Открыть сделку». Сотруднику — те же кнопки без нажатия. Ответили — строкой с галкой:
     что ответили, за него ли, когда. Просьба удалена (вывоз взяла логистика) — ничего. --}}
@props(['message', 'chat', 'user' => null, 'readonly' => false])
@php
    use App\Chats\StepAsk;
    use App\Offers\Handover;
    use App\Offers\ManagerAsk;
    $r = $message->requirement;
    $deal = $r?->deal;
    $manager = $user && $r && $r->user_id === $user->id && ! $readonly;
    $ask = $r && $deal && $r->isOpen() && $manager ? ManagerAsk::for($deal) : null;
    $here = $ask && $ask->requirement?->id === $r->id;
    $labels = $r && $deal && $r->isOpen() && ! $manager && $r->stage ? Handover::managerExits($r->stage, $deal)->map(fn ($e) => $e->label) : collect();
@endphp
@if ($r && $deal)
    <div class="msg-step">
        @if ($r->isOpen())
            @if ($here)
                <x-deal.ask-action :ask="$ask" variant="keys" :back="'/deals/chats/'.$chat->id"/>
            @elseif ($manager)
                <x-ui.button :href="$deal->href().'#step'" variant="secondary" block>Открыть сделку</x-ui.button>
            @elseif ($labels->isNotEmpty())
                <div class="msg-step-off">@foreach ($labels as $label)<span>{{ $label }}</span>@endforeach</div>
            @endif
        @else
            <p class="msg-step-done"><x-ui.icon name="check" class="size-4 shrink-0"/><span class="min-w-0">{{ StepAsk::answered($r, ! $manager) }}</span>@if ($r->done_at)<span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, words: false) }}</span>@endif</p>
        @endif
    </div>
@endif
