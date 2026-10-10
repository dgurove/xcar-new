{{-- О компании: продавец и владелец сайта — реквизиты по карточке предприятия, связь и как забрать ТС. --}}
<x-ui.shell title="О компании" :back="false" :trail="[['Главная', '/'], ['О компании']]" narrow>
    @include('site.pages.legal.company')
</x-ui.shell>
