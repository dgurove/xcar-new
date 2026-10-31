// Оболочка: Turbo делает из серверных страниц приложение, Stimulus отвечает за
// поведение на месте. Контроллеры лежат в ./controllers/<имя>_controller.js
// и подключаются по имени файла: photos_controller.js → data-controller="photos".
import { why as reloadWhy, reloadForBuild } from './reload-guard';
import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import { touchPrefetch, prefetch } from './touch-prefetch';
import { confirmSheet } from './confirm';
import { closeSheet } from './sheet';
import { netGuards } from './net';
import { live, dropKey, refreshOwn, refreshRows } from './live';
import { onMorphAttribute, onMorphElement } from './lib/morph-guard';
import { bump } from './lib/bump';
import './flip';
import { longPressMenu } from './longpress';
import { setTheme, paintBar, chosenTheme } from './theme';
import { keyboardInset, focusField } from './keyboard';
import { actionRoom } from './lib/action-room';
import { onHide, onResume, RESUME } from './lib/resume';
import { scrollBehavior } from './lib/motion';
import { pullToRefresh } from './lib/pull';
import { keepPosition, onScroll as onPageScroll, quietHover, scrollTop, scrollToY } from './lib/scroller';
import { keepPush } from './push-sync';
import { csrf } from './lib/http';

// Потоки из ответа карточки строки (x-ui.row-card): тост flash-сообщения и «следующий без цены» (detail_controller).
Turbo.StreamActions.toast = function () {
    window.toast?.(this.dataset.message, window.flashOptions?.(this, document.getElementById('detail') ?? document) ?? this.dataset.kind);
};
Turbo.StreamActions.advance = function () {
    document.dispatchEvent(new CustomEvent('detail:advance'));
};
// Окно писем с черновиком из ответа карточки (x-ui.row-card, session letters — письмо вендору после «Принять»).
// Открываем, когда карточка встала: поток advance перед этим меняет адрес страницы (detail_controller#address
// переписывает верхнюю запись истории) — открытое раньше окно потеряло бы свою запись, и «Назад» увёл бы со списка.
// Карточка не грузится (дальше строк нет) — сразу; ответ не пришёл за 1,5 с — всё равно открываем (18.10.2026: окно ждали
// по 4 с, и казалось, что его нет).
Turbo.StreamActions.letters = function () {
    const url = this.getAttribute('url');
    const open = () => window.dispatchEvent(new CustomEvent('letters:open', { detail: { url } }));
    requestAnimationFrame(() => {
        const frame = document.getElementById('detail');
        if (!frame?.hasAttribute('busy')) { open(); return; }
        let done = false;
        const once = () => { if (!done) { done = true; requestAnimationFrame(open); } };
        frame.addEventListener('turbo:frame-load', once, { once: true });
        setTimeout(once, 1500);
    });
};
// Письмо из черновика ушло (MailController::send с then=close) — окно писем закрывается без перечитывания страницы.
Turbo.StreamActions['letters-close'] = function () {
    window.dispatchEvent(new CustomEvent('letters:close'));
};
// Окно действия парковки сделало своё (x-park.act-window, шаг done): окно закрывается, карточка строки перечитывается
// (из «Заявок» её ответ ведёт к следующей заявке — flash detail-advance), без карточки — страница. url — письмо вендору
// окном писем: в карточке — когда она встанет, в деле — адресом ?window=.
Turbo.StreamActions['act-done'] = function () {
    window.dispatchEvent(new CustomEvent('act:close'));
    const url = this.getAttribute('url');
    const frame = document.getElementById('detail');
    if (frame?.childElementCount) {
        const src = location.pathname + location.search;
        if (frame.getAttribute('src') === src) frame.reload(); else frame.src = src;
        if (url) Turbo.StreamActions.letters.call(this);
        return;
    }
    if (url) {
        const to = new URL(location.href);
        to.searchParams.set('window', url);
        Turbo.visit(to.pathname + to.search, { action: 'replace' });
        return;
    }
    Turbo.StreamActions.reload.call(this);
};
// Дело в карточке поменяло весь список (лента «Оплат»: строка уходит из «Надо сделать» в «Историю») — страница
// перечитывается морфом с той же карточкой. Штатный refresh Turbo молча пропускает, пока идёт визит фрейма карточки
// (у фрейма turbo-action="replace"), поэтому ждём его конца, но не дольше секунды.
Turbo.StreamActions.reload = function () {
    const go = (n) => (Turbo.session.navigator.currentVisit && n < 60 ? requestAnimationFrame(() => go(n + 1)) : Turbo.session.refresh(document.baseURI));
    go(0);
};
// Число у пилюли вкладки после дела в карточке: выросло — пилюля коротко вспыхивает, видно, куда уехала строка.
// Строка ушла из вкладки (ответ карточки, `detail-gone`): убрать её и вычесть из числа группы и итога экрана — как
// ушедшую при живом обновлении; голый `remove` оставлял числа старыми и пустую группу с заголовком.
Turbo.StreamActions.drop = function () {
    dropKey(this.getAttribute('key'));
};

// Действие тронуло и другие строки (пачка из карточки, «Ответ по списку»): перечитать их, как свои.
Turbo.StreamActions.rows = function () {
    refreshOwn((this.getAttribute('keys') || '').split(','));
};

Turbo.StreamActions.count = function () {
    const n = Number(this.getAttribute('count')) || 0;
    this.targetElements.forEach((el) => {
        const was = Number(el.textContent.replace(/\D/g, '')) || 0;
        el.textContent = n ? String(n) : '';
        if (n > was) bump(el);
    });
};

const application = Application.start();
window.Stimulus = application;

