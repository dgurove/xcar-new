<?php

use App\Http\Admin\AlarmController;
use App\Http\Admin\BankController;
use App\Http\Admin\BidController;
use App\Http\Admin\ResponsibleController;
use App\Http\Admin\ChatController;
use App\Http\Admin\DealContractController;
use App\Http\Admin\DealController;
use App\Http\Admin\DeliveryController;
use App\Http\Admin\DisclosureController;
use App\Http\Admin\GalleryController;
use App\Http\Admin\GarageController;
use App\Http\Admin\GiveController;
use App\Http\Admin\HomeController;
use App\Http\Admin\InterestController;
use App\Http\Admin\InvoiceController;
use App\Http\Admin\LegacyClaimController;
use App\Http\Admin\MailAccountController;
use App\Http\Admin\MailController;
use App\Http\Admin\MailTemplateController;
use App\Http\Admin\MoneyController;
use App\Http\Admin\OfferController;
use App\Http\Admin\SavedFilterController;
use App\Http\Admin\OfferDraftController;
use App\Http\Admin\OfferEstimateController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\OfferValuationController;
use App\Http\Admin\ParallelController;
use App\Http\Admin\ParsingController;
use App\Http\Admin\PayLinkController;
use App\Http\Admin\PaymentProofController;
use App\Http\Admin\PurchaseCarPhotoController;
use App\Http\Admin\PurchaseController;
use App\Http\Admin\ReferenceController;
use App\Http\Admin\RepriceController;
use App\Http\Admin\ExtraAskController;
use App\Http\Admin\RouteController;
use App\Http\Admin\SigningController;
use App\Http\Admin\TagController;
use App\Http\Admin\TelegramChatController;
use App\Http\Admin\TimerController;
use App\Http\Admin\UserController;
use App\Http\Admin\UserGroupController;
use App\Http\Admin\VendorContactController;
use App\Http\Admin\VendorController;
use App\Http\Admin\WatermarkController;
use App\Http\Admin\WorkflowController;
use App\Http\Cabinet\InviteController;
use App\Http\Cabinet\PassportScanController;
use App\Http\Cabinet\ProfileController;
use App\Http\Cabinet\RegionController;
use App\Http\Cars\CarFieldController;
use App\Http\Garage\CarController as GarageCarController;
use App\Http\Garage\SettlementController as GarageSettlementController;
use App\Http\Mail\AttachController;
use App\Http\PeopleController;
use App\Http\PeopleMailController;
use App\Http\SearchController;
use App\Http\Site\ShareController;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Support\Surface;
use Illuminate\Support\Facades\Route;

// Корень — редирект на предложения (`HomeController`; старые ссылки на список `/?…` — на `/offers?…`).
Route::domain(config('xcar.crm_host'))->get('/', HomeController::class)->name('crm.home');

