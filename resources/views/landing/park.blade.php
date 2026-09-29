{{-- park.xcar.ru для гостя: парковка для страховых и лизинга — что делаем и как связаться. Вошедшего LandingController
     уводит в /requests. Факты — из процесса парковки (приём с актом, прайс вендора, выдача по QR). --}}
@php $c = config('xcar.company'); @endphp
<x-ui.shell title="Парковка для страховых и лизинговых компаний" :heading="false" over-hero :back="false" index
    description="Приём, хранение и выдача автомобилей страховых и лизинговых компаний: эвакуатор, акт приёма с фото, хранение по вашему прайсу, выдача покупателю по QR">
    <x-landing title="Парковка для страховых и лизинговых компаний" lead="Принимаем, храним и выдаём автомобили покупателям"
        :primary="['Связаться', '#contact']" :secondary="['Войти', '/login']">
        <section>
            <h2 class="landing-h2">Что делаем</h2>
            <ul class="landing-steps landing-steps--3 mt-5">
                <li class="box"><h3>Приём</h3><p>Забираем эвакуатором, осматриваем, акт приёма с фото приходит вам письмом</p></li>
                <li class="box"><h3>Хранение</h3><p>Сутки по вашему прайсу, счета раз в месяц</p></li>
                <li class="box"><h3>Выдача по QR</h3><p>Покупатель заполняет анкету и получает QR, выдаём после вашего подтверждения</p></li>
            </ul>
        </section>

        <section id="contact" class="box landing-cta scroll-mt-24">
            <div class="min-w-0 flex-1">
                <h2 class="landing-h2">Связаться</h2>
                <div class="mt-3 flex flex-col gap-1">
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $c['phone']) }}" class="nums text-lg text-accent-text">{{ $c['phone'] }}</a>
                    <a href="mailto:{{ $c['email'] }}" class="text-accent-text">{{ $c['email'] }}</a>
                </div>
            </div>
            <a href="{{ \App\Support\Surface::Site->url('/contacts') }}" class="btn btn-accent shrink-0">Написать</a>
        </section>
    </x-landing>
</x-ui.shell>
