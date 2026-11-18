import shapes from './blank-shapes';

// Битых значков не бывает (18.11.2026, владелец: «красивые заглушки на фотках, даже если нет фоток, чтобы вообще ни при
// каких условиях битых значков не было»). Кадр не открылся (нет файла или его размера, сеть, 404 из кэша воркера) —
// картинка остаётся на месте (её считают контроллеры кадров, ленты, просмотра), а показывает заглушку: силуэт типа ТС
// (`data-cat` — `Cars\Category::icon`, ставит `x-offer.photo`; без него легковой) на мягком фоне, как `x-ui.car-blank`
// там, где фото нет в базе. Любая другая картинка (вложение чата, превью документа) — значок картинки. Аватары и логотипы
// вендоров ведёт `lib/avatar-fallback.js` (инициалы, буква). Заглушка — data-адрес: рисуется и без сети.
// Пока кадр грузится, клетка — ровный фон без силуэта: заглушка одна, смены одной на другую нет (app.css).
const OWN = '.avatar > img, .vendor-logo > img';
const PICTURE = 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm4 3.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM4 17l5-5 4 4 3-3 4 4v1H4Z';
// Машина в кадре: кадры предложения и ТС лежат в медиатеке; остальное — картинка «вообще».
const CAR = '[data-photo], .card-media img, .row-photo img, .photo-cell img, .chat-row-photo img, .chat-car-photo img, .detail-photo img, [data-gallery-target="strip"] img';
const cache = new Map();

// Силуэт — 42 % меньшей стороны коробки при `object-fit: contain`: поле вокруг значка зашито в viewBox.
function uri(key) {
    if (cache.has(key)) return cache.get(key);
    const car = shapes[key];
    const [box, d] = car ? ['-176 -176 608 608', car] : ['-16.5 -16.5 57 57', PICTURE];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${box}"><path fill="#8e8e89" fill-opacity=".55" d="${d}"/></svg>`;
    const out = `data:image/svg+xml,${encodeURIComponent(svg)}`;
    cache.set(key, out);
    return out;
}

function blank(img) {
    if (img.dataset.blank || img.matches(OWN)) return;
    const key = img.matches(CAR) ? (img.dataset.cat || img.closest('[data-cat]')?.dataset.cat || 'cat-car') : 'picture';
    img.dataset.blank = key;
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.classList.remove('is-loading');
    img.classList.add('is-blank');
    img.src = uri(shapes[key] ? key : 'picture');
}

const broken = (img) => img.complete && img.naturalWidth === 0 && img.getAttribute('src') && !img.dataset.blank;

export default function photoFallback() {
    addEventListener('error', (e) => { if (e.target instanceof HTMLImageElement) blank(e.target); }, true);
    // Картинка могла сломаться до подключения скрипта или прийти сломанной из снимка страницы — событие уже прошло.
    const sweep = (root = document) => root.querySelectorAll('img').forEach((img) => broken(img) && blank(img));
    sweep();
    document.addEventListener('turbo:load', () => sweep());
    document.addEventListener('turbo:frame-load', (e) => sweep(e.target));
    // Вторая линия: событие ошибки у ленивого кадра приходит не всегда (кадр вставили потоком, морф вернул прежний адрес
    // уже сломанной картинке) — когда прокрутка встала, проверяем ещё раз. Не чаще раза в такт простоя.
    let idle = 0;
    const later = () => { clearTimeout(idle); idle = setTimeout(() => sweep(), 300); };
    addEventListener('scrollend', later, { capture: true, passive: true });
    document.addEventListener('turbo:render', later);
    document.addEventListener('turbo:before-stream-render', later);
}