// Контроллеры, нужные только на своих экранах (чат, фото, сканер QR, подпись, редактор…), — отдельными кусками:
// грузятся, когда на странице появился их data-controller. Остальные — в основной сборке, сразу. «Кому показывать»
// (audience) — сразу (19.10.2026): шторка открывалась раньше куска и была пустой, правка из неё до формы не доходила.
// Галерея — сразу (22.10.2026): пока её кусок не пришёл, нажатие на кадр было ссылкой на картинку, и в приложении с экрана
// «Домой» она открывалась во весь экран без выхода. Не пришёл кусок — окно берёт новую сборку (reloadForBuild).
const controllerName = (path) => path.match(/\/([\w-]+)_controller\.js$/)[1].replace(/_/g, '-');
const controllers = import.meta.glob(['./controllers/*_controller.js', '!./controllers/{chat,photos,qr_release,share,passkey,vin,draft,editor,combobox,login,tariff_form,car_text,drop_empty,valuation,date,export,yard_editor}_controller.js'], { eager: true });
for (const [path, module] of Object.entries(controllers)) application.register(controllerName(path), module.default);
const lazy = new Map(Object.entries(import.meta.glob('./controllers/{chat,photos,qr_release,share,passkey,vin,draft,editor,combobox,login,tariff_form,car_text,drop_empty,valuation,date,export,yard_editor}_controller.js')).map(([path, load]) => [controllerName(path), load]));
const loadLazy = (root) => {
    for (const [name, load] of lazy) {
        const selector = `[data-controller~="${name}"]`;
        if (!root.matches?.(selector) && !root.querySelector?.(selector)) continue;
        lazy.delete(name);
        load().then((module) => application.register(name, module.default)).catch(() => { lazy.set(name, load); reloadForBuild(); });
    }
};
loadLazy(document.documentElement);
new MutationObserver((records) => {
    if (!lazy.size) return;
    for (const r of records) {
        if (r.type === 'attributes') loadLazy(r.target);
        else r.addedNodes.forEach((node) => node.nodeType === 1 && loadLazy(node));
    }
}).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-controller'] });

// Полоса — только у долгих визитов: с префетчем на touchstart быстрые укладываются в 400 мс.
Turbo.config.drive.progressBarDelay = 400;
// data-turbo-confirm — своей шторкой, не системным диалогом.
// Пустой data-turbo-confirm у кнопки — «без подтверждения», даже если оно стоит на форме (сохранить поля ТС в форме шага).
Turbo.config.forms.confirm = (message, form, submitter) => (message === '' ? Promise.resolve(true) : confirmSheet(message, { form, submitter }));

// Морф бережёт то, что живёт на клиенте и серверному HTML неизвестно:
// open у диалогов (блок в потоке, открытая шторка), точки и подгрузка кадров,
// поле с фокусом или набранным текстом (тосты — постоянный узел, морф их обходит).
// Зовётся на каждый атрибут каждого элемента — сначала имя атрибута, `matches` только для своих.
onMorphAttribute((event) => {
    const el = event.target;
    let keep = false;
    switch (event.detail.attributeName) {
        case 'open': keep = el instanceof HTMLDialogElement; break;
        case 'loading': keep = el.dataset.framesTarget === 'frame'; break;
        case 'class': keep = el.classList.contains('card-dot'); break;
        // Блок ключа открывает JS; серверный `hidden` при морфе его бы снова спрятал.
        case 'hidden': keep = el.matches('[data-controller~="passkey"]'); break;
        // Режим поиска лупой ставит JS; морф по live-обновлению его бы снял.
        case 'data-searching': keep = el.matches('[data-controller~="live-search"]'); break;
        // Своя проверка формы (formCheck): без novalidate вернулся бы пузырь Safari.
        case 'novalidate': case 'data-check': keep = el instanceof HTMLFormElement; break;
    }
    if (keep) event.preventDefault();
});
onMorphElement((event) => {
    const el = event.target;
    // Фрейм в открытой модальной шторке (окно письма после «Принять», окно приёма парковки) грузит то, что поставил
    // клиент: морф страницы снимал ему src — запрос обрывался, окно оставалось заготовкой (18.10.2026).
    if (el instanceof HTMLElement && el.matches('turbo-frame') && el.closest('dialog')?.matches(':modal')) event.preventDefault();
    if (el === document.activeElement && el.matches('input, textarea, select')) event.preventDefault();
    if (el.matches('input, textarea') && el.value !== el.defaultValue) event.preventDefault();
});
touchPrefetch();
pressFeedback();
inPageAnchors();
netGuards();
live();
imageFade();
freshness();
keepSideScroll();
lazyInSnapshot();
keepPush();
focusInvalid();
formCheck();
formFree();
enterNext();
relaunchScroll();
keepPosition();
quietHover();
stalePage();
keyboardInset();
actionRoom();
systemTheme();
headerState();
activePillIntoView();
haptics();
neighbourDirection();
noticeSeen();
offerRead();
longPressMenu();
heroTransition();
timerDone();
transitionsWhenAnimated();
noZoom();

// View Transitions роняют промис, когда вкладка скрыта или переход перебит
// следующим: страница при этом в порядке, в консоли этому не место.
window.addEventListener('unhandledrejection', (event) => {
    if (['InvalidStateError', 'AbortError'].includes(event.reason?.name) && /transition/i.test(event.reason?.message || '')) {
        event.preventDefault();
    }
});

