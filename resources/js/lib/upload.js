// Загрузка файлов по одному — общее у ряда фото (`photos_controller`) и формы отчёта о приёме (`report_controller`):
// отпечаток исходника (`sha`: дубли из писем и архивов отсекает сервер) и очередь по три сразу, пока она идёт — экран не
// гаснет. Очередь пополняется на ходу: выбранное, пока идёт прошлая пачка, встаёт в ту же.

export async function sha(file) {
    try {
        const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
        return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    } catch { return ''; }
}

// pool(run) → { push(...jobs) }: run(job) по `size` сразу; push отдаёт обещание, что выполнится, когда очередь опустеет.
// Ошибку run ловит сам — пул её глотает, чтобы остальные шли.
export function pool(run, size = 3) {
    const queue = [];
    let active = 0;
    let wake = null;
    let idle = null;
    let done = null;

    async function worker() {
        active++;
        try {
            for (let job = queue.shift(); job; job = queue.shift()) {
                try { await run(job); } catch {}
            }
        } finally {
            active--;
            if (!active && !queue.length) {
                wake?.then((w) => w?.release?.()).catch(() => {});
                wake = null;
                const resolve = done;
                idle = done = null;
                resolve?.();
            }
        }
    }

    return {
        push(...jobs) {
            if (!jobs.length && !active) return Promise.resolve();
            queue.push(...jobs);
            if (!wake && navigator.wakeLock) wake = navigator.wakeLock.request('screen').catch(() => null);
            idle ??= new Promise((resolve) => { done = resolve; });
            const promise = idle;
            while (active < size && queue.length) worker();
            return promise;
        },
    };
}
