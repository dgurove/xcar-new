// В буфер — одна дверь (copy_controller, «Поделиться», чат, запрос номеров вендору): тост об успехе или просьба
// скопировать руками, если браузер не дал. true — скопировано.
// 18.10.2026 (Татьяна: «не копируется ссылка исполнителю»): Clipboard API есть не везде и не везде разрешён — встроенные
// браузеры Telegram и WhatsApp, окно без фокуса, http без защищённого контекста. Тогда — старый путь: скрытое поле и
// execCommand('copy') в том же нажатии; поле — в открытой модальной шторке, если она есть (вне верхнего слоя выделение
// не встаёт).
export async function copyText(text, done = 'В буфере') {
    if (!text) return false;
    let ok = false;
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch {
            ok = false;
        }
    }
    if (!ok) ok = legacyCopy(text);
    if (ok) {
        if (done) window.toast?.(done);
    } else {
        window.toast?.('Не получилось скопировать, выделите и скопируйте руками', 'danger');
    }
    return ok;
}

function legacyCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;font-size:16px';
    const host = [...document.querySelectorAll('dialog[open]')].pop() ?? document.body;
    const active = document.activeElement;
    host.append(area);
    area.focus({ preventScroll: true });
    area.select();
    area.setSelectionRange(0, text.length);
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    area.remove();
    active?.focus?.({ preventScroll: true });
    return ok;
}
