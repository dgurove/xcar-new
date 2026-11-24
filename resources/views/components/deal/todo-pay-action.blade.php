{{-- Наш ход в «Менеджер должен нам» (24.11.2026, вместе с «Оплатой за подбор»): «Выставить» неотправленный подбор, «Пришли» —
     менеджер сообщил об оплате. Из `x-deal.todo-row`: на ПК — на месте срока, на телефоне — на месте суммы. --}}
@props(['deal', 'todo'])
@if (! empty($todo['issue']))
    <x-deal.issue-button :deal="$deal" :invoice="$todo['issue']"/>
@elseif (! empty($todo['claim']))
    <form method="post" action="/work/payments/{{ $todo['claim']->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ \App\Support\Money::rub($todo['claim']->amount) }}?" data-turbo-confirm-text="Не поступила — откройте строку: там «Не поступила»">@csrf<button type="submit" class="btn btn-accent btn-xs">Пришли</button></form>
@endif
