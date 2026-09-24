// Тактильный отклик (ZIGZAG haptics.ts): navigator.vibrate на Android; на iPhone (iOS 18+) единственный канал —
// щелчок системного переключателя <input type="checkbox" switch>, он даёт тот же тик, что тумблер в «Настройках».
let iosSwitch = null;

function vibrate(pattern) {
    try { navigator.vibrate?.(pattern); } catch {}
}

function iosTick(times) {
    try {
        if (!iosSwitch) {
            if (typeof navigator.vibrate === 'function') return;
            const isIpadAsMac = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
            if (!/iPhone|iPad|iPod/i.test(navigator.userAgent) && !isIpadAsMac) return;
            const probe = document.createElement('input');
            probe.type = 'checkbox';
            probe.setAttribute('switch', '');
            if (!('switch' in probe)) return;
            const label = document.createElement('label');
            label.setAttribute('aria-hidden', 'true');
            label.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;';
            probe.tabIndex = -1;
            label.appendChild(probe);
            document.body.appendChild(label);
            iosSwitch = probe;
        }
        const target = iosSwitch;
        target.click();
        for (let i = 1; i < times; i += 1) setTimeout(() => target.click(), i * 90);
    } catch {}
}

export function haptic(kind) {
    if (kind === 'tap') { vibrate(8); iosTick(1); return; }
    if (kind === 'error') { vibrate([0, 40, 60, 40]); iosTick(3); return; }
    vibrate([0, 20, 40, 20]);
    iosTick(2);
}