// Нажатое держится, пока не приедет экран: класс is-pending на карточке, строке,
// кнопке или табе с момента turbo:click до рендера (стили — блок «нажатие» в app.css).
// Кнопка отправки получает aria-busy — кольцо вместо текста. Ссылка или GET-форма
// из открытой шторки закрывает её сама: при morph-визите Turbo диалог не трогает.
function pressFeedback() {
    const clear = () => document.querySelectorAll('.is-pending').forEach((el) => el.classList.remove('is-pending'));
    document.addEventListener('turbo:click', (event) => {
        event.target.closest('.card, .row, .chat-row, .search-link, .stat, .pill, .btn, .tab, .header-btn')?.classList.add('is-pending');
        const dialog = event.target.closest('dialog[open]');
        if (!dialog?.matches(':modal')) return;
        const link = event.target.closest('a[href]');
        // Ссылка во фрейм внутри той же шторки (окно писем: «Ответить», шаблон) — фрейм и грузится, шторка живёт.
        const frame = link?.closest('turbo-frame');
        const into = link?.dataset.turboFrame ?? frame?.getAttribute('target') ?? frame?.id;
        if (frame && dialog.contains(frame) && into && into !== '_top' && dialog.querySelector(`#${CSS.escape(into)}`)) return;
        // Запись шторки в истории снимается до визита, иначе «назад» вернёт на шторку.
        event.preventDefault();
        event.detail.originalEvent.preventDefault();
        closeSheet(dialog).then(() => Turbo.visit(event.detail.url, { action: link?.dataset.turboAction || 'advance' }));
    });
    // press:clear — запрос остановлен без отрисовки (429 в net.js): нажатое иначе так и висело бы бледным.
    for (const name of ['turbo:before-render', 'turbo:load', 'turbo:fetch-request-error', 'turbo:before-cache', 'press:clear']) document.addEventListener(name, clear);
    // Нажатие на плитку или строку-ссылку — класс is-pressed под пальцем, вместо .card:has(a:active): тот WebKit
    // пересчитывал у всех плиток на каждое касание. Палец поехал (прокрутка) — нажатия нет.
    let pressed = null;
    const release = () => { pressed?.classList.remove('is-pressed'); pressed = null; };
    document.addEventListener('pointerdown', (event) => {
        release();
        const link = event.target.closest?.('a[href]');
        const host = link?.closest('.card, .row');
        if (!host) return;
        if (host.matches('.card') ? (link.parentElement === host || link.closest('.card-media, .card-title')) : link.parentElement === host) {
            pressed = host;
            host.classList.add('is-pressed');
        }
    }, { passive: true });
    for (const name of ['pointerup', 'pointercancel']) document.addEventListener(name, release, { passive: true });
    addEventListener('scroll', release, { capture: true, passive: true });
    // Форма, что уже отправляется, второй раз не уходит: двойной тап, Enter, отправка из скрипта (меню, autosubmit,
    // дата, «Сохранить изменения») — Turbo на повторе отменил бы первый запрос, а тот к серверу уже мог дойти.
    // GET (фильтр, поиск) — как прежде, новый заменяет старый. Минута без ответа — замок снимается сам.
    document.addEventListener('turbo:submit-start', (event) => {
        event.detail.formSubmission.submitter?.setAttribute('aria-busy', 'true');
        if (event.detail.formSubmission.method.toLowerCase() !== 'get') event.target.dataset.sending = String(Date.now());
    });
    document.addEventListener('turbo:submit-end', (event) => { delete event.target.dataset.sending; });
    window.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form?.dataset?.sending || Date.now() - Number(form.dataset.sending) > 60_000) return;
        event.preventDefault();
        event.stopImmediatePropagation();
    }, { capture: true });
    // GET-форма (фильтры, поиск) из открытой шторки: запись шторки снимается из истории
    // до отправки, иначе «назад» после фильтра вернёт адрес без содержимого.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const dialog = form.closest?.('dialog[open]');
        if (!dialog?.matches(':modal') || (form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        event.stopPropagation();
        const submitter = event.submitter;
        closeSheet(dialog).then(() => form.requestSubmit(submitter ?? undefined));
    }, true);
    document.addEventListener('turbo:submit-end', (event) => event.detail.formSubmission.submitter?.removeAttribute('aria-busy'));

    // POST из шторки: ответ — редирект на тот же адрес, Turbo делает morph, а
    // open у диалога морф бережёт — шторка оставалась висеть. Успешная отправка
    // закрывает её, кроме случая, когда сервер снова просит её открыть (ошибки формы).
    let submittedSheet = null;
    document.addEventListener('turbo:submit-end', (event) => {
        const form = event.target;
        const dialog = form.closest?.('dialog[open]');
        if (event.detail.success && dialog?.matches(':modal') && (form.method || 'get').toLowerCase() !== 'get') submittedSheet = dialog;
    });
    document.addEventListener('turbo:before-render', (event) => {
        const dialog = submittedSheet;
        submittedSheet = null;
        if (!dialog?.open) return;
        const fresh = dialog.id ? event.detail.newBody?.querySelector(`#${CSS.escape(dialog.id)}`) : null;
        if (fresh?.dataset.sheetOpenValue !== 'true') closeSheet(dialog);
    });
}

// Android: долгое нажатие по фото и хрому не открывает меню Chrome «Открыть в новой вкладке».
document.addEventListener('contextmenu', (event) => {
    if (event.target.closest('.tabbar, .header, .action-bar, .photo-strip, [data-gallery-target="strip"]')) event.preventDefault();
});

// Якорь на той же странице: плавно и без записи в историю («Смотреть предложения»).
function inPageAnchors() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        const target = link && link.hash.length > 1 && document.getElementById(decodeURIComponent(link.hash.slice(1)));
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ behavior: scrollBehavior(), block: 'start' });
    });
}

// Кадры появляются плавно: незагруженным img — is-loading до load, из кэша — сразу.
function imageFade() {
    const mark = () => document.querySelectorAll('.card-media img, .photo-cell img, [data-gallery-target="strip"] img').forEach((img) => {
        if (!img.complete) img.classList.add('is-loading');
    });
    document.addEventListener('turbo:load', mark);
    document.addEventListener('turbo:render', mark);
    const done = (event) => event.target.classList?.remove('is-loading');
    document.addEventListener('load', done, true);
    document.addEventListener('error', done, true);
}

