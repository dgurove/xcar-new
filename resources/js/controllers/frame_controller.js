import { Controller } from '@hotwired/stimulus';

// Письмо в песочнице iframe: высота по содержимому.
export default class extends Controller {
    static targets = ['frame'];

    connect() {
        if (this.frameTarget.contentDocument?.readyState === 'complete') this.fit();
    }

    fit() {
        try {
            const doc = this.frameTarget.contentDocument;
            const height = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0);
            this.frameTarget.style.height = Math.min(Math.max(height + 8, 60), 4000) + 'px';
        } catch {
            this.frameTarget.style.height = '480px';
        }
    }
}
