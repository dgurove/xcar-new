// Сторож петли перезагрузок (08.10.2026, владелец: «на park.xcar.ru тупо бесконечная перезагрузка в pwa»). Что гоняло
// окно по кругу, с компьютера не воспроизвелось: воркер просит перезагрузку после выкладки (sw.js activate), Turbo
// перезагружает страницу целиком, когда сборка в ответе другая, экран из кэша перечитывает себя (stalePage). Какой бы
// ни была причина, четвёртая полная загрузка за 30 с — признак петли: воркер снимается, его кэши стираются, сами окна
// больше не перезагружаются до конца сеанса, а на сервер уходит, что именно перезагружало (лог «pwa: петля»).
const WINDOW = 30_000, LIMIT = 4;

const read = (key, fallback) => { try { return JSON.parse(sessionStorage.getItem(key) ?? 'null') ?? fallback; } catch { return fallback; } };
const write = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch {} };

// Почему перезагрузились — пишет тот, кто перезагружает (why), следующая загрузка забирает.
export function why(reason) {
    write('reload-why', reason);
}

export const storm = (() => {
    if (read('sw-off', false)) return true;
    const now = Date.now();
    const loads = read('reload-loads', []).filter((l) => now - l.at < WINDOW);
    loads.push({ at: now, url: location.pathname + location.search, why: read('reload-why', null), sw: !!navigator.serviceWorker?.controller });
    write('reload-why', null);
    write('reload-loads', loads);
    if (loads.length < LIMIT) return false;
    write('sw-off', true);
    write('reload-loads', []);
    navigator.serviceWorker?.getRegistrations?.().then((list) => list.forEach((r) => r.unregister())).catch(() => {});
    window.caches?.keys().then((keys) => keys.forEach((k) => caches.delete(k))).catch(() => {});
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    fetch('/offline/loop', {
        method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token || '', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ loads, standalone: matchMedia('(display-mode: standalone)').matches || !!navigator.standalone }),
    }).catch(() => {});
    return true;
})();

window.reloadStorm = storm;

// Turbo перезагружает страницу целиком (другая сборка, нет фрейма) — причина в следующую загрузку.
document.addEventListener('turbo:reload', (e) => why(`turbo:${e.detail?.reason ?? '?'}`));
