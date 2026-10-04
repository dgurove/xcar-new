// Оболочка: Turbo делает из серверных страниц приложение, Stimulus отвечает за
// поведение на месте. Контроллеры лежат в ./controllers/<имя>_controller.js
// и подключаются по имени файла: photos_controller.js → data-controller="photos".
import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import { touchPrefetch, prefetch } from './touch-prefetch';
import { confirmSheet } from './confirm';
import { closeSheet } from './sheet';
import { netGuards } from './net';
import { live } from './live';
import { longPressMenu } from './longpress';

// Потоки из ответа карточки строки (x-ui.row-card): тост flash-сообщения и «следующий без цены» (detail_controller).
Turbo.StreamActions.toast = function () {
    window.toast?.(this.dataset.message, window.flashOptions?.(this, document.getElementById('detail') ?? document) ?? this.dataset.kind);
};
Turbo.StreamActions.advance = function () {
    document.dispatchEvent(new CustomEvent('detail:advance'));
};

const application = Application.start();
window.Stimulus = application;

// Контроллеры, нужные только на своих экранах (чат, фото, сканер QR, подпись, редактор…), — отдельными кусками:
// грузятся, когда на странице появился их data-controller. Остальные — в основной сборке, сразу.
const controllerName = (path) => path.match(/\/([\w-]+)_controller\.js$/)[1].replace(/_/g, '-');
const controllers = import.meta.glob(['./controllers/*_controller.js', '!./controllers/{chat,photos,photo_slot,qr_release,signature,share,passkey,vin,draft,editor,combobox,gallery,audience,landing,login,tariff_form,car_text,drop_empty}_controller.js'], { eager: true });
for (const [path, module] of Object.entries(controllers)) application.register(controllerName(path), module.default);
const lazy = new Map(Object.entries(import.meta.glob('./controllers/{chat,photos,photo_slot,qr_release,signature,share,passkey,vin,draft,editor,combobox,gallery,audience,landing,login,tariff_form,car_text,drop_empty}_controller.js')).map(([path, load]) => [controllerName(path), load]));
const loadLazy = (root) => {
    for (const [name, load] of lazy) {
        const selector = `[data-controller~="${name}"]`;
        if (!root.matches?.(selector) && !root.querySelector?.(selector)) continue;
        lazy.delete(name);
        load().then((module) => application.register(name, module.default));
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
Turbo.config.forms.confirm = (message, form, submitter) => confirmSheet(message, { form, submitter });

// Морф бережёт то, что живёт на клиенте и серверному HTML неизвестно:
// open у диалогов (блок в потоке, открытая шторка), точки и подгрузка кадров,
// поле с фокусом или набранным текстом (тосты — постоянный узел, морф их обходит).
document.addEventListener('turbo:before-morph-attribute', (event) => {
    const { attributeName } = event.detail;
    const el = event.target;
    if (el instanceof HTMLDialogElement && attributeName === 'open') event.preventDefault();
    if (el.matches('[data-frames-target="frame"]') && attributeName === 'loading') event.preventDefault();
    if (el.matches('.card-dot') && attributeName === 'class') event.preventDefault();
    // Блок ключа открывает JS; серверный `hidden` при морфе его бы снова спрятал.
    if (el.matches('[data-controller~="passkey"]') && attributeName === 'hidden') event.preventDefault();
    // Режим поиска лупой ставит JS; морф по live-обновлению его бы снял.
    if (el.matches('[data-controller~="live-search"]') && attributeName === 'data-searching') event.preventDefault();
    // Своя проверка формы (formCheck): без novalidate вернулся бы пузырь Safari.
    if (el instanceof HTMLFormElement && ['novalidate', 'data-check'].includes(attributeName)) event.preventDefault();
});
document.addEventListener('turbo:before-morph-element', (event) => {
    const el = event.target;
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
focusInvalid();
formCheck();
enterNext();
relaunchScroll();
stalePage();
keyboardInset();
systemTheme();
headerState();
activePillIntoView();
haptics();
neighbourDirection();
noticeSeen();
longPressMenu();
heroTransition();
timerDone();
transitionsWhenAnimated();
viewportProbe();

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
        event.target.closest('.card, .row, .stat, .pill, .btn, .tab, .header-btn')?.classList.add('is-pending');
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
    for (const name of ['turbo:before-render', 'turbo:load', 'turbo:fetch-request-error', 'turbo:before-cache']) document.addEventListener(name, clear);
    document.addEventListener('turbo:submit-start', (event) => {
        event.detail.formSubmission.submitter?.setAttribute('aria-busy', 'true');
    });
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
        target.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
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
// Только на списках и не под руками: открытая шторка или правка формы — не трогаем.
function freshness() {
    const cachedAt = new Map();
    let action = null;
    const quiet = () => document.querySelector('.cards, [data-list], [data-fresh-on-back]') && !document.querySelector('form[data-dirty], dialog:modal');
    // Метка — чтобы net.js не перечитывал токен отдельным запросом: его принесёт этот же ответ.
    const refresh = () => { window.xcarRefreshedAt = Date.now(); Turbo.visit(location.href, { action: 'replace' }); };
    document.addEventListener('turbo:before-cache', () => cachedAt.set(location.href, Date.now()));
    document.addEventListener('turbo:visit', (event) => { action = event.detail.action; });
    document.addEventListener('turbo:load', () => {
        // Лента уведомлений и список чатов — сразу: точки «не прочитано» в снимке остались от времени до прочтения.
        const stale = document.querySelector('[data-fresh-on-back]') || Date.now() - (cachedAt.get(location.href) ?? Date.now()) > 120_000;
        if (action === 'restore' && stale && quiet()) refresh();
        action = null;
    });
    let hiddenAt = 0;
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
        if (hiddenAt && Date.now() - hiddenAt > 60_000 && quiet()) refresh();
    });
}

// Ошибка валидации приводит к полю: после морфа с preserve-scroll человек стоит у
// «Сохранить», а красное поле — вне экрана.
function focusInvalid() {
    document.addEventListener('turbo:load', () => {
        const bad = document.querySelector('.field-invalid .field-input');
        if (!bad) return;
        bad.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        bad.focus({ preventScroll: true });
    });
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
        if (!el.getClientRects().length) { window.toast?.(message, 'danger'); return; }
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
        el.focus({ preventScroll: true });
        el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    };

    // Раньше Turbo и обработчиков формы: неверная форма не отправляется вовсе, как с проверкой браузера.
    window.addEventListener('submit', (event) => {
        const form = event.target;
        if (!own(form) || event.submitter?.formNoValidate) return;
        for (const el of [...shown.keys()]) if (el.form === form || !el.isConnected) clear(el);
        if (form.checkValidity()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const bad = [...form.elements].find((el) => el.willValidate && !el.validity.valid);
        if (bad) show(bad);
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
        next.focus();
    });
}

// Приложение выгрузили из фона и открыли заново — страница грузится с нуля, а
// человек стоял на тридцатой карточке. Позиция пишется при уходе в фон и
// возвращается только на полной загрузке того же адреса, если ей меньше получаса.
function relaunchScroll() {
    const key = () => 'scroll:' + location.href;
    const save = () => { try { localStorage.setItem(key(), JSON.stringify({ y: scrollY, at: Date.now() })); } catch {} };
    document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && save());
    window.addEventListener('pagehide', save);
    const nav = performance.getEntriesByType('navigation')[0];
    if (!['navigate', 'reload'].includes(nav?.type)) return;
    try {
        const saved = JSON.parse(localStorage.getItem(key()) || 'null');
        if (saved && Date.now() - saved.at < 30 * 60 * 1000 && saved.y > 0) requestAnimationFrame(() => scrollTo(0, saved.y));
        for (const k of Object.keys(localStorage)) {
            if (!k.startsWith('scroll:') && !k.startsWith('draft:')) continue;
            const at = JSON.parse(localStorage.getItem(k) || '{}').at ?? 0;
            if (Date.now() - at > 24 * 3600 * 1000) localStorage.removeItem(k);
        }
    } catch {}
}

// Клавиатура iOS не уменьшает layout viewport: 100dvh и fixed-низ остаются под ней.
// Высота перекрытия — в --kb на <html>, класс kb-open; полоса действий и шторка
// садятся на клавиатуру (app.css). Android с interactive-widget=resizes-content
// ужимает viewport сам — там поправка выходит нулевой.
function keyboardInset() {
    const vv = window.visualViewport;
    if (!vv) return;
    const field = () => document.activeElement?.matches('input, textarea, select, [contenteditable="true"], trix-editor');
    const update = () => {
        // Без поля в фокусе клавиатуры нет — разница высот от резины или зума не считается за неё.
        const kb = vv.scale > 1.01 || !field() ? 0 : Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop));
        document.documentElement.style.setProperty('--kb', `${kb}px`);
        document.documentElement.classList.toggle('kb-open', kb > 100);
    };
    // iOS: поле с клавиатурой пропало из DOM, не потеряв фокус (ответ формы подменил карточка строки, морф
    // страницы), — клавиатура уходит, а viewport остаётся ужатым: таб-бар висит посреди экрана, строки под ним
    // не рисуются. Снимаем фокус до подмены — клавиатура закрывается штатно; и после закрытия клавиатуры
    // тянем прокрутку на месте — WebKit по ней пересчитывает viewport.
    const blurInside = (root) => {
        const el = document.activeElement;
        if (el && el !== document.body && root?.contains(el) && field()) el.blur();
    };
    document.addEventListener('turbo:submit-start', (e) => blurInside(e.target));
    document.addEventListener('turbo:before-frame-render', (e) => blurInside(e.target));
    // Морф поле сохраняет вместе с фокусом (чат, поиск) — только полная подмена страницы.
    document.addEventListener('turbo:before-render', (e) => { if (e.detail.renderMethod !== 'morph') blurInside(document.body); });
    let open = false;
    const settle = () => {
        const was = open;
        update();
        open = document.documentElement.classList.contains('kb-open');
        if (was && !open) setTimeout(() => scrollTo(scrollX, scrollY), 80);
    };
    vv.addEventListener('resize', settle);
    vv.addEventListener('scroll', update);
    document.addEventListener('focusout', () => requestAnimationFrame(settle));
    update();
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

