{{-- Значок раздела в таб-баре CRM (16.11.2026, владелец: «красивые иконки, заполненные, а не контурные»): «Предложения» — лента
     (прямоугольник с полоской сверху),
     «Почта» — скруглённое письмо, «Чаты» — два залитых круглых пузыря с зазором, как вкладка «Чаты» в WhatsApp (свой рисунок; лента и письмо — Fluent UI System Icons, Microsoft, MIT);
     «Оплаты» — монета из ZIGZAG (рулон сбоку), залитая: обод лицевой стороны и дуга — вырезом (маска). Цвет — currentColor. Размер оптически один: `viewBox` каждого значка подогнан по площади его рисунка (монета выше и шире — чуть мельче, пузыри — чуть крупнее (владелец: «чаты чуть побольше»); монета ещё на 8 % — на глаз казалась больше), рисунок по центру. --}}
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
    @case('coin')
<svg class="tab-icon" viewBox="-2.72 -2.59 29.17 29.17" aria-hidden="true"><defs><mask id="tab-coin-cut"><rect x="-5" y="-5" width="36" height="36" fill="#fff"/><g transform="translate(1.5 0)" fill="none" stroke="#000" stroke-width="1.5" stroke-linecap="round"><path d="M5.45 12C5.45 5.98 8.63 1.09 12.54 1.09s7.1 4.89 7.1 10.91-3.18 10.91-7.1 10.91S5.45 18.02 5.45 12Z"/><path d="M13.86 5c1.1 1.09 2.5 3.23 2.5 7s-1.09 5.45-2.18 6.55"/></g></mask></defs><g transform="translate(1.5 0)" mask="url(#tab-coin-cut)" fill="currentColor"><path d="M12.5 22.91H7.8c-.74 0-1.11 0-1.68-.16-.35-.1-1.12-.47-1.41-.69-.48-.35-.58-.47-.78-.72C2.2 19.14 1.09 15.77 1.09 12s1.11-7.14 2.84-9.34c.2-.25.3-.37.78-.72.29-.22 1.06-.59 1.41-.69.57-.16.94-.16 1.68-.16h4.7Z"/><path d="M5.45 12C5.45 5.98 8.63 1.09 12.54 1.09s7.1 4.89 7.1 10.91-3.18 10.91-7.1 10.91S5.45 18.02 5.45 12Z"/></g></svg>
        @break
@endswitch