// Снимок «назад» старше 2 мин и возврат из фона дольше минуты — тихий replace:
// морф на месте с сохранением прокрутки, заодно свежие csrf-token и темы live.
// 2 мин, а не 10 с: карточки и так живут по Mercure, а открыть ТС, почитать и вернуться — не повод
// перерисовывать весь список заново («Назад» должен быть мгновенным).
// Только на списках и не под руками: открытая шторка или правка формы — не трогаем. Догруженная лента — тоже (ответ
// принёс бы одну первую порцию). Другой экран, оставленный надолго (больше 10 мин, на ночь), — тоже перечитывается.
// Ими же «потянуть вниз» (lib/pull.js): тянуть можно везде, где не под руками.
// Снимок страницы для «Назад» Turbo делает копией (`cloneNode`), а Chrome у отсоединённой копии качает все ленивые
// картинки сразу: уход из каталога тянул 550 кадров, которых никто не увидит (аудит 20.10.2026). Перед снимком у ещё не
// загруженных ленивых картинок адрес уходит в data-, на показе снимка возвращается — дальше они ленивые, как были.
function lazyInSnapshot() {
    document.addEventListener('turbo:before-cache', () => {
        for (const img of document.querySelectorAll('img[loading="lazy"]')) {
            if (img.complete && img.naturalWidth) continue;
            if (img.hasAttribute('srcset')) { img.dataset.lazySrcset = img.getAttribute('srcset'); img.removeAttribute('srcset'); }
            if (img.hasAttribute('src')) { img.dataset.lazySrc = img.getAttribute('src'); img.removeAttribute('src'); }
        }
    });
    const back = () => {
        for (const img of document.querySelectorAll('img[data-lazy-src], img[data-lazy-srcset]')) {
            if (img.dataset.lazySrcset) img.setAttribute('srcset', img.dataset.lazySrcset);
            if (img.dataset.lazySrc) img.setAttribute('src', img.dataset.lazySrc);
            delete img.dataset.lazySrc; delete img.dataset.lazySrcset;
        }
    };
    document.addEventListener('turbo:render', back);
    document.addEventListener('turbo:load', back);
}

// «Назад» возвращает и прокрутку вбок: снимок Turbo копирует разметку, а scrollLeft лент плиток, широких таблиц, пилюль
// и кадров плитки в копию не переходит — полки сайта вставали к началу. Порядок лент на странице — ключ.
function keepSideScroll() {
    const SIDE = '.cards-shelf, .table-box, .pills, .card-strip';
    const saved = new Map();
    let restore = false;
    // Адрес снимка — свой: на «Назад» location уже новый, когда Turbo снимает уходящую страницу.
    let shown = location.href;
    document.addEventListener('turbo:before-cache', () => {
        const at = [...document.querySelectorAll(SIDE)].map((el) => el.scrollLeft);
        if (at.some(Boolean)) saved.set(shown, at); else saved.delete(shown);
    });
    document.addEventListener('turbo:visit', (e) => { restore = e.detail.action === 'restore'; });
    document.addEventListener('turbo:load', () => {
        shown = location.href;
        const at = restore ? saved.get(location.href) : null;
        restore = false;
        if (!at) return;
        document.querySelectorAll(SIDE).forEach((el, i) => { if (at[i]) el.scrollTo({ left: at[i], behavior: 'instant' }); });
    });
}

function freshness() {
    const cachedAt = new Map();
    let action = null;
    const calm = () => !document.querySelector('form[data-dirty], dialog:modal, [data-controller~="endless"][data-extended]')
        && !document.activeElement?.matches('input, textarea, select, [contenteditable]');
    const quiet = () => document.querySelector('.cards, [data-list], [data-fresh-on-back]') && calm();
    // Метка — чтобы net.js не перечитывал токен отдельным запросом: его принесёт этот же ответ.
    const refresh = () => { window.xcarRefreshedAt = Date.now(); Turbo.visit(location.href, { action: 'replace' }); };
    // Адрес снимка — свой: на «Назад» location уже прежний, когда Turbo снимает уходящую страницу, и время ложилось
    // не той странице (возвращённый список всегда казался свежим).
    let shown = location.href;
    document.addEventListener('turbo:before-cache', () => cachedAt.set(shown, Date.now()));
    document.addEventListener('turbo:visit', (event) => { action = event.detail.action; });
    document.addEventListener('turbo:load', () => {
        shown = location.href;
        // Лента уведомлений и список чатов — сразу: точки «не прочитано» в снимке остались от времени до прочтения.
        const stale = document.querySelector('[data-fresh-on-back]') || Date.now() - (cachedAt.get(location.href) ?? Date.now()) > 120_000;
        if (action === 'restore' && stale && quiet()) refresh();
        action = null;
    });
    onResume((away) => { if ((away > 60_000 && quiet()) || (away > 600_000 && calm())) refresh(); }, RESUME.page);
    pullToRefresh(refresh, () => !document.querySelector('form[data-dirty], dialog:modal')
        && !document.activeElement?.matches('input, textarea, select, [contenteditable]'));
}

// Ошибка валидации приводит к полю: после морфа с preserve-scroll человек стоит у
// «Сохранить», а красное поле — вне экрана.
// Ошибка сервера бывает у x-ui.field (.field-invalid), у строки анкеты (.form-row.is-invalid) и голой подписью
// .field-error под своим полем — поле тогда ближайшее перед ней. Фрейм (кроме карточки строки — у неё своё) — так же.
const typed = 'input:not([type=hidden]), select, textarea';
function firstInvalid(root) {
    const bad = root.querySelector('.field-invalid .field-input, .form-row.is-invalid input, .form-row.is-invalid select');
    if (bad) return bad;
    const note = root.querySelector('.field-error');
    for (let el = note?.previousElementSibling; el; el = el.previousElementSibling) {
        const field = el.matches(typed) ? el : el.querySelector(typed);
        if (field) return field;
    }
    return null;
}

function focusInvalid() {
    // В шторке на телефоне — без фокуса: клавиатуры без нажатия нет, а поле в фокусе потом уже не ловит своё нажатие.
    const show = (root) => { const bad = firstInvalid(root); if (bad) focusField(bad, { keyboard: false }); };
    document.addEventListener('turbo:load', () => show(document));
    document.addEventListener('turbo:frame-load', (e) => { if (e.target.id !== 'detail') show(e.target); });
}

