<?php
/**
 * Plugin Name: BSBT – Checkout Summary (FINAL & UNIFIED CODE V12 - GALLERY SLUG FIX + CANCELLATION + 2x2 LAYOUT)
 * Description: Dynamic summary card for Booking Checkout page (MotoPress Hotel Booking) using Session Storage to carry data, guaranteeing display of Apartment Type and Guests, and removing Reservation ID display.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===========================================
// ХУКИ ДЛЯ AJAX И ШОРТКОДА
// ===========================================

add_shortcode( 'bsbt_checkout_summary', 'bsbt_checkout_summary_render' );
add_action( 'wp_ajax_bsbt_get_room_gallery_by_slug', 'bsbt_get_room_gallery_by_slug' );
add_action( 'wp_ajax_nopriv_bsbt_get_room_gallery_by_slug', 'bsbt_get_room_gallery_by_slug' );

// AJAX FUNCTION REMAINS UNCHANGED (Code integrity check)
function bsbt_get_room_gallery_by_slug() {
    if ( empty( $_POST['slug'] ) ) { wp_die(); }
    $slug = sanitize_title( wp_unslash( $_POST['slug'] ) );
    $room_type = get_page_by_path( $slug, OBJECT, 'mphb_room_type' );
    if ( ! $room_type ) { wp_die(); }
    $room_type_id = $room_type->ID;
    $image_ids = array();
    if ( has_post_thumbnail( $room_type_id ) ) {
        $image_ids[] = get_post_thumbnail_id( $room_type_id );
    }
    $gallery_meta = get_post_meta( $room_type_id, 'mphb_gallery', true );
    if ( empty( $gallery_meta ) ) {
        $gallery_meta = get_post_meta( $room_type_id, '_mphb_gallery', true );
    }
    if ( ! empty( $gallery_meta ) ) {
        if ( is_string( $gallery_meta ) ) {
            $extra_ids = array_filter( array_map( 'intval', explode( ',', $gallery_meta ) ) );
        } elseif ( is_array( $gallery_meta ) ) {
            $extra_ids = array_map( 'intval', $gallery_meta );
        } else {
            $extra_ids = array();
        }
        $image_ids = array_unique( array_merge( $image_ids, $extra_ids ) );
    }
    $image_ids = array_slice( $image_ids, 0, 12 );
    if ( empty( $image_ids ) ) { wp_die(); }
    ob_start();
    ?>
    <button type="button"
            class="bsbt-gallery-arrow bsbt-gallery-arrow-prev bsbt-gallery-arrow--disabled"
            aria-label="Previous photos">
        &lt;
    </button>
    <div class="bsbt-summary-photo-strip">
        <?php foreach ( $image_ids as $img_id ) : ?>
            <?php $full_url = wp_get_attachment_image_url( $img_id, 'large' ); ?>
            <div class="bsbt-summary-photo">
                <?php
                echo wp_get_attachment_image(
                    $img_id,
                    'medium',
                    false,
                    array(
                        'class'     => 'bsbt-summary-photo-img',
                        'loading'   => 'lazy',
                        'data-full' => $full_url ? esc_url( $full_url ) : '',
                    )
                );
                ?>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button"
            class="bsbt-gallery-arrow bsbt-gallery-arrow-next"
            aria-label="Next photos">
        &gt;
    </button>
    <?php
    echo ob_get_clean();
    wp_die();
}

// ===========================================
// РЕНДЕР КАРТОЧКИ: ГЛАВНАЯ ФУНКЦИЯ
// ===========================================

function bsbt_checkout_summary_render() {
    global $wpdb;

    $ajax_url        = admin_url( 'admin-ajax.php' );
    $booking_id      = 0;
    $room_type_post  = null;
    $adults          = 0;
    $children        = 0;
    $total_price     = '—';
    $total_price_raw = ''; 
    $check_in_raw    = '';
    $check_out_raw   = '';
    $guests_str      = '—';
    $room_type_id    = 0; 
    
    $is_step_checkout = ! isset( $_GET['step'] ); 
    $is_step_booking  = isset( $_GET['step'] ) && $_GET['step'] === 'booking'; 
    
    // --- 1. ШАГ 1: ПОЛУЧАЕМ ДАННЫЕ ИЗ $_REQUEST
    if ( $is_step_checkout ) {
        $check_in_raw  = isset( $_REQUEST['mphb_check_in_date'] ) ? sanitize_text_field( $_REQUEST['mphb_check_in_date'] ) : '';
        $check_out_raw = isset( $_REQUEST['mphb_check_out_date'] ) ? sanitize_text_field( $_REQUEST['mphb_check_out_date'] ) : '';

        if ( isset( $_REQUEST['mphb_room_details'] ) && is_array( $_REQUEST['mphb_room_details'] ) ) {
            $first_details = reset( $_REQUEST['mphb_room_details'] );
            if ( isset( $first_details['room_type_id'] ) ) {
                $room_type_id = absint( $first_details['room_type_id'] );
            }
            $adults   = isset( $first_details['adults'] ) ? (int) $first_details['adults'] : 0;
            $children = isset( $first_details['children'] ) ? (int) $first_details['children'] : 0;
        }
    }

    // --- 2. ШАГ 2: ПОПЫТКА ВОССТАНОВЛЕНИЯ ДАННЫХ ИЗ БАЗЫ (ТОЛЬКО ДАТЫ)
    if ( $is_step_booking ) {
        if ( isset( $_GET['booking_id'] ) ) {
            $booking_id = absint( $_GET['booking_id'] );
        }
        
        if ( $booking_id ) {
            $check_in_raw  = get_post_meta( $booking_id, 'mphb_check_in_date', true );
            $check_out_raw = get_post_meta( $booking_id, 'mphb_check_out_date', true );
            
            $room_details = get_post_meta( $booking_id, 'mphb_room_details', true );
            if ( ! empty( $room_details ) ) {
                 $first_room_booking = reset( $room_details );
                 $room_type_id       = isset($first_room_booking['room_type_id']) ? absint($first_room_booking['room_type_id']) : 0;
                 $adults             = isset($first_room_booking['adults']) ? (int) $first_room_booking['adults'] : 0;
                 $children           = isset($first_room_booking['children']) ? (int) $first_room_booking['children'] : 0;
            }
        }
    }
    
    // --- 3. РАБОТА С ТИПОМ НОМЕРА, ДАТАМИ И ГОСТЯМИ (Форматирование)
    if ( $room_type_id ) {
        $room_type_post = get_post( $room_type_id );
    }

    $check_in  = $check_in_raw  ? DateTime::createFromFormat( 'Y-m-d', $check_in_raw )  : null;
    $check_out = $check_out_raw ? DateTime::createFromFormat( 'Y-m-d', $check_out_raw ) : null;

    $check_in_str  = $check_in  ? $check_in->format( 'd M Y' ) : '—';
    $check_out_str = $check_out ? $check_out->format( 'd M Y' ) : '—';

    $nights = '—';
    if ( $check_in && $check_out ) {
        $diff   = $check_in->diff( $check_out );
        $nights = $diff->days;
    }
    
    if ( $adults > 0 || $children > 0 ) {
        $parts = array();
        if ( $adults > 0 ) {
            $parts[] = $adults . ' adult' . ( $adults > 1 ? 's' : '' );
        }
        if ( $children > 0 ) {
            $parts[] = $children . ' child' . ( $children > 1 ? 'ren' : '' );
        }
        $guests_str = implode( ', ', $parts );
    }

    // --- 4. ДОБАВЛЕНИЕ СКРЫТЫХ ПОЛЕЙ
    $apartment_title = $room_type_post ? esc_html( $room_type_post->post_title ) : '—'; 
    $apartment_id    = $room_type_post ? absint( $room_type_post->ID ) : 0;
    $total_guests    = $adults + $children;

    // --- 4.1. ТИП ПОЛИТИКИ ОТМЕНЫ (ИЗ META _bsbt_cancel_policy_type)
    $policy_type = $room_type_id ? get_post_meta( $room_type_id, '_bsbt_cancel_policy_type', true ) : '';
    if ( empty( $policy_type ) ) {
        $policy_type = 'nonref';
    }

    $policy_label = ( $policy_type === 'standard' )
        ? 'Free cancellation up to 30 days before arrival'
        : 'Non-refundable booking';

    echo '
        <input type="hidden" id="booking_id_field_hidden" name="booking_id_field" value="' . esc_attr( $booking_id ) . '">
        <input type="hidden" id="booking_guests_hidden" name="booking_guests" value="' . esc_attr( $total_guests ) . '">
        <input type="hidden" id="apartment_id_hidden" name="apartment_id" value="' . esc_attr( $apartment_id ) . '">
        <input type="hidden" id="apartment_title_hidden" name="apartment_title" value="' . esc_attr( $apartment_title ) . '">
        
        <input type="hidden" id="booking_price_field" name="booking_price" value="">
    ';

    // --- 5. РЕНДЕРИНГ HTML КАРТОЧКИ
    ob_start();
    ?>
    <div class="bsbt-checkout-summary"
        data-booking-id="<?php echo esc_attr( $booking_id ?: '' ); ?>"
        data-room-slug="<?php echo esc_attr( $room_type_post ? $room_type_post->post_name : '' ); ?>"
        data-is-step-booking="<?php echo $is_step_booking ? 'true' : 'false'; ?>"> 
        <div class="bsbt-checkout-summary-card">
            <div class="bsbt-summary-photo-gallery"></div>
            <h3 class="bsbt-summary-title">Your reservation</h3>
            
            <p class="bsbt-summary-row bsbt-summary-apartment">
                <span class="bsbt-summary-label">Apartment</span><br>
                <a href="<?php echo $room_type_post ? esc_url( get_permalink( $room_type_post->ID ) ) : '#'; ?>"
                    target="_blank" class="bsbt-summary-apartment-link">
                    <strong class="bsbt-summary-apartment-title">
                        <?php echo esc_html( $apartment_title ); ?>
                    </strong>
                </a>
            </p>

            <div class="bsbt-summary-row-group">
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Check-in</span><br>
                    <strong class="bsbt-summary-checkin"><?php echo esc_html( $check_in_str ); ?></strong>
                </p>
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Check-out</span><br>
                    <strong class="bsbt-summary-checkout"><?php echo esc_html( $check_out_str ); ?></strong>
                </p>
            </div>

            <div class="bsbt-summary-row-group">
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Nights</span><br>
                    <strong class="bsbt-summary-nights"><?php echo esc_html( $nights ); ?></strong>
                </p>
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Guests</span><br>
                    <strong class="bsbt-summary-guests"><?php echo esc_html( $guests_str ); ?></strong>
                </p>
            </div>

            <div class="bsbt-summary-row bsbt-summary-total-row">
                <span class="bsbt-summary-label">Total</span>
                <span class="bsbt-summary-total-amount"><?php echo esc_html( $total_price ); ?></span>
            </div>

            <p class="bsbt-summary-row bsbt-summary-policy">
                <span class="bsbt-summary-label">Cancellation</span><br>
                <span class="bsbt-summary-policy-text">
                    <?php echo esc_html( $policy_label ); ?>
                </span>
            </p>

            <p class="bsbt-summary-note">
                All prices include 7% VAT and City Tax where applicable<br>
                No charge will be made at this step. Your card will be temporarily authorized to secure the booking. 
    Payment is only captured after confirmation.
            </p>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var summaryCard = document.querySelector('.bsbt-checkout-summary');
        var isStepBooking = summaryCard.getAttribute('data-is-step-booking') === 'true';
        var SESSION_KEY = 'bsbt_booking_summary_data';

        var saveSummaryData; // Defined later

        // === 3) APARTMENT TITLE + LINK + GALLERY
        function bsbtEnsureLightbox() {
            var existing = document.querySelector('.bsbt-photo-lightbox');
            if (existing) { return existing; }
            var overlay = document.createElement('div');
            overlay.className = 'bsbt-photo-lightbox';
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.75)';
            overlay.style.display = 'none';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999';
            var inner = document.createElement('div');
            inner.className = 'bsbt-photo-lightbox-inner';
            inner.style.maxWidth = '90%';
            inner.style.maxHeight = '90%';
            var img = document.createElement('img');
            img.className = 'bsbt-photo-lightbox-img';
            img.style.maxWidth = '100%';
            img.style.maxHeight = '100%';
            img.style.display = 'block';
            img.style.borderRadius = '10px';
            img.style.boxShadow = '0 10px 30px rgba(0,0,0,0.6)';
            inner.appendChild(img);
            overlay.appendChild(inner);
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    img.src = '';
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display === 'flex') {
                    overlay.style.display = 'none';
                    img.src = '';
                }
            });
            return overlay;
        }
        
        // **********************************************
        // ********** KEY FIX APPLIED HERE **************
        // **********************************************
        function initApartmentAndGallery() {
            var slug = summaryCard.getAttribute('data-room-slug');

            // FIX: На Шаге 2, если slug пуст, принудительно берем его из сессии
            if (!slug && isStepBooking) {
                try {
                    var storedData = sessionStorage.getItem(SESSION_KEY);
                    if (storedData) {
                       var data = JSON.parse(storedData);
                       slug = data.roomSlug;
                       if (slug) {
                           // ПРИНУДИТЕЛЬНО УСТАНАВЛИВАЕМ SLUG В DOM
                           summaryCard.setAttribute('data-room-slug', slug); 
                           console.log('BSBT Gallery: Recovered slug from session:', slug);
                       } else {
                           console.warn('BSBT Gallery: Slug missing from session data.');
                           return; // Выходим, если slug не найден даже в сессии
                       }
                    }
                } catch(e) { /* silent fail */ }
            }
            
            // Фолбэк для Шага 1 (если PHP не сработал): пытаемся получить slug из ссылки
            if (!slug) {
                var srcLink = document.querySelector('.mphb-room-type-title a');
                if (!srcLink) return;
                var href = srcLink.getAttribute('href') || '';
                var text = (srcLink.textContent || '').trim();
                var sumLink = summaryCard.querySelector('.bsbt-summary-apartment-link');
                var sumTitle = summaryCard.querySelector('.bsbt-summary-apartment-title');
                if (sumLink && href) { sumLink.setAttribute('href', href); }
                if (sumTitle && text) { sumTitle.textContent = text; }
                try {
                    var u = new URL(href, window.location.origin);
                    var parts = u.pathname.split('/').filter(Boolean);
                    if (parts.length) slug = parts[parts.length - 1];
                } catch (e) { slug = ''; }
                if (slug) {
                    summaryCard.setAttribute('data-room-slug', slug); 
                    // Если нашли на Шаге 1, сохраняем, чтобы он был в сессии
                    if (typeof saveSummaryData === 'function' && !isStepBooking) {
                        saveSummaryData();
                    }
                } else {
                     return;
                }
            }


            var gallery = summaryCard.querySelector('.bsbt-summary-photo-gallery');
            if (!gallery) return;
            
            var formData = new FormData();
            formData.append('action', 'bsbt_get_room_gallery_by_slug');
            formData.append('slug', slug);
            
            gallery.innerHTML = '<div style="text-align:center; padding: 20px;">Loading photos...</div>'; // Placeholder
            
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                if (!html || !html.trim() || html.includes('wp_die')) {
                    gallery.innerHTML = '<div style="text-align:center; padding: 20px; color: #777;">Photos not available.</div>';
                    return;
                }
                gallery.innerHTML = html;
                initGalleryArrows(gallery);
                initGalleryLightbox(gallery);
                console.log('BSBT Gallery: Gallery successfully loaded for slug:', slug);
            })
            .catch(function (error) { 
                console.error('BSBT Gallery AJAX error:', error); 
                gallery.innerHTML = '<div style="text-align:center; padding: 20px; color: red;">Error loading photos.</div>';
            });
            
            function initGalleryArrows(galleryRoot) {
                var strip = galleryRoot.querySelector('.bsbt-summary-photo-strip');
                var prevBtn = galleryRoot.querySelector('.bsbt-gallery-arrow-prev');
                var nextBtn = galleryRoot.querySelector('.bsbt-gallery-arrow-next');
                if (!strip) return;
                function updateArrows() {
                    var maxScroll = strip.scrollWidth - strip.clientWidth - 1;
                    if (maxScroll < 0) maxScroll = 0;
                    var pos = strip.scrollLeft;
                    if (prevBtn) { prevBtn.classList.toggle('bsbt-gallery-arrow--disabled', pos <= 0); }
                    if (nextBtn) { nextBtn.classList.toggle('bsbt-gallery-arrow--disabled', pos >= maxScroll); }
                }
                updateArrows();
                if (prevBtn) {
                    prevBtn.addEventListener('click', function () {
                        if (prevBtn.classList.contains('bsbt-gallery-arrow--disabled')) return;
                        var step = strip.clientWidth;
                        strip.scrollBy({ left: -step, behavior: 'smooth' });
                        setTimeout(updateArrows, 350);
                    });
                }
                if (nextBtn) {
                    nextBtn.addEventListener('click', function () {
                        if (nextBtn.classList.contains('bsbt-gallery-arrow--disabled')) return;
                        var step = strip.clientWidth;
                        strip.scrollBy({ left: step, behavior: 'smooth' });
                        setTimeout(updateArrows, 350);
                    });
                }
                strip.addEventListener('scroll', updateArrows);
            }
            function initGalleryLightbox(galleryRoot) {
                var overlay = bsbtEnsureLightbox();
                var imgLarge = overlay.querySelector('.bsbt-photo-lightbox-img');
                if (!imgLarge) return;
                var thumbs = galleryRoot.querySelectorAll('.bsbt-summary-photo-img');
                thumbs.forEach(function (thumb) {
                    thumb.style.cursor = 'pointer';
                    thumb.addEventListener('click', function () {
                        var full = thumb.getAttribute('data-full') || thumb.getAttribute('src');
                        if (!full) return;
                        imgLarge.src = full;
                        overlay.style.display = 'flex';
                    });
                });
            }
        }
        // **********************************************
        
        // ===============================================
        // --- 1) TOTAL PRICE + SESSION LOGIC ---
        // ===============================================

        (function sessionAndPriceSync() {
            var targetField = document.getElementById('booking_price_field');
            var visiblePriceElement = summaryCard.querySelector('.bsbt-summary-total-amount');

            // --- A. ФУНКЦИЯ ДЛЯ СОХРАНЕНИЯ ДАННЫХ В СЕССИИ (ИСПОЛЬЗУЕТСЯ НА ШАГЕ 1) ---
            saveSummaryData = function() {
                if (!visiblePriceElement || visiblePriceElement.textContent.trim() === '—') return;
                
                var data = {
                    apartmentTitle: summaryCard.querySelector('.bsbt-summary-apartment-title').textContent.trim() || '—',
                    apartmentLink: summaryCard.querySelector('.bsbt-summary-apartment-link').getAttribute('href') || '#',
                    guests: summaryCard.querySelector('.bsbt-summary-guests').textContent.trim() || '—',
                    checkin: summaryCard.querySelector('.bsbt-summary-checkin').textContent.trim() || '—',
                    checkout: summaryCard.querySelector('.bsbt-summary-checkout').textContent.trim() || '—',
                    nights: summaryCard.querySelector('.bsbt-summary-nights').textContent.trim() || '—',
                    totalPriceDisplay: visiblePriceElement.textContent.trim() || '—',
                    totalPriceRaw: targetField.value.trim() || '',
                    // FIX: Гарантируем, что slug сохраняется
                    roomSlug: summaryCard.getAttribute('data-room-slug') || '' 
                };

                try {
                    sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
                    console.log('BSBT Session: Data successfully saved from Step 1, including slug:', data.roomSlug);
                } catch (e) {
                    console.error('BSBT Session: Failed to save data to session storage.', e);
                }
            }

            // --- B. ФУНКЦИЯ ДЛЯ ЗАГРУЗКИ ДАННЫХ ИЗ СЕССИИ (ИСПОЛЬЗУЕТСЯ НА ШАГЕ 2) ---
            function loadSummaryData() {
                try {
                    var storedData = sessionStorage.getItem(SESSION_KEY);
                    if (!storedData) {
                        console.warn('BSBT Session: No data found in session storage.');
                        return;
                    }
                    var data = JSON.parse(storedData);
                    
                    // Обновляем HTML-элементы на Шаге 2 - ПРИНУДИТЕЛЬНО
                    
                    // Apartment Title & Link
                    if (data.apartmentTitle && data.apartmentTitle !== '—') {
                        summaryCard.querySelector('.bsbt-summary-apartment-title').textContent = data.apartmentTitle;
                    }
                    if (data.apartmentLink && data.apartmentLink !== '#') {
                        summaryCard.querySelector('.bsbt-summary-apartment-link').setAttribute('href', data.apartmentLink);
                    }
                    
                    // Даты, ночи, гости, цена
                    summaryCard.querySelector('.bsbt-summary-checkin').textContent = data.checkin;
                    summaryCard.querySelector('.bsbt-summary-checkout').textContent = data.checkout;
                    summaryCard.querySelector('.bsbt-summary-nights').textContent = data.nights;

                    if (data.guests && data.guests !== '—') {
                        summaryCard.querySelector('.bsbt-summary-guests').textContent = data.guests;
                    }
                    
                    if (visiblePriceElement) {
                        visiblePriceElement.textContent = data.totalPriceDisplay;
                    }
                    if (targetField) {
                        targetField.value = data.totalPriceRaw;
                    }
                    
                    // FIX: Обновляем Slug для галереи, если он есть в сессии
                    if (data.roomSlug) {
                         summaryCard.setAttribute('data-room-slug', data.roomSlug);
                    }
                    
                    console.log('BSBT Session: Data successfully loaded onto Step 2. Loaded slug:', data.roomSlug);
                } catch (e) {
                    console.error('BSBT Session: Failed to load data from session storage.', e);
                }
            }

            // --- C. OBSERVER ДЛЯ ШАГА 1: НАХОДИМ ЦЕНУ И СОХРАНЯЕМ ЕЕ ---
            if (!isStepBooking) {
                var containerToObserve = document.getElementById('mphb-price-details') || document.querySelector('.mphb-room-price-breakdown-wrapper') || document.body;
                
                var priceSelectors = [
                    '.mphb-price-breakdown-total .mphb-price', 
                    '.mphb-price' 
                ];
                
                function handlePriceFound() {
                    var foundPriceContainer = null;
                    var rawPriceText = '';

                    for (var i = 0; i < priceSelectors.length; i++) {
                        foundPriceContainer = document.querySelector(priceSelectors[i]); 
                        
                        if (foundPriceContainer) {
                            rawPriceText = foundPriceContainer.textContent.trim();
                            
                            if (/\d/.test(rawPriceText)) {
                                var priceText = rawPriceText.replace(/[^0-9.,]/g, '').replace(',', '.');
                                targetField.value = priceText.trim();
                                
                                if(visiblePriceElement) {
                                    visiblePriceElement.textContent = rawPriceText; 
                                }
                                
                                // FIX: Сначала запускаем галерею (чтобы получить slug, если его нет)
                                initApartmentAndGallery(); 
                                
                                // Главное: сохраняем данные в сессию ПОСЛЕ попытки получить slug
                                saveSummaryData();
                                
                                observer.disconnect(); 
                                console.log('BSBT Price Observer: Price found and session data saved from ' + priceSelectors[i] + ' on Step 1.');
                                
                                var checkoutForm = document.querySelector('.mphb-checkout-form');
                                if(checkoutForm) {
                                       checkoutForm.addEventListener('submit', saveSummaryData);
                                }
                                
                                return true;
                            }
                        }
                    }
                    return false;
                }

                var observer = new MutationObserver(function(mutations, obs) {
                    if (handlePriceFound()) {
                        return;
                    }
                });

                var config = { childList: true, subtree: true, characterData: true, attributes: true };
                observer.observe(containerToObserve, config);
                handlePriceFound();
                
            } else {
                // --- D. ЛОГИКА ШАГА 2: ЗАГРУЖАЕМ ИЗ СЕССИИ И ЗАПУСКАЕМ ГАЛЕРЕЮ
                loadSummaryData();
                // FIX: Запускаем галерею ПОСЛЕ загрузки данных из сессии, которые могут содержать slug
                initApartmentAndGallery(); 
            }

        })();


        // --- 2) GUESTS LIVE UPDATE (Обновлено для принудительного сохранения)
        (function guestsLiveUpdate() {
            var guestsTarget = summaryCard.querySelector('.bsbt-summary-guests');
            if (!summaryCard.getAttribute('data-booking-id') && guestsTarget && guestsTarget.textContent.trim() === '—') {
                var adultsSelect = document.querySelector('.mphb_sc_checkout-form select[name^="mphb_room_details"][name$="[adults]"]');
                var childrenInput = document.querySelector('.mphb_sc_checkout-form input[name^="mphb_room_details"][name$="[children]"]');
                function updateGuestsSummary() {
                    if (!guestsTarget) return;
                    var adults = 0, children = 0;
                    if (adultsSelect && adultsSelect.value) { adults = parseInt(adultsSelect.value, 10) || 0; }
                    if (childrenInput && childrenInput.value) { children = parseInt(childrenInput.value, 10) || 0; }
                    var parts = [];
                    if (adults > 0) parts.push(adults + ' adult' + (adults > 1 ? 's' : ''));
                    if (children > 0) parts.push(children + ' child' + (children > 1 ? 'ren' : ''));
                    guestsTarget.textContent = parts.length ? parts.join(', ') : '—';
                    
                    var hiddenGuests = document.getElementById('booking_guests_hidden');
                    if (hiddenGuests) { hiddenGuests.value = adults + children; }
                    
                    if (typeof saveSummaryData === 'function' && !isStepBooking) {
                        setTimeout(saveSummaryData, 50); 
                    }
                }
                updateGuestsSummary();
                if (adultsSelect) adultsSelect.addEventListener('change', updateGuestsSummary);
                if (childrenInput) childrenInput.addEventListener('change', updateGuestsSummary);
            }
        })();
    });
    </script>

    <style>
        /* 2-в-ряд для Check-in/Check-out и Nights/Guests */
        .bsbt-summary-row-group {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 8px;
        }
        .bsbt-summary-row-group .bsbt-summary-row {
            width: 50%;
            margin-bottom: 0;
        }

        @media (max-width: 768px) {
            .bsbt-summary-row-group {
                flex-direction: column;
                gap: 6px;
            }
            .bsbt-summary-row-group .bsbt-summary-row {
                width: 100%;
            }
        }

        /* Cancellation – спокойный текст, как note */
        .bsbt-summary-policy-text {
            font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 13px;
            font-weight: 400;
            color: #444;
            line-height: 1.4;
        }
    </style>
    <?php

    return ob_get_clean();
}