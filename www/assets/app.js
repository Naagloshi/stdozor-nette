import { Application } from '@hotwired/stimulus';
import ToastController from './controllers/toast_controller.js';
import UserMenuController from './controllers/user_menu_controller.js';
import MobileMenuController from './controllers/mobile_menu_controller.js';
import ModalController from './controllers/modal_controller.js';
import UploadController from './controllers/upload_controller.js';
import GalleryController from './controllers/gallery_controller.js';
import DropdownController from './controllers/dropdown_controller.js';
import ConfirmController from './controllers/confirm_controller.js';
import WebauthnRegisterController from './controllers/webauthn_register_controller.js';
import WebauthnLoginController from './controllers/webauthn_login_controller.js';
import Webauthn2faController from './controllers/webauthn_2fa_controller.js';
import ClipboardController from './controllers/clipboard_controller.js';

// Naja — Nette AJAX library (non-blocking import)
import('naja').then(({ default: naja }) => {
    naja.initialize();
    window.naja = naja;
}).catch(e => console.warn('Naja not loaded:', e));

const app = Application.start();
app.register('toast', ToastController);
app.register('user-menu', UserMenuController);
app.register('mobile_menu', MobileMenuController);
app.register('modal', ModalController);
app.register('upload', UploadController);
app.register('gallery', GalleryController);
app.register('dropdown', DropdownController);
app.register('confirm', ConfirmController);
app.register('webauthn-register', WebauthnRegisterController);
app.register('webauthn-login', WebauthnLoginController);
app.register('webauthn-2fa', Webauthn2faController);
app.register('clipboard', ClipboardController);
