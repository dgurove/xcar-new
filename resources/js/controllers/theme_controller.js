import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle() {
        const dark = !document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', dark);
        localStorage.setItem('theme', dark ? 'dark' : 'light');
        document.querySelector('meta[name="theme-color"]').content = dark ? '#121212' : '#ffffff';
    }
}
