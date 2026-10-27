{{-- xcar.ru для гостя: закрытая система — только «Войти», без рекламных надписей (27.10.2026, владелец). Вошедшего сюда
     не пускает LandingController — он сразу в /offers. --}}
<x-ui.shell title="XCar" :heading="false" over-hero :back="false" description="Вход по приглашению">
    <x-landing :primary="['Войти', '/login']"/>
</x-ui.shell>