// Временный замер для таб-бара на iPhone (убрать после проверки): ?vp=1 включает плашку, ?vp=0 — выключает.
// Пишет высоты viewport и низ таб-бара, а за последний переход — разброс низа бара и число view transitions.
function viewportProbe() {
    const flag = new URLSearchParams(location.search).get('vp');
    try { if (flag === '1') localStorage.setItem('vp', '1'); if (flag === '0') localStorage.removeItem('vp'); } catch {}
    let on = false;
    try { on = localStorage.getItem('vp') === '1'; } catch {}
    if (!on) return;
    const probe = (css) => {
        const el = document.createElement('div');
        el.style.cssText = `position:fixed;top:0;left:0;width:0;visibility:hidden;pointer-events:none;${css}`;
        document.documentElement.append(el);
        return el;
    };
    const lvh = probe('height:100lvh'), dvh = probe('height:100dvh'), svh = probe('height:100svh');
    const safe = probe('padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom)');
    const box = document.createElement('pre');
    box.style.cssText = 'position:fixed;z-index:9999;left:4px;top:calc(env(safe-area-inset-top) + 4px);margin:0;padding:4px 6px;'
        + 'font:10px/1.3 ui-monospace,monospace;color:#fff;background:rgb(0 0 0 / .75);border-radius:6px;pointer-events:none;white-space:pre';
    document.documentElement.append(box);
    let vt = 0;
    const start = document.startViewTransition?.bind(document);
    if (start) document.startViewTransition = (...args) => { vt++; return start(...args); };
    let span = null, until = 0;
    const r = (n) => Math.round(n * 10) / 10;
    const frame = () => {
        const bar = document.getElementById('tabbar')?.getBoundingClientRect().bottom ?? NaN;
        if (span) { span.min = Math.min(span.min, bar); span.max = Math.max(span.max, bar); }
        const vv = window.visualViewport, cs = getComputedStyle(safe);
        box.textContent = [
            `${matchMedia('(display-mode: standalone)').matches ? 'standalone' : 'browser'} ${navigator.userAgent.match(/OS (\d+[_\d]*)/)?.[1] ?? ''}`,
            `screen ${screen.height}  inner ${innerHeight}  client ${document.documentElement.clientHeight}`,
            `vv ${r(vv?.height ?? 0)} +${r(vv?.offsetTop ?? 0)}  lvh ${lvh.offsetHeight} dvh ${dvh.offsetHeight} svh ${svh.offsetHeight}`,
            `safe ${cs.paddingTop} / ${cs.paddingBottom}  bar.bottom ${r(bar)}`,
            span ? `переход: bar ${r(span.min)}…${r(span.max)}  vt ${span.vt}→${vt}` : `vt ${vt}`,
        ].join('\n');
        if (performance.now() < until) requestAnimationFrame(frame);
    };
    const watch = (ms) => { until = Math.max(until, performance.now() + ms); requestAnimationFrame(frame); };
    document.addEventListener('turbo:visit', () => { span = { min: Infinity, max: -Infinity, vt }; watch(10000); });
    document.addEventListener('turbo:load', () => { until = performance.now() + 600; watch(0); });
    addEventListener('scroll', () => watch(100), { passive: true });
    window.visualViewport?.addEventListener('resize', () => watch(300));
    watch(600);
}

