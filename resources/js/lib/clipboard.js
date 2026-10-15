// В буфер — одна дверь (copy_controller, «Поделиться», чат, запрос номеров вендору): тост об успехе или просьба
// скопировать руками, если браузер не дал. true — скопировано.
export async function copyText(text, done = 'В буфере') {
    if (!text) return false;
    try {
        await navigator.clipboard.writeText(text);
        if (done) window.toast?.(done);
        return true;
    } catch {
        window.toast?.('Не получилось скопировать, выделите и скопируйте руками', 'danger');
        return false;
    }
}
