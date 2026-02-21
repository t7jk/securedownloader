<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Klasa strony podatnika wtyczki PIT-11 Manager.
 * Umożliwia podatnikom pobieranie PIT-11 po weryfikacji danych.
 */
class PIT_Client {

    /** @var PIT_Client|null Instancja singletona */
    private static ?PIT_Client $instance = null;

    /**
     * Konstruktor – rejestruje hooki WordPress.
     */
    private function __construct() {
        add_action( 'init',               [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'template_redirect',  [ $this, 'handle_download' ] );
    }

    /**
     * Zwraca jedyną instancję klasy (singleton).
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Rejestruje shortcode [pit_client_page].
     */
    public function register_shortcode(): void {
        add_shortcode( 'pit_client_page', [ $this, 'render_shortcode' ] );
    }

    /**
     * Ładuje style na froncie.
     */
    public function enqueue_assets(): void {
        global $post;

        if ( $post && has_shortcode( $post->post_content, 'pit_client_page' ) ) {
            wp_enqueue_style(
                'obsluga-pit-style',
                PIT_PLUGIN_URL . 'assets/style.css',
                [],
                PIT_VERSION
            );

            wp_enqueue_script(
                'obsluga-pit-script',
                PIT_PLUGIN_URL . 'assets/script.js',
                [ 'jquery' ],
                PIT_VERSION,
                true
            );

            wp_localize_script( 'obsluga-pit-script', 'pitManager', [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'pit_manager_nonce' ),
                'errorPesel'   => __( 'PESEL musi składać się z 11 cyfr.', 'obsluga-pit' ),
                'errorName'    => __( 'Imię i nazwisko są wymagane.', 'obsluga-pit' ),
                'errorConfirm' => __( 'Musisz potwierdzić otrzymanie dokumentu.', 'obsluga-pit' ),
            ] );
        }
    }

    /**
     * Renderuje shortcode strony podatnika.
     *
     * @return string HTML strony.
     */
    public function render_shortcode(): string {
        if ( ! get_option( 'pit_enabled', 1 ) ) {
            return '';
        }

        $company_name    = get_option( 'pit_company_name', '' );
        $company_address = get_option( 'pit_company_address', '' );
        $company_nip     = get_option( 'pit_company_nip', '' );

        $db    = PIT_Database::get_instance();
        $years = $db->get_available_years();

        $error   = '';
        $success = '';

        if ( isset( $_GET['pit_error'] ) ) {
            $error = __( 'Nie znaleziono dokumentu. Sprawdź poprawność identyfikacji.', 'obsluga-pit' );
        }

        ob_start();

        ?>
        <div class="pit-client-page">
            <h2><?php esc_html_e( 'Pobierz swój PIT-11', 'obsluga-pit' ); ?></h2>

            <?php if ( $company_name || $company_address || $company_nip ) : ?>
                <div class="pit-company-info">
                    <?php if ( $company_name ) : ?>
                        <p><strong><?php echo esc_html( $company_name ); ?></strong></p>
                    <?php endif; ?>
                    <?php if ( $company_address ) : ?>
                        <p><?php echo esc_html( $company_address ); ?></p>
                    <?php endif; ?>
                    <?php if ( $company_nip ) : ?>
                        <p><?php esc_html_e( 'NIP:', 'obsluga-pit' ); ?> <?php echo esc_html( $company_nip ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $error ) : ?>
                <div class="pit-message pit-error">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="pit-client-form" id="pit-download-form">
                <?php wp_nonce_field( 'pit_download_nonce', 'pit_nonce' ); ?>

                <div class="pit-form-row">
                    <label for="pit_tax_year"><?php esc_html_e( 'Rok podatkowy', 'obsluga-pit' ); ?> *</label>
                    <select name="tax_year" id="pit_tax_year" required>
                        <?php if ( empty( $years ) ) : ?>
                            <option value=""><?php esc_html_e( 'Brak dostępnych lat', 'obsluga-pit' ); ?></option>
                        <?php else : ?>
                            <?php foreach ( $years as $y ) : ?>
                                <option value="<?php echo esc_attr( $y ); ?>">
                                    <?php echo esc_html( $y ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="pit-form-row">
                    <label for="pit_pesel"><?php esc_html_e( 'PESEL', 'obsluga-pit' ); ?> *</label>
                    <input type="text" 
                           name="pesel" 
                           id="pit_pesel" 
                           pattern="[0-9]{11}" 
                           maxlength="11" 
                           placeholder="<?php esc_attr_e( '11 cyfr', 'obsluga-pit' ); ?>"
                           required>
                </div>

                <div class="pit-form-row">
                    <label for="pit_full_name"><?php esc_html_e( 'Imię i nazwisko', 'obsluga-pit' ); ?> *</label>
                    <input type="text" 
                           name="full_name" 
                           id="pit_full_name" 
                           placeholder="<?php esc_attr_e( 'Np. Jan Kowalski', 'obsluga-pit' ); ?>"
                           required>
                </div>

                <div class="pit-form-row pit-checkbox-row">
                    <label class="pit-checkbox-label">
                        <input type="checkbox" name="confirm" id="pit_confirm" required>
                        <?php esc_html_e( 'Potwierdzam otrzymanie dokumentu PIT.', 'obsluga-pit' ); ?>
                    </label>
                </div>

                <div class="pit-form-row">
                    <button type="submit" class="button button-primary pit-submit-btn">
                        <?php esc_html_e( 'Pobierz PIT-11', 'obsluga-pit' ); ?>
                    </button>
                </div>
            </form>

            <p class="pit-info">
                <?php esc_html_e( 'Wprowadź swoje dane osobowe, aby pobrać formularz PIT-11. Dane muszą być zgodne z zapisami w dokumentacji księgowej.', 'obsluga-pit' ); ?>
            </p>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Obsługuje pobieranie pliku.
     */
    public function handle_download(): void {
        if ( ! isset( $_POST['pit_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['pit_nonce'], 'pit_download_nonce' ) ) {
            return;
        }

        $pesel     = sanitize_text_field( $_POST['pesel'] ?? '' );
        $full_name = sanitize_text_field( $_POST['full_name'] ?? '' );
        $tax_year  = (int) ( $_POST['tax_year'] ?? 0 );
        $confirm   = isset( $_POST['confirm'] );

        $errors = [];

        if ( ! preg_match( '/^\d{11}$/', $pesel ) ) {
            $errors[] = 'pesel';
        }

        if ( empty( $full_name ) ) {
            $errors[] = 'full_name';
        }

        if ( $tax_year < 2000 || $tax_year > date( 'Y' ) + 1 ) {
            $errors[] = 'tax_year';
        }

        if ( ! $confirm ) {
            $errors[] = 'confirm';
        }

        if ( ! empty( $errors ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $db   = PIT_Database::get_instance();
        $file = $db->get_file_by_pesel( $pesel, $tax_year );

        if ( ! $file ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $db_parts   = preg_split( '/[\s_]+/', strtolower( trim( $file->full_name ) ) );
        $user_parts = preg_split( '/[\s_]+/', strtolower( trim( $full_name ) ) );

        sort( $db_parts );
        sort( $user_parts );

        if ( $db_parts !== $user_parts ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $filepath = $file->file_path;

        if ( ! file_exists( $filepath ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $db->mark_downloaded( $file->id );

        $filename = 'PIT-11_' . $tax_year . '_' . sanitize_file_name( $file->full_name ) . '.pdf';

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        readfile( $filepath );
        exit;
    }
}
