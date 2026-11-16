{{-- Значок раздела в таб-баре CRM (16.11.2026, владелец: «красивые иконки, заполненные, а не контурные»): «Предложения» — лента
     (прямоугольник с полоской сверху),
     «Почта» — скруглённое письмо, «Чаты» — два залитых круглых пузыря с зазором, как вкладка «Чаты» в WhatsApp (свой рисунок; лента и письмо — Fluent UI System Icons, Microsoft, MIT);
     «Оплаты» — кошелёк (Fluent, выбран владельцем из 43 вариантов 16.11.2026; до того монета из ZIGZAG — «визуально больше»). Цвет — currentColor. Размер оптически один: `viewBox` каждого значка подогнан по площади его рисунка (пузыри — чуть крупнее, владелец: «чаты чуть побольше»), рисунок по центру. --}}
@props(['name'])
@switch($name)
    @case('feed')
<svg class="tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21 8.5v9.25c0 1.8-1.46 3.25-3.25 3.25H6.25A3.25 3.25 0 0 1 3 17.75V8.5h18ZM17.75 3C19.55 3 21 4.46 21 6.25V7H3v-.75C3 4.45 4.46 3 6.25 3h11.5Z"/></svg>
        @break
    @case('mail')
<svg class="tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 8.6v8.15a3.25 3.25 0 0 1-3.07 3.24l-.18.01H5.25a3.25 3.25 0 0 1-3.24-3.07L2 16.75V8.61l9.65 5.05c.22.12.48.12.7 0L22 8.61ZM5.25 4h13.5a3.25 3.25 0 0 1 3.23 2.92L12 12.15 2.02 6.92a3.25 3.25 0 0 1 3.04-2.91L5.25 4h13.5-13.5Z"/></svg>
        @break
    @case('chats')
<svg class="tab-icon" viewBox="0.4 0.6 23.2 23.2" aria-hidden="true"><defs><mask id="tab-chats-cut"><rect x="-5" y="-5" width="40" height="40" fill="#fff"/><circle cx="9.6" cy="13.6" r="9" fill="#000"/><path d="M4.2 18.2 2.2 22.4 7.6 21" fill="#000" stroke="#000" stroke-width="3.4" stroke-linejoin="round"/></mask></defs><g fill="currentColor" stroke="currentColor" stroke-linejoin="round"><g mask="url(#tab-chats-cut)"><circle cx="15.2" cy="8.8" r="6.8" stroke="none"/><path d="M19.6 12.6 21.6 16.6 17.2 15.2Z" stroke-width="1.4"/></g><circle cx="9.6" cy="13.6" r="7.4" stroke="none"/><path d="M4.4 18.4 2.8 21.8 7.6 20.6Z" stroke-width="1.4"/></g></svg>
        @break
    @case('wallet')
<svg class="tab-icon" viewBox="0.69 0.44 23.13 23.13" aria-hidden="true"><path fill="currentColor" d="M3 5v12.75C3 19.55 4.46 21 6.25 21h12c1.8 0 3.25-1.46 3.25-3.25v-8.5A3.25 3.25 0 0 0 19 6.09v-.84C19 4.01 18 3 16.75 3H5.25c-1.16 0-2.11.88-2.24 2zm2.25 1a.75.75 0 0 1 0-1.5h11.5c.41 0 .75.34.75.75V6zm11 7h2a.75.75 0 0 1 0 1.5h-2a.75.75 0 0 1 0-1.5"/></svg>
        @break
@endswitch