// Поля, что стоят внутри чужой формы, но не её данные (28.10.2026, разбор «Заполните поле» у письма): панель
// форматирования Trix (её диалог ссылки — `required` поле «Адрес»: открыли ссылку и спрятали панель «Aa» — пустое
// невидимое поле держало отправку письма, а `href` уходил вместе с письмом) и шторка выбора человека `x-people.pick`
// внутри формы вывоза, договора, исполнителя (`data-form-free`: её «+ Новый …» с почтой и телефоном собирает свои поля
// сам и шлёт JSON — неверная почта там держала форму-хозяина, а `_person[…]` уходили с ней мусором). Атрибут `form` на
// несуществующий id отвязывает поле от формы: форма его не проверяет и не отправляет, сам он работает как прежде.
// Новое такое место — `data-form-free` у обёртки, а не обход в проверке форм.
function formFree() {
    const FREE = ':is(trix-toolbar, [data-form-free]) :is(input, select, textarea, button)';
    const detach = (el) => { if (!el.hasAttribute('form')) el.setAttribute('form', 'form-free'); };
    const free = (root) => {
        if (root.matches?.(FREE)) detach(root);
        root.querySelectorAll?.(FREE).forEach(detach);
    };
    free(document);
    // Trix строит панель уже после вставки редактора, шторки приходят потоками и фреймами.
    new MutationObserver((records) => {
        for (const r of records) for (const node of r.addedNodes) if (node.nodeType === 1) free(node);
    }).observe(document.documentElement, { childList: true, subtree: true });
}

// Своя проверка форм вместо пузыря Safari «Заполните это поле»: формам noValidate (при загрузке, новым из потоков и
// фреймов, при касании), на отправке — checkValidity(). Первое неверное поле получает фокус и ошибку словами там же,
// где её рисует сервер: в x-ui.field — .field-invalid и .field-error в конце поля, в строке .form-row — is-invalid и
// .form-row-error, у голого поля — строкой под ним (под рядом, если поле стоит в ряду с кнопкой). Текст — из
// data-check-text поля, иначе общий. Ввод снимает ошибку. novalidate в разметке и formnovalidate у кнопки — без проверки.
function formCheck() {
    const own = (form) => form instanceof HTMLFormElement && 'check' in form.dataset;
    const mark = (form) => {
        if (!(form instanceof HTMLFormElement) || own(form) || form.hasAttribute('novalidate')) return;
        form.dataset.check = '';
        form.noValidate = true;
    };
    const markAll = (root) => root?.querySelectorAll?.('form').forEach(mark);
    markAll(document);
    document.addEventListener('turbo:load', () => markAll(document));
    new MutationObserver((records) => {
        for (const r of records) r.addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            if (node.tagName === 'FORM') mark(node);
            else if (node.firstElementChild) markAll(node);
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
    const touched = (event) => mark(event.target.closest?.('button, input, select, textarea')?.form ?? event.target.closest?.('form'));
    document.addEventListener('focusin', touched, true);
    document.addEventListener('pointerdown', touched, true);

    const signs = (n) => (n % 10 === 1 && n % 100 !== 11 ? 'знака' : 'знаков');
    const day = (value) => (/^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10).split('-').reverse().join('.') : value);
    const text = (el) => {
        const v = el.validity, dated = ['date', 'datetime-local', 'month', 'time'].includes(el.type);
        if (v.valueMissing) {
            if (el.dataset.checkText) return el.dataset.checkText;
            if (el.type === 'checkbox') return 'Поставьте галку';
            if (el.type === 'radio' || el.tagName === 'SELECT') return 'Выберите вариант';
            if (el.type === 'file') return 'Добавьте файл';
            return 'Заполните поле';
        }
        if (v.typeMismatch) return el.type === 'email' ? 'Почта с ошибкой' : el.type === 'url' ? 'Ссылка с ошибкой' : 'Проверьте значение';
        if (v.tooShort) return `Не короче ${el.minLength} ${signs(el.minLength)}`;
        if (v.tooLong) return `Не длиннее ${el.maxLength} ${signs(el.maxLength)}`;
        if (v.rangeUnderflow) return `${dated ? 'Не раньше' : 'Не меньше'} ${day(el.min)}`;
        if (v.rangeOverflow) return `${dated ? 'Не позже' : 'Не больше'} ${day(el.max)}`;
        if (v.badInput) return dated ? 'Проверьте дату' : 'Введите число';
        if (v.customError) return el.validationMessage;
        return el.title || 'Проверьте значение';
    };

    // Поле → как снять его ошибку.
    const shown = new Map();
    const clear = (el) => { shown.get(el)?.(); shown.delete(el); };
    const row = (node) => { const s = getComputedStyle(node); return s.display.includes('flex') && !s.flexDirection.startsWith('column'); };
    const show = (el) => {
        for (let d = el.closest('details:not([open])'); d; d = d.parentElement?.closest('details:not([open])')) d.open = true;
        const message = text(el);
        // Поле не видно — тостом, и с его названием: «Заполните поле» без него не говорило, какое.
        if (!el.getClientRects().length) {
            const name = el.labels?.[0]?.textContent?.trim() || el.getAttribute('aria-label') || el.placeholder || '';
            window.toast?.(name ? `${message}: ${name}` : message, 'danger');
            return;
        }
        const undo = [];
        const flag = (node, cls) => { if (node.classList.contains(cls)) return; node.classList.add(cls); undo.push(() => node.classList.remove(cls)); };
        const say = (box, selector, make) => {
            let note = box.querySelector(selector);
            if (note) { const was = note.textContent; undo.push(() => { note.textContent = was; }); }
            else { note = make(); box.append(note); undo.push(() => note.remove()); }
            note.textContent = message;
        };
        const box = el.closest('.field, .form-row');
        if (box?.matches('.form-row')) {
            flag(box, 'is-invalid');
            say(box, '.form-row-error', () => Object.assign(document.createElement('span'), { className: 'form-row-error' }));
        } else if (box) {
            flag(box, 'field-invalid');
            say(box, ':scope > .field-error', () => Object.assign(document.createElement('p'), { className: 'field-error' }));
        } else {
            // Голое поле: под ним, галка — под своей подписью, переключатели — под всей группой, поле в ряду — под рядом
            // (форма-ряд «поле и кнопка» — под формой).
            let anchor = ['checkbox', 'radio'].includes(el.type) ? el.closest('label') ?? el : el;
            if (el.type === 'radio' && el.name) {
                const group = [...document.getElementsByName(el.name)].filter((r) => r.form === el.form);
                while (anchor.parentElement && !group.every((r) => anchor.contains(r))) anchor = anchor.parentElement;
            }
            while (anchor !== el.form && anchor.parentElement && row(anchor.parentElement)) anchor = anchor.parentElement;
            const note = Object.assign(document.createElement('p'), { className: 'field-error', textContent: message });
            // Под подписью галки или рядом — с их отступом слева, как серверная ошибка.
            if (anchor !== el) note.style.paddingInlineStart = getComputedStyle(anchor).paddingInlineStart;
            anchor.after(note);
            // Вплотную к полю, как серверная ошибка (6 px), каким бы ни был зазор между строками формы.
            const parent = getComputedStyle(anchor.parentElement);
            const gap = /flex|grid/.test(parent.display) ? parseFloat(parent.rowGap) || 0 : 0;
            const below = parseFloat(getComputedStyle(anchor).marginBottom) || 0;
            note.style.marginTop = `${6 - gap - below}px`;
            // Отступ поля снизу (mb-3 у заметки) переходит к ошибке: следующая строка не прилипает к ней.
            if (below) note.style.marginBottom = `${below}px`;
            undo.push(() => note.remove());
        }
        el.setAttribute('aria-invalid', 'true');
        undo.push(() => el.removeAttribute('aria-invalid'));
        shown.set(el, () => undo.forEach((fn) => fn()));
        focusField(el);
    };

    // Раньше Turbo и обработчиков формы: неверная форма не отправляется вовсе, как с проверкой браузера.
    window.addEventListener('submit', (event) => {
        const form = event.target;
        if (!own(form) || event.submitter?.formNoValidate) return;
        for (const el of [...shown.keys()]) if (el.form === form || !el.isConnected) clear(el);
        // Чужие поля внутри формы (панель Trix, «+ Новый …») к ней не относятся вовсе — `formFree`.
        const bad = [...form.elements].find((el) => el.willValidate && !el.validity.valid);
        if (!bad) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        show(bad);
    }, true);
    const fix = (event) => {
        const el = event.target;
        if (shown.has(el)) clear(el);
        else if (el.type === 'radio') for (const k of [...shown.keys()]) if (k.name === el.name && k.form === el.form) clear(k);
    };
    document.addEventListener('input', fix, true);
    document.addEventListener('change', fix, true);
}

// Enter в поле с enterkeyhint="next" из разметки (логин) переводит к следующему пустому полю формы (паролю), а не
// отправляет её; следующее заполнено (пароль подставил менеджер паролей) — отправляет. У форм с next_controller свои правила.
function enterNext() {
    const SKIP = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'image', 'reset'];
    document.addEventListener('keydown', (event) => {
        const el = event.target;
        if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing || el.tagName !== 'INPUT' || el.getAttribute('enterkeyhint') !== 'next') return;
        const form = el.form;
        if (!form || form.matches('[data-controller~="next"]')) return;
        const fields = [...form.elements];
        const next = fields.slice(fields.indexOf(el) + 1).find((f) => f.matches('input, select, textarea') && !SKIP.includes(f.type) && !f.disabled && f.getClientRects().length);
        if (!next || next.value !== '') return;
        event.preventDefault();
        focusField(next);
    });
}

