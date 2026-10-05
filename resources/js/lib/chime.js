// Звук важного уведомления — две ноты на Web Audio, без файла. Браузер даёт звук только после жеста человека:
// контекст создаётся и будится на первом касании или клавише, до этого chime() молчит. Звенит одна вкладка:
// метка уведомления в localStorage, видимая вкладка первой, скрытые ждут и проверяют, не звенела ли она уже.
let context = null;

function wake() {
    try {
        context ??= new (window.AudioContext || window.webkitAudioContext)();
        if (context.state === 'suspended') context.resume();
    } catch {}
}

export function unlockChime() {
    ['pointerdown', 'keydown', 'touchend'].forEach((name) => document.addEventListener(name, wake, { capture: true, passive: true }));
}

function claim(id) {
    try {
        if (localStorage.getItem('xcar.chime') === id) return false;
        localStorage.setItem('xcar.chime', id);
    } catch {}
    return true;
}

function play() {
    if (!context || context.state !== 'running') return;
    const now = context.currentTime;
    const out = context.createGain();
    out.gain.value = 0.22;
    out.connect(context.destination);
    // Ля и ми второй октавы вверх: мягкий «дзынь», как у системных уведомлений.
    [[880, 0], [1318.5, 0.11]].forEach(([freq, at]) => {
        const env = context.createGain();
        env.gain.setValueAtTime(0.0001, now + at);
        env.gain.exponentialRampToValueAtTime(1, now + at + 0.012);
        env.gain.exponentialRampToValueAtTime(0.0001, now + at + 0.7);
        env.connect(out);
        [['sine', 1], ['triangle', 0.18]].forEach(([type, level]) => {
            const osc = context.createOscillator();
            const gain = context.createGain();
            osc.type = type;
            osc.frequency.value = type === 'sine' ? freq : freq * 2;
            gain.gain.value = level;
            osc.connect(gain).connect(env);
            osc.start(now + at);
            osc.stop(now + at + 0.75);
        });
    });
}

export function chime(id) {
    if (document.querySelector('meta[name="banner-sound"]')?.content === 'off') return;
    const hidden = document.visibilityState !== 'visible';
    // Скрытая вкладка с разрешёнными уведомлениями молчит: звенит системное уведомление пуша.
    if (hidden && window.Notification?.permission === 'granted') return;
    setTimeout(() => claim(id) && play(), hidden ? 300 : 0);
}
