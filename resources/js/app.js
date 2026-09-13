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
// open у диалогов (блок в потоке, открытая шторка), точки и подгрузка кадров,
// поле с фокусом или набранным текстом (тосты — постоянный узел, морф их обходит).
document.addEventListener('turbo:before-morph-attribute', (event) => {
    const { attributeName } = event.detail;
    const el = event.target;
    if (el instanceof HTMLDialogElement && attributeName === 'open') event.preventDefault();
    if (el.matches('[data-frames-target="frame"]') && attributeName === 'loading') event.preventDefault();
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
live();
imageFade();
freshness();
focusInvalid();
relaunchScroll();
stalePage();
keyboardInset();
systemTheme();
headerState();
activePillIntoView();
infiniteLists();
nativeBackGesture();
haptics();
neighbourDirection();
noticeSeen();
longPressMenu();
heroTransition();
timerDone();

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
        // Запись шторки в истории снимается до визита, иначе «назад» вернёт на шторку.
        event.preventDefault();
        event.detail.originalEvent.preventDefault();
        const link = event.target.closest('a[href]');
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
    const update = () => {
        const kb = vv.scale > 1.01 ? 0 : Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop));
        document.documentElement.style.setProperty('--kb', `${kb}px`);
        document.documentElement.classList.toggle('kb-open', kb > 100);
    };
    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);
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

// Таймер дошёл до нуля: всё, что помечено data-closes-with-timer, гаснет сразу
// (форма цены, кнопка «Подтвердить»), а через три секунды страница перечитывается
// морфом — сервер к этому времени закрыл приём (CloseBids).
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
    const paint = () => { const dark = document.documentElement.classList.contains('dark'); if (meta()) meta().content = dark ? '#121212' : '#ffffff'; };
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
        const h1 = document.querySelector('#main h1');
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
        if (row && row.scrollWidth > row.clientWidth) row.scrollLeft = el.offsetLeft - row.offsetLeft - (row.clientWidth - el.offsetWidth) / 2;
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
            setTimeout(() => Turbo.visit(location.href, { action: 'replace' }), 300);
        }
    }
    document.addEventListener('turbo:submit-start', (event) => {
        if (new URL(event.target.action, location.href).pathname === '/vyhod') navigator.serviceWorker?.controller?.postMessage({ forgetPages: true });
    });
}

// Лента дотягивается сама (телефон): когда полоса «Назад · n/m · Вперёд» подходит
// к экрану, следующая страница подгружается (ответ обычно уже в руках — префетч),
// её карточки подшиваются к списку перед полосой, полоса заменяется на новую.
// Адрес не меняется; снимок «назад» уносит подшитое с собой.
function infiniteLists() {
    const phone = matchMedia('(max-width: 767px)');
    let observer = null;
    let busy = false;
    const arm = () => {
        observer?.disconnect();
        if (!phone.matches) return;
        const nav = document.querySelector('[data-pages]');
        const next = nav?.querySelector('a[rel="next"]');
        if (!next) return;
        observer = new IntersectionObserver(async ([entry]) => {
            if (!entry.isIntersecting || busy) return;
            busy = true;
            try {
                const entryHit = prefetch(next.href, 60_000);
                const response = (await (entryHit?.response ?? fetch(next.href, { headers: { Accept: 'text/html' } }))).clone();
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                // Список — сосед перед полосой (или перед её обёрткой), и в ответе так же.
                const listBefore = (n) => { let h = n; while (h.parentElement && h.parentElement.children.length === 1) h = h.parentElement; return h.previousElementSibling; };
                const freshNav = doc.querySelector('[data-pages]');
                const list = listBefore(nav);
                const fresh = freshNav ? listBefore(freshNav) : doc.querySelector('.cards');
                if (!list || !fresh) return;
                list.append(...fresh.children);
                if (freshNav) nav.replaceWith(freshNav); else nav.remove();
                busy = false;
                arm();
            } catch { busy = false; }
        }, { rootMargin: '600px 0px' });
        observer.observe(nav);
    };
    document.addEventListener('turbo:load', arm);
}

// Свайп от края в standalone iOS: WebKit сам тянет снимок предыдущей страницы, а
// следом popstate → Turbo играет свой pop — экран уезжал бы дважды. Restore-визит
// сразу после касания у края идёт без анимации: страница просто встаёт на место.
function nativeBackGesture() {
    let edgeAt = 0;
    document.addEventListener('touchstart', (e) => {
        const x = e.touches[0].clientX;
        if (x < 24 || x > innerWidth - 24) edgeAt = Date.now();
    }, { capture: true, passive: true });
    document.addEventListener('turbo:visit', (e) => {
        if (e.detail.action === 'restore' && Date.now() - edgeAt < 1200) document.documentElement.dataset.nativeBack = '1';
    });
    document.addEventListener('turbo:load', () => delete document.documentElement.dataset.nativeBack);
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
        navigator.sendBeacon(`/lk/uvedomleniya/${link.dataset.notice}/otkryto`, body);
        link.closest('.swipe, .block')?.querySelector('.rounded-full.bg-accent-soft')?.remove();
    }, true);
}
