# Три приложения: XCar, XCar CRM, XCar Стоянка (12.09.2026)

Одно приложение разложено на три хоста с одним оформлением.

- `App\Support\Surface` (Site / Crm / Park) — по хосту ставит `ResolveSurface`;
  чужим на CRM и стоянке — 404 (кроме входа и служебного). Оболочка, навигация,
  манифест, иконки и заголовок берут поверхность из `Surface::current()`.
- CRM `crm.xcar.ru` — `routes/crm.php`: `/` предложения, `/predlozheniya/{n}`,
  `/perepiski/{pochta,chaty}`, `/sdelki`, `/zakupki`, `/nastroyki` (хаб) и
  `/nastroyki/{kandidaty,strahovye,marshruty,yashchiki,shablony}`, `/lk`.
  `xcar.ru/admin/*` → 301 по карте `LegacyAdmin` (хвост и query сохраняются).
- Сотрудник на сайте — как менеджер: без «Редактировать», в верхнем ряду и в
  кабинете ссылка «CRM». Кабинет `/lk` на всех хостах, пилюли по поверхности;
  стоянка `/kabinet` → `/lk`.
- Обращение с `/kontakty` — чат без предложения: у гостя по cookie
  `xcar_enquiry` (в базе hash токена), при входе из того же браузера чат
  переезжает в аккаунт (`AttachGuestEnquiry` на событие Login). У пользователя
  — раздел «Чаты» в кабинете; в CRM — пилюля «Обращения».
- «Оффер» в текстах — «предложение».
- Прод до домена: имена nip.io и `SESSION_DOMAIN=.200.169.180.17.nip.io` —
  один вход на три имени; голый IP отвечает как сайт с отдельной сессией.
  Passkey по http на nip.io не работают (нужен secure context).