// CRM — свой хост того же приложения, только для сотрудников.
Route::domain(config('xcar.crm_host'))->middleware(['auth', 'ability:isStaff'])->group(function () {
    // Модератору — только этот список: черновики предложений, справочник, профиль, почта по галке. Чужое предложение
    // (опубликованное, из закупки) — 404 через `offer.edit`. Всё остальное ниже — `ability:canManageCrm`: новый раздел, не
    // внесённый сюда, модератору закрыт сам.
    Route::get('/offers', [OfferController::class, 'index'])->name('crm.offers');
    // Поиск в шапке — модератору тоже: свои черновики (`Search\Search::for` сам решает, кому какие группы).
    Route::get('/search', SearchController::class);
    // «Горит» — срок обязывающего (19.10.2026): ведущим логистику, админам и модераторам.
    Route::get('/alarms', [AlarmController::class, 'index'])->middleware('ability:leadsLogistics');
    Route::post('/offers', [OfferController::class, 'store']);
    // Почта и «Из писем» — общим куском с XCAR Mail (`routes/mail-shared.php`); до `/offers/{offer}`, иначе from-mail примут за номер.
    require __DIR__.'/mail-shared.php';
    Route::get('/offers/twins', [OfferController::class, 'twins']);
    // Окно «Выгрузка» над списком: вкладка, чипы и лупа — файлом или данными для буфера (`format=json`).
    Route::get('/offers/export', [OfferController::class, 'export']);
    // Сохранённые фильтры шторки «Фильтр» (17.11.2026) — свои у каждого, модератору тоже; чужой — 404.
    Route::post('/offers/filters', [SavedFilterController::class, 'store']);
    Route::patch('/offers/filters/{filter}', [SavedFilterController::class, 'update'])->whereNumber('filter');
    Route::delete('/offers/filters/{filter}', [SavedFilterController::class, 'destroy'])->whereNumber('filter');
    // «Только мои» в колесе вкладок (18.11.2026): помнится у человека, любому сотруднику CRM.
    Route::post('/offers/wheel-mine', [OfferController::class, 'wheelMine']);
    // «Оценить → Из текста»: оценочные из сообщения — модератору тоже, он ставит закупочную.
    Route::post('/offers/valuations/file', [OfferValuationController::class, 'file'])->middleware('throttle:10,1');
    Route::post('/offers/valuations/preview', [OfferValuationController::class, 'preview']);
    Route::post('/offers/valuations', [OfferValuationController::class, 'apply']);
    // История окна (17.11.2026): последние сохранения окна и бота.
    Route::get('/offers/valuations/history', [OfferValuationController::class, 'history']);
    // «Из таблицы Кати» (10.10.2026): номера списком, «Завести» и «Убрать» — только админу.
    Route::middleware('ability:canManageCrm')->group(function () {
        Route::post('/offers/legacy', [LegacyClaimController::class, 'store']);
        Route::post('/offers/legacy/{claim}/create', [LegacyClaimController::class, 'create']);
        Route::delete('/offers/legacy/{claim}', [LegacyClaimController::class, 'destroy']);
        Route::post('/offers/{offer}/legacy-drop', [LegacyClaimController::class, 'drop']);
    });
    Route::middleware('offer.edit')->group(function () {
        Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('crm.offers.edit');
        Route::get('/offers/{offer}/letters', [MailController::class, 'offerLetters'])->middleware('ability:canCrmMail');
        // Шторки действий окна машины — по первому требованию (`data-lazy-sheets`, `emit_controller`).
        Route::get('/offers/{offer}/window-sheets', [OfferController::class, 'windowSheets']);
        // Кадры письма прикрепляются: строка хода и ряд фото, пока ImportThreadFiles идёт (attach_controller).
        Route::get('/offers/{offer}/attach', [AttachController::class, 'offer']);
        Route::put('/offers/{offer}', [OfferController::class, 'update']);
        // «Закупочная» сверху карточки во вкладке «Без закупочной цены» — дело модератора.
        Route::post('/offers/{offer}/floor', [OfferController::class, 'floor']);
        // «ПТС и СТС» — документы ТС отдельной карточкой (19.10.2026).
        // Черновик из писем до первого сохранения: «Отменить» и «Не заявка» (decline) — черновика не было, письма снова ждут.
        Route::post('/offers/{offer}/drop', [MailController::class, 'dropDraft'])->middleware('ability:canCrmMail');
        // Ушли из пустого черновика «+ Новый» — его нет (sendBeacon при уходе; не пустой сервер не трогает).
        Route::post('/offers/{offer}/drop-empty', [OfferController::class, 'dropEmpty']);
        // Черновик оказался двойником (тот же номер убытка или VIN): «Это она» — всё его к найденному, черновика нет.
        Route::post('/offers/{offer}/into/{target}', [OfferController::class, 'mergeInto']);
        Route::delete('/offers/{offer}', [OfferDraftController::class, 'destroy']);
        // Черновик из парковки завели в продажу зря — отвязать ТС и удалить («Снять с продажи»).
        Route::post('/offers/{offer}/unlist', [OfferController::class, 'unlist']);
        Route::post('/offers/{offer}/media', [OfferPhotoController::class, 'store']);
        Route::post('/offers/{offer}/media/migtorg', [OfferPhotoController::class, 'migtorg']);
        Route::get('/offers/{offer}/migtorg', [OfferPhotoController::class, 'migtorgChip']);
        // Спорное поле машины — выбрать вариант источника или оставить (`Cars\Sources`); фото — «Это документ».
        Route::post('/offers/{offer}/fields/{field}', [CarFieldController::class, 'offer']);
        Route::post('/offers/{offer}/as-document', [CarFieldController::class, 'offerDocument']);
        Route::post('/offers/{offer}/media/order', [OfferPhotoController::class, 'reorder']);
        Route::post('/offers/{offer}/media/visibility', [OfferPhotoController::class, 'visibility']);
        Route::post('/offers/{offer}/media/{media}/hide', [OfferPhotoController::class, 'toggle']);
        Route::post('/offers/{offer}/media/{media}/rotate', [OfferPhotoController::class, 'rotate']);
        // Чужой водяной знак кадра: шторка из просмотрщика, кадр со знаком для сравнения, «Вернуть» и свой файл.
        Route::get('/offers/{offer}/media/{media}/mark', [OfferPhotoController::class, 'mark']);
        Route::get('/offers/{offer}/media/{media}/marked', [OfferPhotoController::class, 'marked']);
        Route::post('/offers/{offer}/media/{media}/mark', [OfferPhotoController::class, 'unmark']);
        Route::post('/offers/{offer}/media/{media}/learn', [OfferPhotoController::class, 'learn']);
        Route::delete('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);
        Route::patch('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'rename']);
    });
    // Кабинет CRM = «Настройки»: корень — профиль (общий экран с сайтом), /account сюда же.
    Route::get('/settings', [ProfileController::class, 'profile']);
    Route::permanentRedirect('/account', '/settings');
    // Форма профиля (имя, фото) шлёт PUT /account. Редирект выше ловит любой метод, а маршрут того же адреса перекрывает
    // объявленный раньше — поэтому PUT строго после него; без него фото и имя в CRM молча терялись.
    Route::put('/account', [ProfileController::class, 'update']);
    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::post('/reference/car-text', [ReferenceController::class, 'carText']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::get('/reference/settlements', [ReferenceController::class, 'settlements']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/settlements', [ReferenceController::class, 'createSettlement']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
    Route::get('/reference/vendors', [ReferenceController::class, 'vendors']);
    Route::post('/reference/vendors', [ReferenceController::class, 'createVendor'])->middleware('ability:canManageCrm');

    // Сделки для документов (14.11.2026, владелец: «админам и модераторам вручную менять сумму договора и счёта»): модератору —
    // список «Сделок» с окном документов, сумма договора, данные ДКП и паспорт покупателя; прочее по сделке — только админу.
    Route::middleware('ability:canEditDeals')->group(function () {
        Route::get('/work/deals', [DealController::class, 'index']);
        // «+ Новый покупатель» сделки (и модератору, 20.11.2026) и «+ Новый логист / сотрудник» вывоза (только админ —
        // `PeopleController::allows`), данные входа и ссылка нового — письмом.
        Route::post('/people', [PeopleController::class, 'store']);
        Route::post('/people/credentials-mail/{token}', [PeopleMailController::class, 'credentials']);
        Route::post('/people/{user}/password-link-mail', [PeopleMailController::class, 'passwordLink']);
        Route::get('/work/deals/{deal}', [DealController::class, 'show']);
        Route::put('/work/deals/{deal}/contract', [DealContractController::class, 'update']);
        Route::put('/work/deals/{deal}/invoice-data', [DealContractController::class, 'invoiceData']);
        Route::put('/work/deals/{deal}/payer', [DealContractController::class, 'payer']);
        // Схема сделки («Кому платит покупатель», через Р.О.С.) — и модератору (17.11.2026, владелец).
        Route::post('/work/deals/{deal}/scheme', [DealController::class, 'scheme']);
        Route::put('/work/deals/{deal}/fee-seller', [DealContractController::class, 'feeSeller']);
        // «Отправить менеджеру» у счёта и договора (16.11.2026): сотрудник проверил — менеджер видит.
        Route::post('/work/deals/{deal}/invoices/{invoice}/show', [DealContractController::class, 'showInvoice']);
        Route::post('/work/deals/{deal}/invoices/{invoice}/issue', [DealContractController::class, 'issueInvoice']);
        // «Запросили счёт…» у комиссии (18.11.2026): обычно только ссылка или наличные.
        Route::post('/work/deals/{deal}/invoices/{invoice}/paper', [DealContractController::class, 'paperInvoice']);
        Route::post('/work/deals/{deal}/contract/show', [DealContractController::class, 'showContract']);
        // «Отозвать» отправленный счёт и договор (19.11.2026, владелец: «поправить и заново отправить», `ShowToManager::recall*`).
        Route::post('/work/deals/{deal}/invoices/{invoice}/recall', [DealContractController::class, 'recallInvoice']);
        Route::post('/work/deals/{deal}/contract/recall', [DealContractController::class, 'recallContract']);
        // «Напомнить менеджеру» про отправленный счёт (19.11.2026, `NudgeInvoice`).
        Route::post('/work/deals/{deal}/invoices/{invoice}/nudge', [DealContractController::class, 'nudgeInvoice']);
        // Исправленный договор (19.11.2026, вариант Б): приложить исправленный, вернуть сформированный (Word для правки — `dkp.docx?edit=1`).
        Route::post('/work/deals/{deal}/contract/edited', [DealContractController::class, 'edited']);
        Route::delete('/work/deals/{deal}/contract/edited', [DealContractController::class, 'unedit']);
        Route::post('/work/deals/{deal}/supplier-contract', [DealContractController::class, 'supplierContract']);
        Route::post('/parties/{party}/passport/{page}', [PassportScanController::class, 'upload'])->whereIn('page', ['main', 'reg'])->middleware('throttle:30,1');
        Route::get('/parties/{party}/passport/live', [PassportScanController::class, 'live']);
        Route::post('/parties/{party}/passport/read', [PassportScanController::class, 'read'])->middleware('throttle:10,1');
        Route::post('/parties/{party}/passport/{page}/rotate', [PassportScanController::class, 'rotate'])->whereIn('page', ['main', 'reg'])->middleware('throttle:30,1');
        Route::get('/parties/{party}/passport.pdf', [PassportScanController::class, 'pdf']);
        Route::get('/work/deals/{deal}/dkp', [DealContractController::class, 'show']);
        Route::get('/work/deals/{deal}/dkp.pdf', [DealContractController::class, 'show']);
        Route::get('/work/deals/{deal}/dkp.docx', [DealContractController::class, 'show']);
        Route::post('/work/deals/{deal}/signed', [DealController::class, 'signed']);
        Route::delete('/work/deals/{deal}/signed/{media}', [DealController::class, 'removeSigned']);
        Route::get('/work/deals/{deal}/signed-contract.pdf', [DealController::class, 'signedPdf']);
        // Работа со сделкой модератору (17.11.2026, владелец с начальником: «модераторам полный доступ ко всем сделкам — данные
        // покупателей, отправка счёта и договора менеджеру и так далее»; чат с менеджером и гараж): шаги пути без выходов в тупик
        // (`RouteController::exit`), разбор писем, подписание, документ об оплате за менеджера, чаты, гараж. Деньги («Поступила»,
        // ссылки, «Оплаты») и решения (отмена, отказ, «Отдать», подтверждения, переоценка) — у админа ниже.
        // Счёт сделки — смотреть (окно счёта, PDF, печать, акт); оплаты и аннулирование — у админа.
        Route::get('/work/money/invoices/{invoice}', [MoneyController::class, 'show']);
        Route::get('/work/money/invoices/{invoice}/pdf', [MoneyController::class, 'file']);
        Route::get('/work/money/invoices/{invoice}/print', [MoneyController::class, 'print']);
        Route::get('/work/money/invoices/{invoice}/act', [MoneyController::class, 'act']);
        Route::get('/garage/cars/{offer}/invoice/pdf', [GarageSettlementController::class, 'pdf']);
        Route::delete('/garage/cars/{offer}/links/{link}', [GarageSettlementController::class, 'cancelLink']);
        Route::post('/garage/cars/{offer}/costs', [GarageCarController::class, 'storeCost']);
        Route::delete('/garage/cars/{offer}', [GarageCarController::class, 'destroy']);
        Route::post('/garage/cars/{offer}/waiting', [GarageCarController::class, 'notArrived']);
        Route::put('/garage/costs/{cost}', [GarageCarController::class, 'updateCost']);
        Route::delete('/garage/costs/{cost}', [GarageCarController::class, 'destroyCost']);
        Route::post('/garage/cars/{offer}/stage', [GarageSettlementController::class, 'stage']);
        Route::post('/garage/cars/{offer}/sold', [GarageSettlementController::class, 'sold']);
        Route::post('/garage/cars/{offer}/price-check', [GarageSettlementController::class, 'priceCheck'])->middleware('throttle:60,1');
        Route::delete('/garage/cars/{offer}/sold', [GarageSettlementController::class, 'unsold']);
        Route::put('/garage/cars/{offer}/buyer-price', [GarageSettlementController::class, 'buyerPrice']);
        Route::post('/garage/cars/{offer}/settle', [GarageSettlementController::class, 'settle']);
        Route::post('/garage/cars/{offer}/payments', [GarageSettlementController::class, 'pay']);
        Route::delete('/garage/cars/{offer}/invoice', [GarageSettlementController::class, 'voidInvoice']);
        Route::post('/offers/{offer}/exit/{exit}', [RouteController::class, 'exit']);
        Route::post('/offers/{offer}/stage', [RouteController::class, 'place']);
        Route::post('/offers/{offer}/back', [RouteController::class, 'back']);
        Route::post('/offers/{offer}/deadline', [RouteController::class, 'deadline']);
        Route::get('/offers/{offer}/letter-ask', [RouteController::class, 'letterAsk']);
        Route::post('/offers/{offer}/letter-skip', [RouteController::class, 'letterSkip']);
        // «Запросить реквизиты» в шаге «Письмо с реквизитами» (18.11.2026): менеджеру снова в чат сделки.
        Route::post('/offers/{offer}/ask-buyer', [RouteController::class, 'askBuyer']);
        // Доп. просьба (18.11.2026, `Offers\ExtraAsk`): «Вопрос не решён», «Ответили не письмом», решение за менеджера.
        Route::post('/offers/{offer}/extra-asks/{ask}/reopen', [ExtraAskController::class, 'reopen']);
        Route::post('/offers/{offer}/extra-asks/{ask}/phone', [ExtraAskController::class, 'phone']);
        Route::post('/offers/{offer}/extra-asks/decide', [ExtraAskController::class, 'decide']);
        Route::post('/offers/{offer}/pickup', [RouteController::class, 'pickup']);
        Route::post('/offers/{offer}/media/{media}/managers', [OfferPhotoController::class, 'managers']);
        Route::delete('/offers/{offer}/pickup', [RouteController::class, 'dropPickup']);
        Route::post('/offers/{offer}/pickup/back', [RouteController::class, 'pickupBack']);
        Route::post('/offers/{offer}/pickup/backdate', [RouteController::class, 'pickupBackdate']);
        Route::post('/work/deals/supplier-answer', [DealController::class, 'supplierAnswer']);
        Route::put('/work/deals/{deal}/receiver', [DealController::class, 'receiver']);
        Route::post('/work/deals/{deal}/contract-signed', [DealController::class, 'contractSigned']);
        Route::post('/work/deals/{deal}/dates', [DealController::class, 'dates']);
        Route::post('/work/deals/{deal}/disclose', [DisclosureController::class, 'store']);
        Route::post('/work/deals/{deal}/supplier-invoice', [DisclosureController::class, 'invoice']);
        Route::post('/work/deals/{deal}/signing-slots', [SigningController::class, 'slots']);
        Route::post('/work/deals/{deal}/signing', [SigningController::class, 'pick']);
        Route::post('/work/deals/{deal}/signing-wish', [SigningController::class, 'wish']);
        Route::post('/work/deals/{deal}/signing-confirm', [SigningController::class, 'confirm']);
        Route::get('/work/deals/{deal}/signing.ics', [SigningController::class, 'ics']);
        Route::post('/work/deals/{deal}/payment-proof', [PaymentProofController::class, 'store']);
        Route::delete('/work/deals/{deal}/payment-proof/{media}', [PaymentProofController::class, 'remove']);
        Route::get('/work/deals/{deal}/payment-proof.pdf', [PaymentProofController::class, 'pdf']);
        Route::post('/work/deals/{deal}/payment-proof/to-invoice', [PaymentProofController::class, 'toInvoice']);
        Route::post('/work/deals/{deal}/reviews/done', [DisclosureController::class, 'dismiss']);
        Route::get('/work/garage', [GarageController::class, 'index']);
        Route::get('/work/deals/{deal}/chat', [DealController::class, 'chat']);
        Route::post('/work/deals/{deal}/note', [DealController::class, 'note']);
        Route::get('/work/chats', [ChatController::class, 'index']);
        Route::get('/work/chats/{chat}', [ChatController::class, 'show']);
        Route::post('/work/chats/offer/{offer}/{user}', [ChatController::class, 'start']);
        Route::delete('/work/chats/{chat}', [ChatController::class, 'destroy']);
        // Сайтовый адрес чатов (`Chat::path`, уведомления и тосты без хоста; старый `/account/chats` сюда же приводит
        // `Paths::MOVED`) — на CRM это «Чаты» в работе. Только корень и экран чата: `/chats/{id}/messages|files|typing`
        // из web.php должны дойти до ленты.
        Route::get('/chats', fn () => redirect('/work/chats'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
        Route::get('/chats/{chat}', fn (int $chat) => redirect("/work/chats/{$chat}", 301))->whereNumber('chat');
        // Счета и деньги сделки — модератору тоже (18.11.2026, владелец: «у модераторов должен быть полный доступ ко всему, что
        // касается договоров и счетов», «пусть цену продажи и сумму вознаграждения тоже могут менять»): цена и вознаграждение,
        // оплаты и аннулирование счёта, заявки менеджера об оплате, ссылки у счёта, «Ещё счёт». Счёт без сделки модератору
        // закрыт (`MoneyController::guardInvoice`, `PayLinkController`); раздел «Оплаты» и возврат эквайринга — админу.
        Route::put('/work/deals/{deal}/money', [DealController::class, 'money']);
        Route::post('/work/payments/{payment}/confirm', [MoneyController::class, 'confirm']);
        Route::post('/work/payments/{payment}/reject', [MoneyController::class, 'reject']);
        Route::post('/work/payments/{payment}/to-supplier', [MoneyController::class, 'toSupplier']);
        Route::post('/work/money/invoices/{invoice}/links', [PayLinkController::class, 'store']);
        Route::delete('/work/money/links/{link}', [PayLinkController::class, 'destroy']);
        Route::put('/work/money/links/{link}/email', [PayLinkController::class, 'email']);
        Route::prefix('work/money/invoices/{invoice}')->group(function () {
            Route::put('/', [MoneyController::class, 'update']);
            Route::post('/payments', [MoneyController::class, 'pay']);
            Route::get('/payments/{payment}/slip', [MoneyController::class, 'slip']);
            Route::delete('/payments/{payment}', [MoneyController::class, 'unpay']);
            Route::post('/void', [MoneyController::class, 'void']);
        });
        Route::get('/work/invoices/new', [InvoiceController::class, 'create']);
        Route::post('/work/invoices', [InvoiceController::class, 'store']);
    });

    Route::middleware('ability:canManageCrm')->group(function () {
        // Шеринг PDF: маршруты без домена на хосте CRM отбивает ResolveSurface — свои копии.
        Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
        Route::get('/offers/{offer}/photo/{media}', [ShareController::class, 'photo'])->whereNumber('media');
        Route::post('/offers/{offer}/photos/warm', [ShareController::class, 'warm'])->middleware('throttle:240,1');
        Route::post('/offers/{offer}/zip', [ShareController::class, 'zip']);
        // Карточка «Гаража» зовёт счёт машины и отмену ссылки оплаты путём без хоста — на CRM тоже свои копии.
        // Машину в гараже сотрудник ведёт в CRM (карточка «Гараж» редактора и «Работа → Гараж») — теми же адресами, что на сайте.
        Route::post('/share/error', [ShareController::class, 'report'])->middleware('throttle:30,1');
        Route::post('/offers/{offer}/extend', [OfferController::class, 'extend']);
        Route::post('/offers/{offer}/lower-price', [OfferController::class, 'lowerPrice']);
        Route::post('/offers/{offer}/no-bids', [OfferController::class, 'notifyNoBids'])->middleware('throttle:6,1');
        Route::post('/offers/{offer}/state', [OfferController::class, 'state']);
        // «Открыть всем сейчас…» — снять эксклюзив за оценку у машины в продаже (09.11.2026, `Exclusive`).
        Route::post('/offers/{offer}/exclusive-off', [OfferController::class, 'exclusiveOff']);
        // «На аукцион» (13.10.2026): отправить сейчас или по истечении срока; DELETE — отменить или убрать с аукциона.
        Route::post('/offers/{offer}/auction', [OfferController::class, 'auction']);
        Route::delete('/offers/{offer}/auction', [OfferController::class, 'auctionCancel']);
        Route::post('/offers/{offer}/auction/sold', [OfferController::class, 'auctionSold']);
        Route::delete('/offers/{offer}/auction/sold', [OfferController::class, 'auctionUnsold']);
        // «Предложено из заявок на приём» (13.10.2026): принять черновик или отклонить — ТС остаётся на парковке.
        Route::post('/offers/{offer}/proposal', [OfferController::class, 'acceptProposal']);
        Route::delete('/offers/{offer}/proposal', [OfferController::class, 'declineProposal']);
        Route::post('/offers/{offer}/rate', [OfferController::class, 'rate']);
        // «Марки нет» (12.11.2026): прицеп или кузов без шильдика — публикация марку не ждёт.
        Route::post('/offers/{offer}/no-brand', [OfferController::class, 'noBrand']);
        // «Оценочная неактуальна» (15.11.2026): ждём новую от поставщика — черновик в «Без закупочной».
        Route::post('/offers/{offer}/value-stale', [OfferController::class, 'dropValue']);
        // Оценка менеджером (11.10.2026): «Отдать менеджеру на оценку», «Взять», «Отклонить», «×».
        Route::post('/offers/{offer}/estimates', [OfferEstimateController::class, 'store']);
        Route::post('/offers/{offer}/estimates/keep', [OfferEstimateController::class, 'keep']);
        Route::post('/offers/{offer}/estimates/{estimate}/take', [OfferEstimateController::class, 'take']);
        Route::post('/offers/{offer}/estimates/{estimate}/reject', [OfferEstimateController::class, 'reject']);
        Route::post('/offers/{offer}/estimates/{estimate}/again', [OfferEstimateController::class, 'again']);
        Route::delete('/offers/{offer}/estimates/{estimate}', [OfferEstimateController::class, 'destroy']);
        Route::post('/offers/{offer}/resale', [OfferController::class, 'resale']);
        // «Менеджер отказался» (19.11.2026): вернуть в продажу на часы с авто-снятием и «Не снимать автоматически».
        Route::post('/offers/{offer}/refused-resale', [OfferController::class, 'refusedResale']);
        Route::post('/offers/{offer}/keep-on-sale', [OfferController::class, 'keepOnSale']);
        // «Точно?» перед ценой под вопросом (02.11.2026, `PriceGuard`): форма цены и публикация спрашивают до отправки.
        Route::post('/offers/price-check', [OfferController::class, 'priceCheck'])->middleware('throttle:60,1');
        // Пачкой из «Оцененных» и «Публикации»: галочки строк, `offers[]` — номера.
        Route::post('/offers/schedule', [OfferController::class, 'scheduleMany']);
        Route::post('/offers/unschedule', [OfferController::class, 'unscheduleMany']);
        // «Без подтверждений» пачкой (06.11.2026): вернуть в продажу с одним сроком или в архив.
        Route::post('/offers/relist', [OfferController::class, 'relistMany']);
        Route::post('/offers/archive', [OfferController::class, 'archiveMany']);
        // Архив — навсегда, со всем связанным: пачкой из вкладки и по одному из карточки и редактора (PurgeOffer).
        Route::post('/offers/purge', [OfferController::class, 'purgeMany']);
        Route::post('/offers/{offer}/purge', [OfferController::class, 'purge']);
        Route::post('/offers/{offer}/unschedule', [OfferController::class, 'unschedule']);
        Route::post('/offers/{offer}/give', [GiveController::class, 'store']);
        // «Передать…» гаражную другому держателю без отмены сделки (19.11.2026, `HandOverGarage`).
        Route::post('/offers/{offer}/garage-holder', [GiveController::class, 'holder']);
        // Переоценка закупочной на решение (28.10.2026, `x-offer.reprice`).
        Route::post('/offers/{offer}/reprices/{reprice}', [RepriceController::class, 'apply']);
        Route::delete('/offers/{offer}/reprices/{reprice}', [RepriceController::class, 'dismiss']);
        Route::post('/offers/{offer}/reprice-asks/{ask}', [RepriceController::class, 'answer']);
        // Публикация машины в сделке для других менеджеров (`PublishDeal`): опубликовать или поправить, «Снять публикацию».
        Route::post('/offers/{offer}/deal-publication', [RepriceController::class, 'publish']);
        Route::delete('/offers/{offer}/deal-publication', [RepriceController::class, 'unpublish']);
        Route::post('/offers/{offer}/reprices/{reprice}/answered', [RepriceController::class, 'answered']);
        // «Видно менеджеру» у документа — решает админ (модератору документ менеджерам не открыть).

        Route::post('/confirmations/{bid}/accept', [GiveController::class, 'accept']);
        Route::post('/offers/{offer}/confirmations', [BidController::class, 'storeFor']);
        Route::post('/offers/{offer}/interests', [InterestController::class, 'store']);
        Route::post('/confirmations/{bid}/decline', [BidController::class, 'decline']);
        Route::post('/confirmations/{bid}/amount', [BidController::class, 'amount']);
        Route::post('/confirmations/{bid}/counter', [BidController::class, 'counter']);
        Route::post('/confirmations/{bid}/counter/withdraw', [BidController::class, 'withdrawCounter']);
        Route::post('/confirmations/{bid}/counter/answer', [BidController::class, 'answerCounter']);
        Route::post('/interests/{interest}', [InterestController::class, 'update']);

        // Работа: сделки, почта и чаты под одним заголовком.
        Route::redirect('/work', '/work/mail');
        Route::post('/work/deals/{deal}/declined', [DealController::class, 'declined']);
        Route::post('/work/deals/{deal}/sold', [DealController::class, 'sold']);
        Route::post('/work/deals/{deal}/reopen', [DealController::class, 'reopen']);
        // Параллельные сделки (07.11.2026, `Offers\Parallels`): переключиться на эту — «Взять в работу», закрыть одну — «Снять».
        Route::post('/work/deals/{deal}/take', [ParallelController::class, 'take']);
        Route::post('/work/deals/{deal}/drop', [ParallelController::class, 'drop']);
        // Документ об оплате поставщику за менеджера и одним PDF (10.11.2026, `PaymentProofController`).
        // У кого что в гараже и во что обошлось.
        // Кто что вывозит: мы или менеджер, к нему, к нам или на парковку.
        // «Работа → Вывоз» заменила логистика (10.10.2026): перевозки — на logistics.xcar.ru.
        Route::get('/work/pickups', fn () => redirect()->away(Surface::Logistics->url('/transports'), 301));
        // Скан паспорта покупателя, которого админ указывает за менеджера (20.10.2026) — та же загрузка, что в кабинете.
        // Оплаты — вкладки «По ссылкам / Нам должны / Мы должны» (07.10.2026); карточка счёта — общая со стоянкой.
        Route::get('/work/money', [MoneyController::class, 'index']);
        Route::get('/work/money/managers', [MoneyController::class, 'managers']); // бывшие «Расчёты с менеджерами» — редирект
        // Человек в «Оплатах» (18.11.2026): его машины в разделе; `none` — без менеджера.
        Route::get('/work/money/managers/{person}', [MoneyController::class, 'person'])->where('person', '\d+|none');
        // Разовая оплата услуг по ссылке без сделки — только админ (05.10.2026).
        Route::post('/work/money/service', [MoneyController::class, 'service'])->middleware('ability:isAdmin');
        Route::get('/work/money/cars', [MoneyController::class, 'cars'])->middleware('ability:isAdmin');
        Route::get('/work/money/links/existing', [MoneyController::class, 'existing'])->middleware('ability:isAdmin');
        // «Назначить менеджера» ручной ссылке, по желанию — со сделкой (22.11.2026, `AssignServiceManager`).
        Route::post('/work/money/invoices/{invoice}/manager', [MoneyController::class, 'assign'])->middleware('ability:isAdmin');
        Route::put('/work/money/links/{link}/car', [PayLinkController::class, 'car'])->middleware('ability:isAdmin');
        Route::get('/work/money/links/{link}/attach', [PayLinkController::class, 'attach'])->middleware('ability:isAdmin');
        Route::post('/work/money/acquiring/{attempt}/refund', [PayLinkController::class, 'refund']);
        // Выписка СберБизнеса переехала в Настройки → Банк (07.10.2026): связь толком не настроена, в «Оплатах» она была мусором.
        Route::permanentRedirect('/work/money/bank', '/settings/bank/statement');

        Route::get('/gallery', [GalleryController::class, 'index']);
        // Старые адреса разделов — закладки и ссылки из уведомлений.
        Route::get('/deals', fn () => redirect('/work/deals', 301));

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/limits', [PurchaseController::class, 'restrictions']);
        Route::post('/purchases/limits/{user}', [PurchaseController::class, 'restrict']);
        Route::post('/purchases/prices/{offer}/choose', [PurchaseController::class, 'choose']);
        Route::post('/purchases/prices/{offer}/cancel', [PurchaseController::class, 'unchoose']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::put('/purchases/{purchase}', [PurchaseController::class, 'update']);
        Route::post('/purchases/{purchase}/state', [PurchaseController::class, 'state']);
        Route::post('/purchases/{purchase}/extend', [PurchaseController::class, 'extend']);
        Route::post('/purchases/{purchase}/file', [PurchaseController::class, 'upload']);
        Route::get('/purchases/{purchase}/import', [PurchaseController::class, 'preview']);
        Route::post('/purchases/{purchase}/import', [PurchaseController::class, 'import']);
        Route::get('/purchases/{purchase}/export', [PurchaseController::class, 'export']);
        Route::post('/purchases/{purchase}/counter', [PurchaseController::class, 'counterUpload']);
        Route::get('/purchases/{purchase}/counter', [PurchaseController::class, 'counterPreview']);
        Route::post('/purchases/{purchase}/counter/move', [PurchaseController::class, 'counterMove']);
        Route::redirect('/purchases/{purchase}/offers', '/purchases/{purchase}', 301);
        Route::get('/purchases/{purchase}/{car}', [PurchaseController::class, 'car']);
        Route::match(['get', 'post'], '/purchases/{purchase}/{car}/pdf', [ShareController::class, 'carPdf']);
        Route::get('/purchases/{purchase}/{car}/photo/{media}', [ShareController::class, 'carPhoto'])->whereNumber('media');
        Route::post('/purchases/{purchase}/{car}/photos/warm', [ShareController::class, 'carWarm'])->middleware('throttle:240,1');
        Route::post('/purchases/{purchase}/{car}/zip', [ShareController::class, 'carZip']);
        Route::put('/purchases/{purchase}/{car}', [PurchaseController::class, 'updateCar']);
        // Оценка — в окошке строки таблицы; старый экран ведёт туда же.
        Route::get('/purchases/{purchase}/{car}/estimate', fn (Purchase $purchase, Car $car) => redirect("/purchases/{$purchase->number}?preset=unfinal&vid=table&peek={$car->ref}", 301));
        Route::post('/purchases/{purchase}/{car}/estimate', [PurchaseController::class, 'estimate']);
        Route::post('/purchases/cars/{car}/media', [PurchaseCarPhotoController::class, 'store']);
        Route::post('/purchases/cars/{car}/media/order', [PurchaseCarPhotoController::class, 'reorder']);
        Route::post('/purchases/cars/{car}/media/{media}/hide', [PurchaseCarPhotoController::class, 'toggle']);
        Route::post('/purchases/cars/{car}/media/{media}/rotate', [PurchaseCarPhotoController::class, 'rotate']);
        Route::delete('/purchases/cars/{car}/media/{media}', [PurchaseCarPhotoController::class, 'destroy']);

        // Настройки: справочное, что меняется редко.
        Route::prefix('settings')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            // Группы менеджеров и модераторов — до `/users/{user}`, иначе «groups» приняли бы за человека.
            Route::post('/users/groups', [UserGroupController::class, 'store']);
            Route::put('/users/groups/{group}', [UserGroupController::class, 'update']);
            Route::delete('/users/groups/{group}', [UserGroupController::class, 'destroy']);
            // Замок менеджеров (07.10.2026): тумблер над «Менеджерами».
            Route::put('/users/lock', [UserController::class, 'lock']);
            Route::put('/users/single-device', [UserController::class, 'singleDevice']);
            Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::get('/users/{user}/regions', [RegionController::class, 'show']);
            Route::put('/users/{user}/regions', [RegionController::class, 'update']);
            Route::post('/users/{user}/access', [UserController::class, 'decide']);
            Route::post('/users/{user}/buyer', [UserController::class, 'toBuyer']);
            Route::post('/users/{user}/manager', [UserController::class, 'toManager']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/password', [UserController::class, 'passwordLink']);
            Route::post('/users/{user}/telegram/unlink', [UserController::class, 'unlinkTelegram']);
            Route::post('/users/{user}/link', [UserController::class, 'link']);
            Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate']);
            Route::put('/users/{user}/party', [UserController::class, 'party']);
            Route::put('/users/{user}/passport', [UserController::class, 'passport']);
            Route::get('/users/{user}/statement', [UserController::class, 'statement']);
            Route::get('/users/{user}/export', [UserController::class, 'export']);
            Route::post('/users/invites', [InviteController::class, 'store']);
            Route::post('/users/invites/{invite}/off', [InviteController::class, 'disable']);
            Route::post('/users/invites/{invite}/mail', [InviteController::class, 'mail']);
            Route::post('/users/invites/{invite}/on', [InviteController::class, 'enable']);

            // Ответственные за вкладки «Предложений» (18.11.2026): по ним «Только мои» в колесе.
            Route::get('/responsibles', [ResponsibleController::class, 'index']);
            Route::put('/responsibles/{preset}', [ResponsibleController::class, 'update']);

            Route::get('/tags', [TagController::class, 'index']);
            Route::post('/tags', [TagController::class, 'store']);
            Route::post('/tags/order', [TagController::class, 'reorder']);
            Route::put('/tags/{tag}', [TagController::class, 'update']);
            Route::post('/tags/{tag}/color', [TagController::class, 'color']);
            Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

            Route::get('/watermarks', [WatermarkController::class, 'index']);
            Route::get('/watermarks/{name}/image', [WatermarkController::class, 'image']);
            Route::put('/watermarks/{name}', [WatermarkController::class, 'update']);
            Route::delete('/watermarks/{name}', [WatermarkController::class, 'destroy']);

            Route::get('/vendors', [VendorController::class, 'index']);
            Route::post('/vendors', [VendorController::class, 'store']);
            Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
            Route::get('/vendors/{vendor}/offers/export', [VendorController::class, 'export']);
            Route::post('/vendors/{vendor}/requests', [VendorController::class, 'request']);
            Route::post('/vendors/{vendor}/requests/{claimRequest}/telegram', [VendorController::class, 'resend']);
            Route::delete('/vendors/{vendor}/offers/{offer}/request', [VendorController::class, 'unrequest']);
            Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
            Route::post('/vendors/{vendor}/contacts', [VendorContactController::class, 'store']);
            Route::put('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'update']);
            Route::delete('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'destroy']);
            // Парковочное у вендора (реквизиты, хранение, прайс, деньги, заявки на приёмку) — на парковке с 24.09.2026.
            Route::get('/tariffs', fn () => redirect()->away(Surface::Park->url('/tariffs'), 301));

            // Таймеры (19.01.2027): сроки всех этапов всех вендоров одной таблицей.
            Route::get('/timers', [TimerController::class, 'index']);
            Route::put('/timers/row', [TimerController::class, 'updateRow']);
            Route::put('/timers/{stage}', [TimerController::class, 'update'])->whereNumber('stage');
            // Доставка уведомлений (30.10.2026): что ушло людям и открыли ли — по журналу `notice_deliveries`.
            Route::get('/deliveries', [DeliveryController::class, 'index']);
            // «Парсинг» (08.11.2026): спорные поля машин из источников — списком, выбор вариантом.
            Route::get('/parsing', [ParsingController::class, 'index']);
            Route::get('/parsing/cars/{car}', [ParsingController::class, 'car'])->whereNumber('car');
            Route::post('/parsing/cars/{car}/fields/{field}', [ParsingController::class, 'pick'])->whereNumber('car');
            Route::post('/workflows/{workflow}/enable', [WorkflowController::class, 'activate']);
            Route::post('/workflows/{workflow}/fill', [WorkflowController::class, 'fill']);
            Route::post('/workflows/{workflow}/order', [WorkflowController::class, 'reorder']);
            Route::post('/workflows/{workflow}/blocks', [WorkflowController::class, 'storeBlock']);
            Route::put('/workflows/blocks/{block}', [WorkflowController::class, 'updateBlock']);
            Route::delete('/workflows/blocks/{block}', [WorkflowController::class, 'destroyBlock']);
            Route::get('/workflows/{workflow}/stages/new', [WorkflowController::class, 'createStage']);
            Route::post('/workflows/{workflow}/stages', [WorkflowController::class, 'storeStage']);
            Route::get('/workflows/stages/{stage}', [WorkflowController::class, 'editStage']);
            Route::put('/workflows/stages/{stage}', [WorkflowController::class, 'updateStage']);
            Route::delete('/workflows/stages/{stage}', [WorkflowController::class, 'destroyStage']);

            Route::get('/mailboxes', [MailAccountController::class, 'index']);
            Route::get('/mailboxes/new', [MailAccountController::class, 'create']);
            Route::post('/mailboxes', [MailAccountController::class, 'store']);
            Route::get('/mailboxes/{account}', [MailAccountController::class, 'edit']);
            Route::put('/mailboxes/{account}', [MailAccountController::class, 'update']);
            Route::delete('/mailboxes/{account}', [MailAccountController::class, 'destroy']);
            Route::post('/mailboxes/{account}/check', [MailAccountController::class, 'test']);
            Route::post('/mailboxes/{account}/sync', [MailAccountController::class, 'sync']);

            Route::get('/bank', [BankController::class, 'settings']);
            Route::post('/bank/connect', [BankController::class, 'connect']);
            Route::get('/bank/callback', [BankController::class, 'callback']);
            Route::put('/bank', [BankController::class, 'save']);
            Route::post('/bank/sync', [BankController::class, 'sync']);
            Route::get('/bank/statement', [BankController::class, 'index']);
            Route::post('/bank/statement/{transaction}/match', [BankController::class, 'match']);
            Route::post('/bank/statement/{transaction}/ignore', [BankController::class, 'ignore']);
            Route::post('/bank/statement/{transaction}/car', [BankController::class, 'car']);

            // Переписка бота (Настройки → «Бот Telegram»).
            Route::prefix('telegram')->group(function () {
                Route::get('/', [TelegramChatController::class, 'index']);
                Route::get('/files/{message}', [TelegramChatController::class, 'file']);
                Route::get('/{chat}', [TelegramChatController::class, 'show'])->whereNumber('chat');
                Route::get('/{chat}/messages', [TelegramChatController::class, 'messages'])->whereNumber('chat');
                Route::post('/{chat}/messages', [TelegramChatController::class, 'post'])->whereNumber('chat');
                Route::post('/{chat}/typing', [TelegramChatController::class, 'typing'])->whereNumber('chat');
                Route::get('/{chat}/messages/{seq}', [TelegramChatController::class, 'message'])->whereNumber(['chat', 'seq']);
                Route::patch('/{chat}/messages/{seq}', [TelegramChatController::class, 'edit'])->whereNumber(['chat', 'seq']);
                Route::delete('/{chat}/messages/{seq}', [TelegramChatController::class, 'destroy'])->whereNumber(['chat', 'seq']);
            });

            Route::get('/templates', [MailTemplateController::class, 'index']);
            Route::get('/templates/new', [MailTemplateController::class, 'create']);
            Route::post('/templates', [MailTemplateController::class, 'store']);
            Route::post('/templates/order', [MailTemplateController::class, 'order']);
            Route::get('/templates/{template}', [MailTemplateController::class, 'edit']);
            Route::put('/templates/{template}', [MailTemplateController::class, 'update']);
            Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy']);
        });

        if (app()->isLocal()) {
            Route::view('/ui', 'admin.ui');
        }
    });
});

// На хосте CRM ничего, кроме CRM и общих путей.
Route::domain(config('xcar.crm_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