// Приложение выгрузили из фона и открыли заново — страница грузится с нуля, а
// человек стоял на тридцатой карточке. Позиция пишется при уходе в фон и
// возвращается только на полной загрузке того же адреса, если ей меньше получаса.
function relaunchScroll() {
    const key = () => 'scroll:' + location.href;
    const save = () => { try { localStorage.setItem(key(), JSON.stringify({ y: scrollTop(), at: Date.now() })); } catch {} };
    onHide(save);
    window.addEventListener('pagehide', save);
    const nav = performance.getEntriesByType('navigation')[0];
    if (!['navigate', 'reload'].includes(nav?.type)) return;
    try {
        const saved = JSON.parse(localStorage.getItem(key()) || 'null');
        if (saved && Date.now() - saved.at < 30 * 60 * 1000 && saved.y > 0) requestAnimationFrame(() => scrollToY(saved.y));
        for (const k of Object.keys(localStorage)) {
            if (!k.startsWith('scroll:') && !k.startsWith('draft:')) continue;
            const at = JSON.parse(localStorage.getItem(k) || '{}').at ?? 0;
            if (Date.now() - at > 24 * 3600 * 1000) localStorage.removeItem(k);
        }
    } catch {}
}

// Фото карточки перетекает в главный кадр галереи и обратно: одно имя
// view-transition-name у кадра, на который смотрели, и у кадра на новой странице.
// Старый элемент помечается до захвата снимка (turbo:click / turbo:visit restore),
// новый — в newBody на turbo:before-render; после рендера имена снимаются,
// иначе два одинаковых имени отменят переход.
function heroTransition() {
    const name = (el) => { if (el) el.style.viewTransitionName = 'hero'; };
    const cardImg = (card) => {
        const strip = card?.querySelector('.card-strip');
        if (!strip) return card?.querySelector('.card-media img');
        return [...strip.querySelectorAll('img')].find((img) => Math.abs(img.offsetLeft - strip.scrollLeft) < 4) || strip.querySelector('img');
    };
    const pageImg = (root) => root.querySelector('[data-gallery-target="strip"] img, .photo-strip img');
    document.addEventListener('turbo:click', (event) => name(cardImg(event.target.closest('.card'))));
    document.addEventListener('turbo:visit', (event) => {
        if (event.detail.action === 'restore' && document.body.dataset.offerPage) name(pageImg(document.body));
    });
    document.addEventListener('turbo:before-render', (event) => {
        const body = event.detail.newBody;
        const from = document.querySelector('[style*="view-transition-name"]');
        if (!from) return;
        const number = from.closest('.card')?.dataset.offerNumber;
        if (body.dataset.offerPage) name(pageImg(body));
        else if (number) name(cardImg(body.querySelector(`#offer-${number}, #admin-offer-${number}`)));
    });
    document.addEventListener('turbo:load', () => {
        document.querySelectorAll('[style*="view-transition-name"]').forEach((el) => { el.style.viewTransitionName = ''; });
    });
}

