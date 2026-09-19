import { Controller } from '@hotwired/stimulus';

// Подпись пальцем на телефоне: холст → PNG в скрытое поле формы. Пустой холст
// поля не заполняет, «Заново» стирает. Рисуется в масштабе экрана, чтобы линия была чёткой.
export default class extends Controller {
    static targets = ['canvas', 'input', 'clear'];

    connect() {
        this.ctx = this.canvasTarget.getContext('2d');
        this.resize();
        this.drawing = false;
        this.dirty = false;
        this.onResize = () => this.resize();
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    resize() {
        const c = this.canvasTarget;
        const ratio = window.devicePixelRatio || 1;
        const rect = c.getBoundingClientRect();
        if (!rect.width) return;
        const data = this.dirty ? c.toDataURL() : null;
        c.width = Math.round(rect.width * ratio);
        c.height = Math.round(rect.height * ratio);
        this.ctx.scale(ratio, ratio);
        this.ctx.lineWidth = 2.2;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = getComputedStyle(c).color;
        if (data) {
            const img = new Image();
            img.onload = () => this.ctx.drawImage(img, 0, 0, rect.width, rect.height);
            img.src = data;
        }
    }

    point(e) {
        const rect = this.canvasTarget.getBoundingClientRect();
        return [e.clientX - rect.left, e.clientY - rect.top];
    }

    start(e) {
        e.preventDefault();
        this.canvasTarget.setPointerCapture(e.pointerId);
        this.drawing = true;
        this.ctx.beginPath();
        this.ctx.moveTo(...this.point(e));
    }

    move(e) {
        if (!this.drawing) return;
        e.preventDefault();
        this.ctx.lineTo(...this.point(e));
        this.ctx.stroke();
        this.dirty = true;
    }

    end(e) {
        if (!this.drawing) return;
        this.drawing = false;
        if (this.dirty) {
            this.inputTarget.value = this.export();
            this.element.classList.add('is-signed');
        }
    }

    // В документ подпись уходит чернилами: на экране линия цветом темы (в тёмной — белая), на бумаге такая невидима.
    export() {
        const c = this.canvasTarget;
        const out = document.createElement('canvas');
        out.width = c.width;
        out.height = c.height;
        const ctx = out.getContext('2d');
        ctx.drawImage(c, 0, 0);
        ctx.globalCompositeOperation = 'source-in';
        ctx.fillStyle = '#1d1d1b';
        ctx.fillRect(0, 0, out.width, out.height);
        return out.toDataURL('image/png');
    }

    clear() {
        const c = this.canvasTarget;
        this.ctx.clearRect(0, 0, c.width, c.height);
        this.dirty = false;
        this.inputTarget.value = '';
        this.element.classList.remove('is-signed');
    }
}
