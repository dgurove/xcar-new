// Файл наружу с телефона: лист «Поделиться» с одним файлом — без текста и заголовка (вместе с ними iOS теряет
// файл). Лист Safari открывает только в свежем жесте, а файл ещё надо скачать: просмотрщик отдаёт уже открытый
// файл сразу (`known`), ссылки греют его при касании (`warm`), а не успел — тост «Файл готов» с кнопкой, это
// новый жест. Где листа с файлами нет (компьютер, старый браузер) — обычное скачивание.
const ready = new Map(); // адрес → { file, promise }

const EXT = { 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/heic': 'heic', 'text/plain': 'txt', 'text/csv': 'csv',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx' };

// Имя файла: из Content-Disposition, иначе подпись документа с расширением по типу ответа.
export function fileName(res, name = 'Файл') {
    const cd = res.headers.get('Content-Disposition') || '';
    const star = cd.match(/filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/);
    if (star) { try { return decodeURIComponent(star[1].trim()); } catch {} }
    const plain = cd.match(/filename\s*=\s*"?([^";]+)"?/);
    if (plain) return plain[1].trim();
    const type = (res.headers.get('Content-Type') || '').split(';')[0].trim();
    const ext = EXT[type];
    const base = name.replace(/[\\/:*?"<>|]+/g, ' ').trim() || 'Файл';
    return ext && !base.toLowerCase().endsWith(`.${ext}`) ? `${base}.${ext}` : base;
}

// Файл уже в руках (просмотрщик скачал его, чтобы показать).
export function known(url, file) {
    if (file) ready.set(key(url), { file, promise: Promise.resolve(file) });
}

export function warm(url, name) {
    const k = key(url);
    let hit = ready.get(k);
    if (hit) return hit.promise;
    hit = { file: null, promise: null };
    hit.promise = fetch(url, { credentials: 'same-origin' }).then(async (res) => {
        // Страница вместо файла (вход, ошибка) — не файл.
        if (!res.ok || (res.headers.get('Content-Type') || '').startsWith('text/html')) throw Object.assign(new Error('http'), { status: res.status });
        const blob = await res.blob();
        hit.file = new File([blob], fileName(res, name), { type: blob.type || 'application/octet-stream' });
        return hit.file;
    });
    hit.promise.catch(() => ready.delete(k));
    ready.set(k, hit);
    // Память телефона: держим немного последних.
    if (ready.size > 12) ready.delete(ready.keys().next().value);
    return hit.promise;
}

export const canShareFiles = () => typeof navigator.canShare === 'function' && navigator.canShare({ files: [new File([''], 'x.pdf', { type: 'application/pdf' })] });

export async function shareFile(url, name) {
    if (!canShareFiles()) { download(url); return; }
    const hit = ready.get(key(url));
    let file = hit?.file;
    if (!file) {
        try { file = await warm(url, name); } catch { window.toast?.('Файл не скачался', 'danger'); return; }
    }
    const files = [file];
    if (!navigator.canShare({ files })) { download(url); return; }
    try {
        await navigator.share({ files });
    } catch (e) {
        if (e.name === 'AbortError') return;
        // Пока файл качался, жест истёк — лист откроет новое нажатие.
        if (e.name === 'NotAllowedError') window.toast?.('Файл готов', { action: { label: 'Поделиться', run: () => navigator.share({ files }).catch(() => {}) } });
        else download(url);
    }
}

export function download(url) {
    const a = Object.assign(document.createElement('a'), { href: url, download: '' });
    a.dataset.doc = 'off';
    document.body.append(a);
    a.click();
    a.remove();
}

function key(url) {
    const u = new URL(url, location.href);
    u.searchParams.delete('inline');
    return u.pathname + u.search;
}