// Turbo оборачивает в view transition каждый визит, хотя едут только соседи по списку (data-nav-dir) и фото
// карточки (hero). Снимок без анимации не нужен, а в установленном приложении iOS 26–27 вредит: viewport там
// короче экрана на верхний вырез, и снимок таб-бара встаёт по другой высоте — бар подпрыгивает на каждом
// переходе. Turbo спрашивает мету текущей страницы до рендера; новая страница приносит её обратно.
function transitionsWhenAnimated() {
    document.addEventListener('turbo:visit', () => {
        const animated = 'navDir' in document.documentElement.dataset || document.querySelector('[style*="view-transition-name"]');
        document.querySelector('meta[name="view-transition"]')?.setAttribute('content', animated ? 'same-origin' : 'none');
    });
}

// Зума в интерфейсе нет (15.10.2026, владелец: «в приложениях не бывает зума»): viewport его запрещает, а touch-action —
// щипок и двойное касание (app.css), но вкладка Safari viewport-запрет не слушает. Жест WebKit отменяется у всей страницы;
// просмотр фото (PhotoSwipe) и документа (docs/zoom.js) масштабируют своё по касаниям, жест им не нужен. Ключ прежней
// плашки замера ?vp=1 стирается: она висела у тех, кто её однажды включил.
function noZoom() {
    for (const type of ['gesturestart', 'gesturechange', 'gestureend']) document.addEventListener(type, (e) => e.preventDefault(), { passive: false });
    try { localStorage.removeItem('vp'); } catch {}
}

// Таймер дошёл до нуля: всё, что помечено data-closes-with-timer, гаснет сразу
// (форма цены, кнопка «Подтвердить»), а через три секунды перечитывается — сервер решает по времени (bidsOpen),
// состояния «закрыт» нет. Таймер в строке списка — только его строки (`refreshRows`): у списка CRM часы в каждой строке,
// и весь список (28 тыс. узлов) перерисовывался на каждом истёкшем сроке (аудит 20.10.2026). Вне строк — страница.
function timerDone() {
    let planned = null;
    const keys = new Set();
    let page = false;
    document.addEventListener('timer:done', (e) => {
        const row = e.target.closest?.('[data-detail-key]');
        if (row && !row.closest('turbo-frame#detail, dialog')) keys.add(row.dataset.detailKey);
        else page = true;
        document.querySelectorAll('[data-closes-with-timer]').forEach((el) => {
            if (el.matches('button, a')) { el.classList.add('btn-quiet'); el.classList.remove('btn-accent'); el.setAttribute('aria-disabled', 'true'); el.style.pointerEvents = 'none'; }
            else el.hidden = true;
        });
        document.querySelectorAll('[data-shows-when-closed]').forEach((el) => { el.hidden = false; });
        clearTimeout(planned);
        planned = setTimeout(() => {
            if (document.querySelector('form[data-dirty], dialog:modal')) return;
            if (page) Turbo.visit(location.href, { action: 'replace' });
            else if (keys.size) refreshRows([...keys]);
            keys.clear();
            page = false;
        }, 3000);
    });
}

// Пока тема не выбрана руками (cookie нет) — следуем за системой, в том числе на лету; после переходов Turbo сверяем
// цвет строки состояния с классом на <html>.
function systemTheme() {
    // Выбор, сделанный до cookie, жил в localStorage: переносим его в cookie один раз.
    let saved = null;
    try { saved = localStorage.getItem('theme'); localStorage.removeItem('theme'); } catch {}
    // Экран мог прийти из кэша воркера с прежней темой — cookie важнее.
    const chosen = chosenTheme();
    if (chosen) setTheme(chosen === 'dark', false);
    else if (saved === 'dark' || saved === 'light') setTheme(saved === 'dark');
    else paintBar();
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => { if (!chosenTheme()) setTheme(e.matches, false); });
    // Turbo подменяет мету ответом сервера — без cookie там светлая; render идёт и у визита, и у морфа.
    document.addEventListener('turbo:render', paintBar);
}

// Шапка знает, что под ней: is-scrolled — страница сдвинута (волосяная линия). Заголовка экрана в шапке нет — там всегда
// логотип (22.10.2026, владелец).
function headerState() {
    const onScroll = () => document.getElementById('header')?.classList.toggle('is-scrolled', scrollTop() > 4);
    onPageScroll(onScroll);
    document.addEventListener('turbo:load', onScroll);
    document.addEventListener('turbo:render', onScroll);
}

// Активная пилюля ленты — в кадре, а не за краем экрана.
function activePillIntoView() {
    const show = () => document.querySelectorAll('.pills [aria-current], .toolbar-pills [aria-current], .cabinet-pills [aria-current], .work-titles [aria-current]').forEach((el) => {
        const row = el.closest('.pills, .toolbar-pills, .cabinet-pills, .work-titles');
        if (!row || row.scrollWidth <= row.clientWidth) return;
        // Уже целиком в кадре (с полем ленты) — не трогаем: иначе первая пилюля прилипает к краю экрана.
        const pad = parseFloat(getComputedStyle(row).paddingLeft) || 0;
        const left = el.offsetLeft - row.offsetLeft, right = left + el.offsetWidth;
        if (left - pad >= row.scrollLeft && right + pad <= row.scrollLeft + row.clientWidth) return;
        row.scrollLeft = left - (row.clientWidth - el.offsetWidth) / 2;
    });
    document.addEventListener('turbo:load', show);
}

