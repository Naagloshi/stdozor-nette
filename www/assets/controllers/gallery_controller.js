import { Controller } from '@hotwired/stimulus';

/**
 * PhotoSwipe Gallery Controller
 *
 * Inicializuje PhotoSwipe galerii pro zobrazení obrázků v lightboxu.
 *
 * Usage:
 * <div data-controller="gallery" data-gallery-selector-value=".gallery-item">
 *   <a href="image1.jpg"
 *      class="gallery-item"
 *      data-pswp-width="1920"
 *      data-pswp-height="1080">
 *     <img src="image1-thumb.jpg" alt="Image 1">
 *   </a>
 *   <a href="image2.jpg" class="gallery-item" ...>...</a>
 * </div>
 */
export default class extends Controller {
    static values = {
        // CSS selector pro galerie items (default: 'a')
        selector: { type: String, default: 'a' }
    };

    connect() {
        // PhotoSwipe musí být načten z CDN (viz @layout.latte)
        if (typeof PhotoSwipeLightbox === 'undefined') {
            console.error('PhotoSwipe library not loaded. Check @layout.latte for CDN script tags.');
            return;
        }

        // Inicializovat PhotoSwipe Lightbox
        this.lightbox = new PhotoSwipeLightbox({
            // Galerie element
            gallery: this.element,

            // Selector pro jednotlivé položky
            children: this.selectorValue,

            // PhotoSwipe instance
            pswpModule: PhotoSwipe,

            // Options
            padding: { top: 50, bottom: 50, left: 100, right: 100 },
            bgOpacity: 0.9,

            // Zoom animation
            showHideAnimationType: 'zoom',

            // UI elements
            closeTitle: 'Zavřít (Esc)',
            zoomTitle: 'Zvětšit/Zmenšit',
            arrowPrevTitle: 'Předchozí (šipka doleva)',
            arrowNextTitle: 'Další (šipka doprava)',
            errorMsg: 'Obrázek nelze načíst',
        });

        // Hook pro dynamické získání velikosti obrázku pokud není v data atributech
        this.lightbox.on('uiRegister', () => {
            this.lightbox.pswp.ui.registerElement({
                name: 'custom-caption',
                order: 9,
                isButton: false,
                appendTo: 'root',
                html: 'Caption text',
                onInit: (el, pswp) => {
                    this.lightbox.pswp.on('change', () => {
                        const currSlideElement = this.lightbox.pswp.currSlide.data.element;
                        let captionHTML = '';

                        if (currSlideElement) {
                            const hiddenCaption = currSlideElement.querySelector('.pswp-caption-content');
                            if (hiddenCaption) {
                                captionHTML = hiddenCaption.innerHTML;
                            } else {
                                const img = currSlideElement.querySelector('img');
                                if (img && img.alt) {
                                    captionHTML = img.alt;
                                }
                            }
                        }

                        el.innerHTML = captionHTML || '';
                    });
                }
            });
        });

        // Inicializovat galerii
        this.lightbox.init();
    }

    disconnect() {
        // Cleanup při odpojení elementu
        if (this.lightbox) {
            this.lightbox.destroy();
            this.lightbox = null;
        }
    }
}
