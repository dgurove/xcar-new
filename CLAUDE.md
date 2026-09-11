# CLAUDE.md

Документация, коммиты и комментарии — **по-русски**, отвечать по-русски.

## Что это

**xcar-new** — xcar.ru заново: оптовая площадка битых автомобилей как
веб-приложение для телефона. Один Laravel на два хоста: `xcar.ru` (витрина,
кабинет `/lk`, админ `/admin` как раздел сайта) и `park.xcar.ru` (стоянка).
Без Filament и любого чужого админ-фреймворка — свой UI-кит на одних токенах.

Старый проект `../xcar.ru` — донор, не источник: оттуда берутся проверенные
решения (почта mail.ru, VIN, Carcade, приём фото, шеринг PDF, Mercure, модель
маршрута), переписываются в новую структуру. План — `~/.claude/plans/tender-jumping-quokka.md`.

## Стек и принципы

- Laravel 13, PHP 8.5, Postgres 18 (данные, очередь, сессии, кэш), FrankenPHP
  в режиме воркера (Octane), хаб Mercure внутри Caddy.
- Blade + Hotwire: Turbo (переходы, фреймы, стримы) и Stimulus (поведение).
  Контроллеры — `resources/js/controllers/<имя>_controller.js`, подключаются по
  имени файла. Никаких Alpine/Livewire/Vue.
- Каждое изменение состояния — Action + событие; контроллеры и экраны в
  модели не пишут. Событие → Mercure → Turbo Stream, никаких `poll`.
- Код по предметным областям: `app/Offers`, `app/Mail`, `app/Park`,
  `app/Purchases`, `app/Users`, `app/Cars`, `app/Media`, `app/Live`.
  HTTP по поверхностям: `app/Http/{Site,Cabinet,Admin,Park}`.
- Телефон — основное устройство: таб-бар, шторки `<dialog>`, камера в поле,
  16px в полях, safe-area. Системные `<input type=date>`, `<select>`.
- Тесты во время разработки не пишем — только когда без теста ошибка молчалива
  и дорога. Проверка — глазами, с телефона.
- Подписей-пояснений под элементами нет: непонятный блок переделывается.

## Команды

```bash
./serve-local.sh                 # http://localhost:8010 (php@8.5 и postgres из brew)
npm run build                    # ассеты; npm run dev — с горячей перезагрузкой
./deploy/server-setup.sh         # один раз на новый сервер
./deploy/deploy.sh               # выкладка: снимок git → сборка на сервере → миграции → up
```

Локальная база `xcar_new` (роль xcar/xcar, порт 5432). Порт 8010, потому
что 8000 занят старым проектом.

## Сервер

`ssh xcar-next` (root@200.169.180.17), см. `CONNECT.md`; пароли — в
`CONNECT.local.md` вне git. Раскладка `/srv/xcar/{env,releases,current}`.
Пока без домена — по IP. Прод не трогать без спроса, выкладка только
`deploy/deploy.sh`.
