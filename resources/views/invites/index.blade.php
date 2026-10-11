{{-- Приглашения на сайте — менеджеру и админу один экран; в CRM тот же список на «Пользователи → Ссылки». Менеджеру — в
     «Сделках → Покупатели» (`/buyers/invites`, 11.10.2026: из «Покупателей» строка уводила в кабинет), админу — в кабинете. --}}
@if (request()->is('buyers/invites*'))
<x-ui.shell title="Приглашения" :back="['Покупатели', '/buyers']">
    <div class="flex flex-col gap-3"><x-invites.list :invites="$invites" :admin="$admin" :managers="$managers" :groups="$groups" :fresh="$fresh"/></div>
</x-ui.shell>
@else
<x-ui.cabinet title="Приглашения">
    <div class="flex flex-col gap-3"><x-invites.list :invites="$invites" :admin="$admin" :managers="$managers" :groups="$groups" :fresh="$fresh"/></div>
</x-ui.cabinet>
@endif