// Таймер дошёл до нуля: всё, что помечено data-closes-with-timer, гаснет сразу
// (форма цены, кнопка «Подтвердить»), а через три секунды страница перечитывается
// морфом — сервер решает по времени (bidsOpen), состояния «закрыт» нет.
function timerDone() {
    let planned = null;
    document.addEventListener('timer:done', () => {
        document.querySelectorAll('[data-closes-with-timer]').forEach((el) => {
            if (el.matches('button, a')) { el.classList.add('btn-quiet'); el.classList.remove('btn-accent'); el.setAttribute('aria-disabled', 'true'); el.style.pointerEvents = 'none'; }
            else el.hidden = true;
        });
        document.querySelectorAll('[data-shows-when-closed]').forEach((el) => { el.hidden = false; });
        clearTimeout(planned);
        planned = setTimeout(() => {
            if (document.querySelector('form[data-dirty], dialog:modal')) return;
            Turbo.visit(location.href, { action: 'replace' });
        }, 3000);
    });
}

// Пока тема не выбрана руками (cookie нет) — следуем за системой, в том числе на лету;
// после переходов Turbo сверяем мету цвета полосы с классом на <html>.
function systemTheme() {
    const meta = () => document.querySelector('meta[name="theme-color"]');
    const paint = () => { const dark = document.documentElement.classList.contains('dark'); if (meta()) meta().content = dark ? '#161616' : '#ffffff'; };
    // Экран мог прийти из кэша воркера с прежней темой — cookie важнее.
    const chosen = document.cookie.match(/(?:^|; )theme=(dark|light)/)?.[1];
    if (chosen) { document.documentElement.classList.toggle('dark', chosen === 'dark'); paint(); }
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (document.cookie.includes('theme=')) return;
        document.documentElement.classList.toggle('dark', e.matches);
        paint();
    });
    document.addEventListener('turbo:render', paint);
    document.addEventListener('turbo:load', paint);
}

