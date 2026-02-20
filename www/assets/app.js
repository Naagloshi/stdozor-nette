import { Application } from '@hotwired/stimulus';
import ToastController from './controllers/toast_controller.js';
import UserMenuController from './controllers/user_menu_controller.js';
import MobileMenuController from './controllers/mobile_menu_controller.js';
import ModalController from './controllers/modal_controller.js';

const app = Application.start();
app.register('toast', ToastController);
app.register('user-menu', UserMenuController);
app.register('mobile_menu', MobileMenuController);
app.register('modal', ModalController);
