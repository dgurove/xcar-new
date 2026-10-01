import { Controller } from '@hotwired/stimulus';
import { liveOpen } from '../live';

// «✨ Распознать» (park/requests/scan): число отмеченных на кнопке; пока файлы читаются (busy) — фрейм
// перечитывается по событию live:scan от задачи, а без хаба (локально, обрыв) — раз в три секунды.
export default class extends Controller {
    static targets = ['count', 'submit'];
    static values = { busy: Boolean, url: String };

    connect() {
        if (!this.busyValue) return;
        this.onScan = () => this.reload();
        document.addEventListener('live:scan', this.onScan);
        this.timer = setInterval(() => { if (!document.hidden && !liveOpen()) this.reload(); }, 3000);
    }

    disconnect() {
        document.removeEventListener('live:scan', this.onScan);
        clearInterval(this.timer);
    }

    count() {
        const n = this.element.querySelectorAll('input[name="ids[]"]:checked').length;
        if (this.hasCountTarget) this.countTarget.textContent = n;
        if (this.hasSubmitTarget) this.submitTarget.disabled = n === 0;
    }

    reload() {
        const frame = this.element.closest('turbo-frame');
        const url = new URL(this.urlValue, location.href).href;
        if (frame.src === url) frame.reload();
        else frame.src = url;
    }
}
