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
    /** Czas życia klucza do listy plików (sekundy). */
    private const FILES_LIST_TRANSIENT_TTL = 600;

    private function __construct() {
        add_action( 'init',               [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'template_redirect',  [ $this, 'handle_download' ] );
        add_action( 'template_redirect',  [ $this, 'handle_single_file_download' ], 5 );
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
                'obsluga-dokumentow-ksiegowych-style',
                PIT_PLUGIN_URL . 'assets/style.css',
                [],
                pit_plugin_version()
            );

            wp_enqueue_script(
                'obsluga-dokumentow-ksiegowych-script',
                PIT_PLUGIN_URL . 'assets/script.js',
                [ 'jquery' ],
                pit_plugin_version(),
                true
            );

            wp_localize_script( 'obsluga-dokumentow-ksiegowych-script', 'pitManager', [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'pit_manager_nonce' ),
                'errorPesel'   => __( 'PESEL musi składać się z 11 cyfr.', 'obsluga-dokumentow-ksiegowych' ),
                'errorName'    => __( 'Imię i nazwisko są wymagane.', 'obsluga-dokumentow-ksiegowych' ),
                'errorFirstName' => __( 'Imię jest wymagane.', 'obsluga-dokumentow-ksiegowych' ),
                'errorLastName'  => __( 'Nazwisko jest wymagane.', 'obsluga-dokumentow-ksiegowych' ),
            ] );
        }
    }

    /**
     * Renderuje shortcode strony podatnika.
     *
     * @return string HTML strony.
     */
    public function render_shortcode(): string {
        $company_name    = get_option( 'pit_company_name', '' );
        $company_address = get_option( 'pit_company_address', '' );
        $company_nip     = get_option( 'pit_company_nip', '' );

        $db    = PIT_Database::get_instance();
        $years = $db->get_available_years();

        $error   = '';
        $success = '';
        $files_list = [];

        if ( isset( $_GET['pit_error'] ) ) {
            $error = __( 'Nie znaleziono dokumentu. Sprawdź poprawność identyfikacji.', 'obsluga-dokumentow-ksiegowych' );
        }

        $show_files_key = isset( $_GET['pit_show_files'] ) && isset( $_GET['pit_key'] )
            ? preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_GET['pit_key'] ) )
            : '';
        if ( $show_files_key !== '' ) {
            $stored = get_transient( 'pit_client_files_' . $show_files_key );
            if ( is_array( $stored ) && ! empty( $stored ) ) {
                $files_list = $stored;
            }
        }

        ob_start();

        if ( ! empty( $files_list ) ) {
            // Ekran „Dokumenty do pobrania” – bez formularza.
            ?>
        <div class="pit-client-page pit-client-files-screen">
            <h2><?php esc_html_e( 'Dokumenty do pobrania', 'obsluga-dokumentow-ksiegowych' ); ?></h2>

            <div class="pit-files-list">
                <ul class="pit-files-list-items">
                    <?php foreach ( $files_list as $item ) : ?>
                        <?php
                        $file_id   = (int) ( $item['id'] ?? 0 );
                        $file_name = ! empty( $item['name'] ) ? $item['name'] : __( 'Dokument', 'obsluga-dokumentow-ksiegowych' );
                        $download_url = add_query_arg(
                            [
                                'pit_download' => $file_id,
                                'pit_key'      => $show_files_key,
                            ],
                            get_permalink()
                        );
                        ?>
                        <li class="pit-files-list-item">
                            <span class="pit-files-list-name"><?php echo esc_html( $file_name ); ?></span>
                            <a href="<?php echo esc_url( $download_url ); ?>" class="button pit-download-one-btn">
                                <?php esc_html_e( 'Pobierz', 'obsluga-dokumentow-ksiegowych' ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p class="pit-files-screen-note"><?php esc_html_e( 'Możesz zamknąć bezpiecznie stronę.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
            <p class="pit-files-screen-actions">
                <a href="<?php echo esc_url( remove_query_arg( [ 'pit_show_files', 'pit_key' ], get_permalink() ) ); ?>" class="button pit-back-home-btn"><?php esc_html_e( 'Powrót do strony głównej', 'obsluga-dokumentow-ksiegowych' ); ?></a>
            </p>
        </div>
        <?php
            return ob_get_clean();
        }

        ?>
        <div class="pit-client-page">
            <h2><?php esc_html_e( 'Pobierz dokumenty księgowe', 'obsluga-dokumentow-ksiegowych' ); ?></h2>

            <?php if ( $company_name || $company_address || $company_nip ) : ?>
                <div class="pit-company-info">
                    <?php if ( $company_name ) : ?>
                        <p><strong><?php echo esc_html( $company_name ); ?></strong></p>
                    <?php endif; ?>
                    <?php if ( $company_address ) : ?>
                        <p><?php echo esc_html( $company_address ); ?></p>
                    <?php endif; ?>
                    <?php if ( $company_nip ) : ?>
                        <p><?php esc_html_e( 'NIP:', 'obsluga-dokumentow-ksiegowych' ); ?> <?php echo esc_html( $company_nip ); ?></p>
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
                    <label for="pit_tax_year"><?php esc_html_e( 'Rok podatkowy', 'obsluga-dokumentow-ksiegowych' ); ?> *</label>
                    <select name="tax_year" id="pit_tax_year" required>
                        <?php if ( empty( $years ) ) : ?>
                            <option value=""><?php esc_html_e( 'Brak dostępnych lat', 'obsluga-dokumentow-ksiegowych' ); ?></option>
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
                    <label for="pit_pesel"><?php esc_html_e( 'PESEL', 'obsluga-dokumentow-ksiegowych' ); ?> *</label>
                    <input type="text" 
                           name="pesel" 
                           id="pit_pesel" 
                           pattern="[0-9]{11}" 
                           maxlength="11" 
                           placeholder="<?php esc_attr_e( '11 cyfr', 'obsluga-dokumentow-ksiegowych' ); ?>"
                           required>
                </div>

                <div class="pit-form-row">
                    <label for="pit_last_name"><?php esc_html_e( 'Nazwisko', 'obsluga-dokumentow-ksiegowych' ); ?> *</label>
                    <input type="text" 
                           name="last_name" 
                           id="pit_last_name" 
                           class="pit-uppercase"
                           placeholder="<?php esc_attr_e( 'Np. KOWALSKI', 'obsluga-dokumentow-ksiegowych' ); ?>"
                           required
                           autocomplete="family-name">
                </div>
                <div class="pit-form-row">
                    <label for="pit_first_name"><?php esc_html_e( 'Imię', 'obsluga-dokumentow-ksiegowych' ); ?> *</label>
                    <input type="text" 
                           name="first_name" 
                           id="pit_first_name" 
                           class="pit-uppercase"
                           placeholder="<?php esc_attr_e( 'Np. JAN', 'obsluga-dokumentow-ksiegowych' ); ?>"
                           required
                           autocomplete="given-name">
                </div>

                <div class="pit-form-row">
                    <button type="submit" class="button button-primary pit-submit-btn">
                        <?php esc_html_e( 'Pobierz dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?>
                    </button>
                </div>
            </form>

            <p class="pit-info">
                <?php esc_html_e( 'Wprowadź swoje dane osobowe, aby pobrać formularz PIT-11. Dane muszą być zgodne z zapisami w dokumentacji księgowej.', 'obsluga-dokumentow-ksiegowych' ); ?>
            </p>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Sanityzuje i wymusza wielkie litery w polu imienia/nazwiska.
     *
     * @param string $value Wartość z formularza.
     * @return string Trim, sanityzacja, wielkie litery (UTF-8).
     */
    private function sanitize_uppercase_name( string $value ): string {
        $s = sanitize_text_field( $value );
        $s = preg_replace( '/^\s+|\s+$/u', '', $s );
        if ( $s === '' ) {
            return '';
        }
        return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
    }

    /**
     * Sprawdza, czy imię i nazwisko z bazy zgadza się z wpisanym (wymagana pełna zgodność).
     *
     * @param string $db_name   full_name z rekordu pliku.
     * @param string $user_name Wpisane imię i nazwisko (po normalizacji).
     * @return bool True jeśli identyczne (słowa w dowolnej kolejności).
     */
    /**
     * Przekierowuje na stronę podatnika z komunikatem błędu.
     */
    private function redirect_client_error(): void {
        $url = wp_get_referer();
        if ( $url === false || $url === '' ) {
            $url = remove_query_arg( [ 'pit_download', 'pit_key', 'pit_show_files' ] );
        }
        if ( $url === '' ) {
            $url = home_url( '/' );
        }
        wp_redirect( add_query_arg( 'pit_error', '1', $url ) );
    }

    private function names_match_for_download( string $db_name, string $user_name ): bool {
        $db_lower   = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $db_name ), 'UTF-8' ) : strtolower( trim( $db_name ) );
        $user_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $user_name ), 'UTF-8' ) : strtolower( trim( $user_name ) );
        $db_parts   = array_values( array_filter( preg_split( '/[\s_]+/', $db_lower ) ) );
        $user_parts = array_values( array_filter( preg_split( '/[\s_]+/', $user_lower ) ) );

        $db_parts   = array_map( 'pit_normalize_name_for_compare', $db_parts );
        $user_parts = array_map( 'pit_normalize_name_for_compare', $user_parts );

        sort( $db_parts );
        sort( $user_parts );

        return $db_parts === $user_parts;
    }

    public function handle_download(): void {
        if ( ! isset( $_POST['pit_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['pit_nonce'], 'pit_download_nonce' ) ) {
            return;
        }

        $pesel      = sanitize_text_field( $_POST['pesel'] ?? '' );
        $first_name = $this->sanitize_uppercase_name( $_POST['first_name'] ?? '' );
        $last_name  = $this->sanitize_uppercase_name( $_POST['last_name'] ?? '' );
        $tax_year   = (int) ( $_POST['tax_year'] ?? 0 );

        $full_name = pit_normalize_full_name( $last_name . ' ' . $first_name );

        $errors = [];

        if ( ! preg_match( '/^\d{11}$/', $pesel ) ) {
            $errors[] = 'pesel';
        }

        if ( $first_name === '' ) {
            $errors[] = 'first_name';
        }
        if ( $last_name === '' ) {
            $errors[] = 'last_name';
        }

        if ( $tax_year < 2000 || $tax_year > date( 'Y' ) + 1 ) {
            $errors[] = 'tax_year';
        }

        if ( ! empty( $errors ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $db    = PIT_Database::get_instance();
        $files = $db->get_files_by_pesel( $pesel, $tax_year );

        if ( empty( $files ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $first = $files[0];
        if ( ! $this->names_match_for_download( $first->full_name, $full_name ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        $downloadable = [];
        foreach ( $files as $f ) {
            $p = $f->file_path ?? '';
            if ( $p !== '' && file_exists( $p ) ) {
                $downloadable[] = [
                    'id'   => (int) $f->id,
                    'path' => $p,
                    'name' => basename( $p ),
                ];
            }
        }

        if ( empty( $downloadable ) ) {
            wp_redirect( add_query_arg( 'pit_error', '1', wp_get_referer() ) );
            exit;
        }

        // Tylko znaki alfanumeryczne – unikamy problemów z URL i sanitize_text_field.
        $pit_key = wp_generate_password( 32, false, false );
        set_transient( 'pit_client_files_' . $pit_key, $downloadable, self::FILES_LIST_TRANSIENT_TTL );

        $redirect_url = remove_query_arg( [ 'pit_error', 'pit_show_files', 'pit_key' ], wp_get_referer() );
        $redirect_url = add_query_arg( [ 'pit_show_files' => '1', 'pit_key' => $pit_key ], $redirect_url );
        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Obsługuje pobranie pojedynczego pliku (GET pit_download + pit_key).
     */
    public function handle_single_file_download(): void {
        $file_id = isset( $_GET['pit_download'] ) ? (int) $_GET['pit_download'] : 0;
        $pit_key = isset( $_GET['pit_key'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_GET['pit_key'] ) ) : '';

        if ( $file_id <= 0 || $pit_key === '' ) {
            return;
        }

        $list = get_transient( 'pit_client_files_' . $pit_key );
        if ( ! is_array( $list ) ) {
            $this->redirect_client_error();
            exit;
        }

        $found = null;
        foreach ( $list as $item ) {
            if ( (int) ( $item['id'] ?? 0 ) === $file_id ) {
                $found = $item;
                break;
            }
        }

        if ( $found === null || empty( $found['path'] ) || ! file_exists( $found['path'] ) ) {
            $this->redirect_client_error();
            exit;
        }

        $db = PIT_Database::get_instance();
        $db->mark_downloaded( $file_id );

        $filepath = $found['path'];
        $filename = $found['name'] ?? basename( $filepath );
        $is_zip   = str_ends_with( strtolower( $filename ), '.zip' );
        $mime     = $is_zip ? 'application/zip' : 'application/pdf';

        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        readfile( $filepath );
        exit;
    }
}
