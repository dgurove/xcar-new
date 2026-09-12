// Оболочка: Turbo делает из серверных страниц приложение, Stimulus отвечает за
// поведение на месте. Контроллеры лежат в ./controllers/<имя>_controller.js
// и подключаются по имени файла: photos_controller.js → data-controller="photos".
import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import { touchPrefetch } from './touch-prefetch';
import { confirmSheet } from './confirm';
import { netGuards } from './net';

const application = Application.start();
window.Stimulus = application;

const controllers = import.meta.glob('./controllers/*_controller.js', { eager: true });
for (const [path, module] of Object.entries(controllers)) {
    const name = path.match(/\/([\w-]+)_controller\.js$/)[1].replace(/_/g, '-');
    application.register(name, module.default);
}

// Полоса — только у долгих визитов: с префетчем на touchstart быстрые укладываются в 400 мс.
Turbo.config.drive.progressBarDelay = 400;
// data-turbo-confirm — своей шторкой, не системным диалогом.
Turbo.config.forms.confirm = (message, form, submitter) => confirmSheet(message, { form, submitter });

// Морф бережёт то, что живёт на клиенте и серверному HTML неизвестно:
// open у диалогов (блок в потоке, открытая шторка), текущий кадр карусели,
// поле с фокусом или набранным текстом (тосты — постоянный узел, морф их обходит).
document.addEventListener('turbo:before-morph-attribute', (event) => {
    const { attributeName } = event.detail;
    const el = event.target;
    if (el instanceof HTMLDialogElement && attributeName === 'open') event.preventDefault();
    if (el.matches('[data-frames-target="frame"]') && ['hidden', 'loading'].includes(attributeName)) event.preventDefault();
    if (el.matches('.card-dot') && attributeName === 'class') event.preventDefault();
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
imageFade();
freshness();
focusInvalid();

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
        if (dialog?.matches(':modal')) dialog.close();
    });
    for (const name of ['turbo:before-render', 'turbo:load', 'turbo:fetch-request-error', 'turbo:before-cache']) document.addEventListener(name, clear);
    document.addEventListener('turbo:submit-start', (event) => {
        const { formSubmission } = event.detail;
        formSubmission.submitter?.setAttribute('aria-busy', 'true');
        const dialog = event.target.closest('dialog[open]');
        if (formSubmission.method === 'get' && dialog?.matches(':modal')) dialog.close();
    });
    document.addEventListener('turbo:submit-end', (event) => event.detail.formSubmission.submitter?.removeAttribute('aria-busy'));
}

// Android: долгое нажатие по фото и хрому не открывает меню Chrome «Открыть в новой вкладке».
document.addEventListener('contextmenu', (event) => {
    if (event.target.closest('.tabbar, .header, .card-media, .action-bar, .photo-strip, [data-gallery-target="strip"]')) event.preventDefault();
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

// Снимок «назад» старше 10 с и возврат из фона дольше минуты — тихий replace:
// морф на месте с сохранением прокрутки, заодно свежие csrf-token и темы live.
// Только на списках и не под руками: открытая шторка или правка формы — не трогаем.
function freshness() {
    const cachedAt = new Map();
    let action = null;
    const quiet = () => document.querySelector('.cards, [data-list]') && !document.querySelector('form[data-dirty], dialog:modal');
    const refresh = () => Turbo.visit(location.href, { action: 'replace' });
    document.addEventListener('turbo:before-cache', () => cachedAt.set(location.href, Date.now()));
    document.addEventListener('turbo:visit', (event) => { action = event.detail.action; });
    document.addEventListener('turbo:load', () => {
        if (action === 'restore' && Date.now() - (cachedAt.get(location.href) ?? Date.now()) > 10_000 && quiet()) refresh();
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
