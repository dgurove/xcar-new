{{-- Уведомления на это устройство — окно, как подключение Telegram (20.10.2026, владелец: «для Telegram красивое окошко с
     анимациями, а пуши засунули в настройки — туда никто не заходит»). Одно на страницу, в оболочке у вошедшего.
     Шаги одной шторки: предложение (пример уведомления выезжает сверху, как придёт) → «Разрешите» (браузер спрашивает) →
     пробное и «Видите его?» → готово; «Нет» — где разрешить в этой системе (`lib/push.js` fixSteps) и «Проверить ещё раз»;
     браузер запретил или спрятал запрос — как разрешить для сайта; iPhone не с экрана «Домой» — как добавить.
     Открывают: событие push:open (строка «Не пропускайте…», профиль) и само — на главном списке, если на устройстве
     пушей нет, не чаще раза в неделю и не больше трёх раз (push_offer_controller, память устройства). --}}
@php
    use App\Support\Surface;
    use App\Users\Impersonation;
    $user = auth()->user();
    $on = $user && config('xcar.vapid.public') && ! $user->is_demo && ! Impersonation::active();
    if ($on) {
        $surface = Surface::current();
        $carrier = $user->isLogist() || $user->isContractor() || $user->isDriver();
        // Где окно открывается само — главный список приложения (как шторка Telegram).
        $intro = match ($surface) {
            Surface::Logistics => ['/transports'],
            Surface::Park => ['/requests', '/'],
            Surface::Crm => ['/'],
            default => ['/offers', '/deals'],
        };
        [$head, $lead] = match (true) {
            $surface === Surface::Logistics && $carrier => ['Вывозы сразу на экран', 'Новое поручение придёт уведомлением, даже когда вкладка закрыта'],
            $surface === Surface::Park => ['Заявки сразу на экран', 'Новая заявка на приём или выдачу придёт уведомлением, даже когда вкладка закрыта'],
            $user->isBuyer() => ['Новые автомобили сразу на экран', 'Когда менеджер откроет вам автомобиль или ответит, придёт уведомление'],
            $user->hasDeals() => ['Узнавайте первым', 'Выбрали вас, нужен ваш ответ или пришли деньги: уведомление сразу на экран'],
            default => ['Уведомления на экран', 'Важное придёт сразу, даже когда XCar закрыт'],
        };
        // Пример — то же сообщение, что в окне Telegram (`Telegram\Preview`), строки — текстом.
        $preview = \App\Telegram\Preview::for($user);
        $body = collect($preview['lines'])->map(fn ($l) => trim(strip_tags((string) $l)))->filter()->implode(', ');
    }
@endphp
@if ($on)
    <div class="contents" id="push-offer" data-turbo-permanent data-controller="sheet push-offer" data-push-offer-intro-value='@json($intro)' data-push-offer-user-value="{{ $user->id }}">
        <x-ui.sheet id="push-offer-sheet" bare>
            {{-- Предложение: экран с уведомлением, выезжающим сверху. --}}
            <div class="tg-step" data-push-offer-target="step" data-step="offer">
                <div class="pu-scene" data-push-offer-target="scene" aria-hidden="true">
                    <div class="pu-note pu-note--back"></div>
                    <div class="pu-note">
                        <img src="/pwa/{{ $surface->value }}/icon-192.png" alt="" class="pu-icon">
                        <div class="min-w-0 flex-1">
                            <div class="pu-app"><span>{{ $surface->label() }}</span><span>сейчас</span></div>
                            <b class="pu-title">{{ $preview['title'] }}</b>
                            @if ($body)<span class="pu-body">{{ $body }}</span>@endif
                        </div>
                    </div>
                </div>
                <div class="tg-lead">
                    <h2 class="text-lg">{{ $head }}</h2>
                    <p>{{ $lead }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-accent w-full" data-action="push-offer#enable"><x-ui.icon name="bell" class="size-5"/>Включить уведомления</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="push-offer#later">Не сейчас</button>
                </div>
            </div>

            {{-- Браузер спрашивает разрешение: показать, куда нажать. --}}
            <div class="tg-step" data-push-offer-target="step" data-step="prompt" hidden>
                <div class="pu-ask"><x-ui.icon name="bell" class="size-9"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Нажмите «Разрешить»</h2>
                    <p>Браузер спрашивает в окне вверху экрана</p>
                </div>
            </div>

            {{-- Запрещено или запрос спрятан в адресную строку. --}}
            <div class="tg-step" data-push-offer-target="step" data-step="blocked" hidden>
                <div class="pu-ask pu-ask--off"><x-ui.icon name="bell" class="size-9"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg" data-push-offer-target="blockedTitle">Уведомления запрещены для сайта</h2>
                </div>
                <ol class="pu-steps" data-push-offer-target="siteSteps"></ol>
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-accent w-full" data-action="push-offer#enable">Попробовать снова</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="push-offer#later">Закрыть</button>
                </div>
            </div>

            {{-- iPhone в Safari: пуши только у приложения с экрана «Домой». --}}
            <div class="tg-step" data-push-offer-target="step" data-step="ios" hidden>
                <div class="pu-ask"><x-ui.icon name="share" class="size-9"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Сначала на экран «Домой»</h2>
                    <p>На iPhone уведомления приходят только приложению</p>
                </div>
                <ol class="pu-steps">
                    <li>Внизу Safari нажмите «Поделиться»</li>
                    <li>«На экран „Домой“» → «Добавить»</li>
                    <li>Откройте XCar с экрана и включите уведомления</li>
                </ol>
                <button type="button" class="btn btn-accent w-full" data-action="push-offer#later">Понятно</button>
            </div>

            {{-- Пробное ушло: видно ли его? --}}
            <div class="tg-step" data-push-offer-target="step" data-step="test" hidden>
                <div class="pu-ask pu-ask--ring"><x-ui.icon name="bell" class="size-9"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Отправили пробное уведомление</h2>
                    <p>Оно появилось на экране?</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn btn-quiet" data-action="push-offer#no">Нет</button>
                    <button type="button" class="btn btn-accent" data-action="push-offer#yes">Да, пришло</button>
                </div>
            </div>

            {{-- Не пришло: браузер получил, система прячет. --}}
            <div class="tg-step" data-push-offer-target="step" data-step="fix" hidden>
                <div class="tg-lead">
                    <h2 class="text-lg">Система прячет уведомления</h2>
                    <p data-push-offer-target="browser"></p>
                </div>
                <ol class="pu-steps" data-push-offer-target="fixSteps"></ol>
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-accent w-full" data-action="push-offer#again">Проверить ещё раз</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="push-offer#later">Закрыть</button>
                </div>
            </div>

            <div class="tg-step" data-push-offer-target="step" data-step="done" hidden>
                <div class="tg-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Готово, {{ $user->firstName() }}</h2>
                    <p>Новое будет приходить уведомлением на это устройство</p>
                </div>
                <button type="button" class="btn btn-accent w-full" data-action="push-offer#later">Отлично</button>
            </div>
        </x-ui.sheet>
    </div>
@endif