// Экран из кэша воркера (запуск с иконки) старше 5 с — тихий replace-morph:
// свежие карточки, csrf-token, темы live; выход — воркер забывает экраны.
// Экран из кэша — прошлой сборки, а свежий уже другой (21.10.2026, Макаров и Тужиков: после выкладки «Предложения» на
// iPhone перезагружались по кругу, а брошенные запросы по 1 МБ висели на сервере до 500): Turbo сам перезагрузил бы
// страницу, но воркер снова отдал бы ту же старую копию — запись свежей не успевала. Копия этой страницы стирается из
// кэша воркера до перезагрузки, и она приходит из сети.
function freshBuild() {
    const mine = document.querySelector('meta[name="build"]')?.content;
    const here = location.pathname + location.search;
    const check = async (event) => {
        const response = event.detail.fetchResponse?.response;
        const url = response ? new URL(response.url) : null;
        if (!url || url.pathname + url.search !== here) return;
        document.removeEventListener('turbo:before-fetch-response', check);
        const build = response.headers.get('X-Build');
        if (!mine || !build || build === mine) return;
        event.preventDefault();
        try {
            for (const key of await caches.keys()) if (key.startsWith('pages-')) await (await caches.open(key)).delete(location.href);
        } catch {}
        reloadWhy('build');
        location.reload();
    };
    document.addEventListener('turbo:before-fetch-response', check);
}

function stalePage() {
    const nav = performance.getEntriesByType('navigation')[0];
    if (['navigate', 'reload'].includes(nav?.type) && !window.reloadStorm) {
        const at = Number(document.querySelector('meta[name="rendered-at"]')?.content || 0) * 1000;
        if (at && Date.now() - at > 5_000 && navigator.onLine !== false) {
            setTimeout(() => { window.xcarRefreshedAt = Date.now(); freshBuild(); Turbo.visit(location.href, { action: 'replace' }); }, 300);
        }
    }
    document.addEventListener('turbo:submit-start', (event) => {
        if (new URL(event.target.action, location.href).pathname === '/logout') navigator.serviceWorker?.controller?.postMessage({ forgetPages: true });
    });
    // На одном телефоне сменился человек (вошёл другой без явного выхода, истекла
    // сессия) — экраны прежнего из кэша воркера не должны всплыть первым кадром.
    const watchUser = () => {
        const id = document.querySelector('meta[name="user-id"]')?.content || '';
        let last = null;
        try { last = localStorage.getItem('xcar.user'); localStorage.setItem('xcar.user', id); } catch { return; }
        if (last !== null && last !== id) navigator.serviceWorker?.controller?.postMessage({ forgetPages: true });
    };
    document.addEventListener('turbo:load', watchUser);
}

// Тактильный отклик: настоящее касание по невидимому switch (.haptic) в табе и
// закладке даёт тик на iPhone; его клик наружу не идёт, а change становится
// кликом по хозяину — таб-бар и Turbo видят обычный тап. Android — короткая вибрация.
// Чипы (.choice) и галочки — сами switch, им ничего пробрасывать не надо.
function haptics() {
    document.addEventListener('click', (event) => {
        if (event.target.matches?.('input.haptic')) event.stopPropagation();
    }, true);
    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('switch')) return;
        if (!/iphone|ipad/i.test(navigator.userAgent)) navigator.vibrate?.(8);
        if (!input.classList.contains('haptic')) return;
        event.stopPropagation();
        input.checked = false;
        const host = input.parentElement;
        host?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    }, true);
}

// Соседи по списку: «предыдущее» уезжает вправо (как назад), «следующее» — влево;
// data-nav-dir читает CSS переходов, снимается после рендера.
function neighbourDirection() {
    document.addEventListener('turbo:click', (event) => {
        const rel = event.target.closest('a[rel="prev"], a[rel="next"]')?.rel;
        if (rel) document.documentElement.dataset.navDir = rel;
    });
    document.addEventListener('turbo:load', () => delete document.documentElement.dataset.navDir);
}

// «Просмотрено» на карточках сайта (`ReadOffer`): открыли предложение с карточки или строки списка — метка встаёт сразу
// (снимок Turbo для «Назад» уже с ней), а сервер узнаёт маячком: страницу и окно строки Turbo часто берёт из предзагрузки,
// и настоящий запрос до сервера не доходит. Закладка, «Подтвердить», стрелки кадров — не открытие.
function offerRead() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        const card = link?.closest('[data-viewed-key]');
        if (!card || card.hasAttribute('data-viewed')) return;
        const n = card.dataset.viewedKey;
        const href = link.getAttribute('href');
        if (!new RegExp(`(/offers/${n}|[?&]peek=${n})([?#&]|$)`).test(href)) return;
        card.setAttribute('data-viewed', '');
        const body = new FormData();
        body.append('_token', csrf());
        navigator.sendBeacon(`/offers/${n}/read`, body);
    }, true);
}

// Уведомление открывает объект сразу (без промежуточной страницы); прочитанность
// уходит маячком, чтобы переход не ждал.
function noticeSeen() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-notice]');
        if (!link) return;
        const body = new FormData();
        body.append('_token', csrf());
        if (link.dataset.noticeVia) body.append('via', link.dataset.noticeVia);
        navigator.sendBeacon(`/account/notifications/${link.dataset.notice}/opened`, body);
        // Прочитано сразу: точка гаснет (в ленте — прозрачной, чтобы текст не съехал), заголовок — обычным.
        link.querySelectorAll('.rounded-full.bg-accent').forEach((dot) => dot.classList.replace('bg-accent', 'bg-transparent'));
        link.querySelector('.flex-1.font-medium')?.classList.remove('font-medium');
    }, true);
}
