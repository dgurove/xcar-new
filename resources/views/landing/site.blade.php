{{-- xcar.ru для гостя: что это, как проходит сделка, как попасть. Вошедшего сюда не пускает
     LandingController — он сразу в /offers. Текст — только факты, без пересказа интерфейса. --}}
<x-ui.shell title="Оптовая площадка автомобилей" :heading="false" over-hero :back="false"
    description="Автомобили страховых, лизинговых компаний и банков для менеджеров по подбору. Вход по приглашению">
    <x-landing title="Оптовая площадка автомобилей" lead="Автомобили страховых, лизинговых компаний и банков для менеджеров по подбору"
        :primary="['Войти', '/login']" :secondary="['Стать менеджером', '/contacts']">
        <section>
            <h2 class="landing-h2">Каждое предложение целиком</h2>
            <p class="landing-lead mt-3">Фото, пробег, VIN, город и цена. Новое приходит уведомлением на телефон</p>
        </section>

        <section>
            <h2 class="landing-h2">Как проходит сделка</h2>
            <ol class="landing-steps mt-5">
                <li class="box"><span class="landing-step nums">1</span><h3>Предложение</h3><p>Смотрите фото, состояние и цену</p></li>
                <li class="box"><span class="landing-step nums">2</span><h3>Подтверждение</h3><p>Называете свою цену до конца приёма</p></li>
                <li class="box"><span class="landing-step nums">3</span><h3>Сделка</h3><p>Выбираем подтверждение, шаги сделки видны в приложении</p></li>
                <li class="box"><span class="landing-step nums">4</span><h3>Автомобиль</h3><p>Оплата по счёту, документы и выдача</p></li>
            </ol>
        </section>

        <section class="box landing-cta">
            <div class="min-w-0 flex-1">
                <h2 class="landing-h2">Вход по приглашению</h2>
                <p class="landing-lead mt-2">Напишите нам, если хотите работать с нами менеджером</p>
            </div>
            <a href="/contacts" class="btn btn-accent shrink-0">Написать</a>
        </section>
    </x-landing>
</x-ui.shell>