// Шапка знает, что под ней: is-scrolled — страница сдвинута (линия), is-past-title —
// h1 ушёл под шапку (заголовок собирается в центр). Только на телефоне.
function headerState() {
    let observer = null;
    const header = () => document.getElementById('header');
    const onScroll = () => header()?.classList.toggle('is-scrolled', scrollY > 4);
    addEventListener('scroll', onScroll, { passive: true });
    const watch = () => {
        observer?.disconnect();
        onScroll();
        // Якорь — h1, а где его нет (кабинет: разделом называется пилюля) — помеченный элемент.
        // Спрятанный на телефоне h1 (шелл, phone-heading=false) не в счёт — берётся видимый.
        const h1 = [...document.querySelectorAll('#main h1, #main [data-title-anchor]')].find((el) => el.offsetParent !== null);
        const h = header();
        if (!h1 || !h) { h?.classList.remove('is-past-title'); return; }
        observer = new IntersectionObserver(([entry]) => {
            h.classList.toggle('is-past-title', !entry.isIntersecting && entry.boundingClientRect.top < 0);
        }, { rootMargin: `-${h.offsetHeight}px 0px 0px 0px` });
        observer.observe(h1);
    };
    document.addEventListener('turbo:load', watch);
    document.addEventListener('turbo:render', watch);
}

// Активная пилюля ленты — в кадре, а не за краем экрана.
function activePillIntoView() {
    const show = () => document.querySelectorAll('.pills [aria-current], .toolbar-pills [aria-current], .cabinet-pills [aria-current]').forEach((el) => {
        const row = el.closest('.pills, .toolbar-pills, .cabinet-pills');
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
function stalePage() {
    const nav = performance.getEntriesByType('navigation')[0];
    if (['navigate', 'reload'].includes(nav?.type)) {
        const at = Number(document.querySelector('meta[name="rendered-at"]')?.content || 0) * 1000;
        if (at && Date.now() - at > 5_000 && navigator.onLine !== false) {
            setTimeout(() => { window.xcarRefreshedAt = Date.now(); Turbo.visit(location.href, { action: 'replace' }); }, 300);
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

// Уведомление открывает объект сразу (без промежуточной страницы); прочитанность
// уходит маячком, чтобы переход не ждал.
function noticeSeen() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-notice]');
        if (!link) return;
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        navigator.sendBeacon(`/account/notifications/${link.dataset.notice}/opened`, body);
        // Прочитано сразу: точка гаснет (в ленте — прозрачной, чтобы текст не съехал), заголовок — обычным.
        link.querySelectorAll('.rounded-full.bg-accent').forEach((dot) => dot.classList.replace('bg-accent', 'bg-transparent'));
        link.querySelector('.flex-1.font-medium')?.classList.remove('font-medium');
    }, true);
}
