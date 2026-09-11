// Оболочка: Turbo делает из серверных страниц приложение, Stimulus отвечает за
// поведение на месте. Контроллеры лежат в ./controllers/<имя>_controller.js
// и подключаются по имени файла: photos_controller.js → data-controller="photos".
import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import { touchPrefetch } from './touch-prefetch';

const application = Application.start();
window.Stimulus = application;

const controllers = import.meta.glob('./controllers/*_controller.js', { eager: true });
for (const [path, module] of Object.entries(controllers)) {
    const name = path.match(/\/([\w-]+)_controller\.js$/)[1].replace(/_/g, '-');
    application.register(name, module.default);
}

Turbo.setProgressBarDelay(200);
touchPrefetch();
