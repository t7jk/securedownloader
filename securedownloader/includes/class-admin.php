<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Klasa panelu administratora wtyczki PIT-11 Manager.
 * Rejestruje strony menu i zarządza ustawieniami.
 */
class PIT_Admin {

    /** @var PIT_Admin|null Instancja singletona */
    private static ?PIT_Admin $instance = null;

    /**
     * Konstruktor – rejestruje hooki WordPress.
     */
    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_pit_delete_file', [ $this, 'handle_delete_file' ] );
        add_action( 'admin_post_pit_create_page', [ $this, 'handle_create_page' ] );
        add_action( 'admin_post_pit_remove_one_accountant', [ $this, 'handle_remove_one_accountant' ] );
        add_action( 'update_option_pit_accountant_users', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_accountant_page_url', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_client_page_url', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_developer_mode', [ $this, 'notice_developer_mode_saved' ] );
        add_action( 'admin_post_pit_set_pesel', [ $this, 'handle_set_pesel' ] );
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
     * Rejestruje strony menu w panelu WordPress.
     */
    public function register_menu(): void {
        add_submenu_page(
            'tools.php',
            __( 'Secure Downloader', 'securedownloader' ),
            __( 'Secure Downloader', 'securedownloader' ),
            'manage_options',
            'securedownloader-settings',
            [ $this, 'render_settings' ]
        );
    }

    /**
     * Rejestruje ustawienia wtyczki.
     */
    public function register_settings(): void {
        register_setting( 'pit_options_group', 'pit_accountant_users', [
            'sanitize_callback' => [ $this, 'sanitize_user_ids' ],
        ] );
        register_setting( 'pit_options_group', 'pit_accountant_page_url', [
            'sanitize_callback' => 'sanitize_url',
        ] );
        register_setting( 'pit_options_group', 'pit_client_page_url', [
            'sanitize_callback' => 'sanitize_url',
        ] );
        register_setting( 'pit_options_group', 'pit_company_name', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'pit_options_group', 'pit_company_address', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'pit_options_group', 'pit_company_nip', [
            'sanitize_callback' => [ $this, 'sanitize_nip' ],
        ] );
        register_setting( 'pit_options_group', 'pit_filename_filters', [
            'sanitize_callback' => [ $this, 'sanitize_filename_filters' ],
        ] );
        register_setting( 'pit_options_group', 'pit_developer_mode', [
            'sanitize_callback' => function ( $v ) { return (int) ( $v === 1 || $v === '1' ); },
        ] );

        add_settings_section(
            'pit_pages_section',
            __( 'Adresy stron', 'securedownloader' ),
            [ $this, 'render_section_pages_desc' ],
            'securedownloader-settings'
        );
        add_settings_field(
            'pit_accountant_page_url',
            __( 'Adres strony dla menadżera', 'securedownloader' ),
            [ $this, 'render_field_url' ],
            'securedownloader-settings',
            'pit_pages_section',
            [
                'name'        => 'pit_accountant_page_url',
                'shortcode'   => 'pit_accountant_panel',
                'description' => __( 'Pełny URL strony z panelem menadżera (np. …/manager). Na tej stronie musi być shortcode [pit_accountant_panel]. Umożliwia menadżerom wgrywanie dokumentów i zarządzanie nimi. Przycisk „Dodaj” tworzy stronę z tym shortcode, „Otwórz” – otwiera stronę w nowej karcie.', 'securedownloader' ),
            ]
        );
        add_settings_field(
            'pit_client_page_url',
            __( 'Adres strony dla klienta', 'securedownloader' ),
            [ $this, 'render_field_url' ],
            'securedownloader-settings',
            'pit_pages_section',
            [
                'name'        => 'pit_client_page_url',
                'shortcode'   => 'pit_client_page',
                'description' => __( 'Pełny URL strony, na której klienci pobierają dokumenty (np. …/securedownloader). Na stronie musi być shortcode [pit_client_page]. Klient podaje PESEL, imię i nazwisko, po weryfikacji widzi listę swoich dokumentów i może je pobrać. „Dodaj” tworzy stronę z shortcode, „Otwórz” – otwiera w nowej karcie.', 'securedownloader' ),
            ]
        );

        add_settings_section(
            'pit_managers_section',
            __( 'Kto jest menadżerem', 'securedownloader' ),
            [ $this, 'render_section_managers_desc' ],
            'securedownloader-settings'
        );
        add_settings_field(
            'pit_accountant_users',
            __( 'Lista menadżerów', 'securedownloader' ),
            [ $this, 'render_field_users' ],
            'securedownloader-settings',
            'pit_managers_section',
            [
                'name'        => 'pit_accountant_users',
                'description' => __( 'Użytkownicy z tej listy mają dostęp do panelu menadżera (adres z pola powyżej). Wybierz użytkownika z listy (Ctrl+klik dla wielu) i kliknij „Dodaj menadżera”. Usuń – usuwa z listy menadżerów (nie usuwa konta WordPress).', 'securedownloader' ),
            ]
        );

        add_settings_section(
            'pit_developer_section',
            __( 'Tryb deweloperski', 'securedownloader' ),
            [ $this, 'render_section_developer_desc' ],
            'securedownloader-settings'
        );
        add_settings_field(
            'pit_developer_mode',
            __( 'Włącz tryb deweloperski', 'securedownloader' ),
            [ $this, 'render_field_checkbox' ],
            'securedownloader-settings',
            'pit_developer_section',
            [
                'name'             => 'pit_developer_mode',
                'with_hidden_zero' => true,
                'label'            => __( 'Włącz tryb deweloperski', 'securedownloader' ),
                'description'      => __( 'Gdy włączony: przy wgrywaniu plików w panelu menadżera wyświetlany jest szczegółowy log (dlaczego nie rozpoznano PESEL, które wzorce pasują itd.). Przydatne do diagnozy na serwerze klienta. Na produkcji można wyłączyć.', 'securedownloader' ),
            ]
        );
    }

    public function sanitize_filename_filters( $input ): array {
        if ( is_array( $input ) ) {
            $lines = $input;
        } elseif ( is_string( $input ) ) {
            $lines = preg_split( '/\r\n|\r|\n/', $input );
        } else {
            $lines = [];
        }
        $out = [];
        foreach ( $lines as $line ) {
            $s = sanitize_text_field( is_string( $line ) ? $line : '' );
            $s = trim( $s );
            if ( $s !== '' ) {
                $out[] = $s;
            }
        }
        return array_values( $out );
    }

    public function render_section_pages_desc( array $args ): void {
        echo '<p class="description">' . esc_html__( 'Określ adresy stron WordPress, na których wyświetlany jest panel menadżera i strona pobierania dla klienta. Strony muszą zawierać odpowiednie shortcode’y.', 'securedownloader' ) . '</p>';
    }

    public function render_section_managers_desc( array $args ): void {
        echo '<p class="description">' . esc_html__( 'Wybierz konta WordPress (użytkowników), które mają mieć dostęp do panelu menadżera – wgrywanie dokumentów, wzorce, dane firmy.', 'securedownloader' ) . '</p>';
    }

    public function render_section_developer_desc( array $args ): void {
        echo '<p class="description">' . esc_html__( 'Opcje pomocne przy wdrażaniu i diagnozowaniu problemów z rozpoznawaniem plików i PESEL.', 'securedownloader' ) . '</p>';
    }

    public function render_field_text( array $args ): void {
        $name  = $args['name'];
        $value = get_option( $name, '' );
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text">',
            esc_attr( $name ),
            esc_attr( $value )
        );
    }

    public function render_field_url( array $args ): void {
        $name      = $args['name'];
        $value     = get_option( $name, '' );
        $shortcode = $args['shortcode'] ?? '';
        
        printf(
            '<input type="url" name="%s" value="%s" class="regular-text" placeholder="https://" id="%s">',
            esc_attr( $name ),
            esc_attr( $value ),
            esc_attr( $name )
        );
        
        if ( ! empty( $value ) && ! empty( $shortcode ) ) {
            $page_exists = ( url_to_postid( $value ) > 0 );
            if ( ! $page_exists ) {
                printf(
                    ' <a href="%s" class="button button-small">%s</a>',
                    esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pit_create_page&option_name=' . $name ), 'pit_create_page_' . $name ) ),
                    esc_html__( 'Dodaj', 'securedownloader' )
                );
            }
            printf(
                ' <a href="%s" target="_blank" class="button button-small">%s</a>',
                esc_url( $value ),
                esc_html__( 'Otwórz', 'securedownloader' )
            );
        }
        
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function render_field_checkbox( array $args ): void {
        $name  = $args['name'];
        $value = get_option( $name, 0 );
        if ( ! empty( $args['with_hidden_zero'] ) ) {
            printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
        }
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            esc_attr( $name ),
            checked( 1, $value, false ),
            esc_html( $args['label'] ?? __( 'Tak', 'securedownloader' ) )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function sanitize_user_ids( $input ): array {
        try {
            $existing_ids = get_option( 'pit_accountant_users', [] );
            if ( ! is_array( $existing_ids ) ) {
                $existing_ids = [];
            }
            $existing_ids = array_map( 'intval', $existing_ids );

            $remove_ids = [];
            if ( isset( $_POST['pit_remove_accountants'] ) && is_array( $_POST['pit_remove_accountants'] ) ) {
                $remove_ids = array_map( 'intval', $_POST['pit_remove_accountants'] );
            }
            $remove_ids = array_filter( $remove_ids );

            $existing_ids = array_filter( $existing_ids, function( $id ) use ( $remove_ids ) {
                return $id > 0 && ! in_array( (int) $id, $remove_ids, true );
            } );

            $new_ids = [];
            if ( is_array( $input ) ) {
                $new_ids = array_map( 'intval', array_filter( $input, 'is_numeric' ) );
            } elseif ( is_numeric( $input ) && (int) $input > 0 ) {
                $new_ids = [ (int) $input ];
            }

            foreach ( $new_ids as $uid ) {
                if ( $uid > 0 && get_user_by( 'id', $uid ) && ! in_array( $uid, $existing_ids, true ) ) {
                    $existing_ids[] = $uid;
                }
            }

            $result = array_values( array_unique( array_filter( $existing_ids, function( $id ) {
                return (int) $id > 0;
            } ) ) );
            return $result;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'error_log' ) ) {
                error_log( '[PIT] sanitize_user_ids: ' . $e->getMessage() );
            }
            $fallback = get_option( 'pit_accountant_users', [] );
            return is_array( $fallback ) ? array_values( array_map( 'intval', $fallback ) ) : [];
        }
    }

    public function render_field_users( array $args ): void {
        $name         = $args['name'];
        $selected_ids = get_option( $name, [] );
        if ( ! is_array( $selected_ids ) ) {
            $selected_ids = [];
        }

        $users = get_users( [ 
            'orderby' => 'user_login',
            'order'   => 'ASC',
        ] );

        if ( empty( $users ) ) {
            echo '<p class="description">' . esc_html__( 'Brak użytkowników do wyświetlenia.', 'securedownloader' ) . '</p>';
            return;
        }

        echo '<p class="description">' . esc_html__( 'Dodaj menadżerów: wybierz jednego lub wielu (Ctrl+klik) z listy, następnie kliknij „Dodaj menadżera”.', 'securedownloader' ) . '</p>';
        echo '<div style="display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-top: 8px;">';
        printf( '<input type="hidden" name="%s[]" value="">', esc_attr( $name ) );
        printf(
            '<select name="%s[]" multiple="multiple" size="10" style="min-width: 280px;">',
            esc_attr( $name )
        );
        foreach ( $users as $user ) {
            if ( in_array( $user->ID, $selected_ids, true ) ) {
                continue;
            }
            printf(
                '<option value="%d">%s</option>',
                $user->ID,
                esc_html( $user->user_login )
            );
        }
        echo '</select>';
        submit_button( __( 'Dodaj menadżera', 'securedownloader' ), 'primary', 'submit', false, [ 'style' => 'width: auto;' ] );
        echo '</div>';

        if ( ! empty( $selected_ids ) ) {
            echo '<h4 style="margin-top: 20px; margin-bottom: 8px;">' . esc_html__( 'Lista menadżerów', 'securedownloader' ) . '</h4>';
            echo '<table class="wp-list-table widefat fixed striped" style="max-width: 450px;">';
            echo '<thead><tr><th>' . esc_html__( 'Login', 'securedownloader' ) . '</th><th style="width: 100px;">' . esc_html__( 'Usuń', 'securedownloader' ) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ( $selected_ids as $user_id ) {
                $user = get_user_by( 'id', $user_id );
                if ( ! $user ) continue;
                $remove_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=pit_remove_one_accountant&user_id=' . (int) $user_id ),
                    'pit_remove_accountant_' . (int) $user_id
                );
                echo '<tr>';
                echo '<td>' . esc_html( $user->user_login ) . '</td>';
                echo '<td style="text-align: center;">';
                printf(
                    '<a href="%s" class="button button-small" aria-label="%s">%s</a>',
                    esc_url( $remove_url ),
                    esc_attr( sprintf( __( 'Usuń %s z listy menadżerów', 'securedownloader' ), $user->user_login ) ),
                    esc_html__( 'Usuń', 'securedownloader' )
                );
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    public function sanitize_nip( $input ): string {
        $input = is_string( $input ) ? $input : ( is_scalar( $input ) ? (string) $input : '' );
        $nip   = preg_replace( '/[^0-9]/', '', $input );
        return substr( $nip, 0, 10 );
    }

    /**
     * Ładuje style i skrypty tylko na stronach wtyczki.
     */
    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'securedownloader' ) ) {
            return;
        }

        wp_enqueue_style(
            'securedownloader-style',
            PIT_PLUGIN_URL . 'assets/style.css',
            [],
            pit_plugin_version()
        );

        wp_enqueue_script(
            'securedownloader-script',
            PIT_PLUGIN_URL . 'assets/script.js',
            [ 'jquery' ],
            pit_plugin_version(),
            true
        );

        wp_localize_script( 'securedownloader-script', 'pitManager', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pit_manager_nonce' ),
            'confirmDelete' => __( 'Czy na pewno usunąć ten plik?', 'securedownloader' ),
        ] );

    }

    /**
     * Renderuje główny panel administratora.
     */
    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $db     = PIT_Database::get_instance();
        $years  = $db->get_available_years();
        $year   = isset( $_GET['year'] ) ? (int) $_GET['year'] : ( $years[0] ?? date( 'Y' ) );
        $files  = $db->get_all_files( $year );

        ?>
        <div class="wrap securedownloader-wrap">
            <h1><?php esc_html_e( 'Wgrane PIT-y', 'securedownloader' ); ?></h1>

            <?php if ( isset( $_GET['pit_set_pesel_ok'] ) && $_GET['pit_set_pesel_ok'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PESEL został zapisany.', 'securedownloader' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['pit_set_pesel_error'] ) && $_GET['pit_set_pesel_error'] === '1' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Błąd: podaj prawidłowy PESEL (11 cyfr).', 'securedownloader' ); ?></p></div>
            <?php endif; ?>

            <?php if ( ! empty( $years ) ) : ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="year" id="pit-year-filter">
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $year ); ?>>
                                <?php echo esc_html( $y ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <script>
                    document.getElementById('pit-year-filter').addEventListener('change', function() {
                        window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=securedownloader-settings' ) ); ?>&year=' + this.value;
                    });
                    </script>
                </div>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Lp.', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'Nazwisko i imię', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'PESEL', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'Rok', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'Wgrano', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'Data pobrania', 'securedownloader' ); ?></th>
                        <th><?php esc_html_e( 'Akcje', 'securedownloader' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $files ) ) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'Brak dokumentów dla wybranego roku.', 'securedownloader' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php $i = 1; foreach ( $files as $file ) : 
                            $is_downloaded = ! empty( $file->last_download );
                        ?>
                            <tr class="<?php echo $is_downloaded ? '' : 'pit-not-downloaded'; ?>">
                                <td><?php echo $i++; ?></td>
                                <td><?php echo esc_html( (string) $file->full_name ); ?></td>
                                <td>
                                    <?php
                                    $pesel_empty = ( $file->pesel === '' || $file->pesel === null );
                                    if ( $pesel_empty ) :
                                        ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="pit_set_pesel">
                                            <?php wp_nonce_field( 'pit_set_pesel', 'pit_set_pesel_nonce' ); ?>
                                            <input type="hidden" name="pit_set_pesel_full_name" value="<?php echo esc_attr( $file->full_name ); ?>">
                                            <a href="#" class="pit-brak-pesel-link"><?php esc_html_e( 'Nie dopasowano', 'securedownloader' ); ?></a>
                                            <span class="pit-set-pesel-form" style="display:none;">
                                                <input type="text" name="pit_set_pesel_value" placeholder="<?php esc_attr_e( '11 cyfr', 'securedownloader' ); ?>" maxlength="11" pattern="\d{11}" size="11" style="width:100px;">
                                                <button type="submit" class="button button-small"><?php esc_html_e( 'Zapisz', 'securedownloader' ); ?></button>
                                            </span>
                                        </form>
                                    <?php else : ?>
                                        <?php echo esc_html( $file->pesel ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $file->tax_year ); ?></td>
                                <td><?php echo esc_html( $file->uploaded_at ); ?></td>
                                <td>
                                    <?php 
                                    echo $is_downloaded 
                                        ? esc_html( $file->last_download ) 
                                        : '<em>' . esc_html__( 'Nie pobrano', 'securedownloader' ) . '</em>'; 
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pit_delete_file&id=' . $file->id ), 'pit_delete_' . $file->id ) ); ?>"
                                       class="button button-small button-link-delete pit-confirm-delete">
                                        <?php esc_html_e( 'Usuń', 'securedownloader' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renderuje stronę ustawień.
     */
    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $accountant_url = get_option( 'pit_accountant_page_url', '' );
        $client_url = get_option( 'pit_client_page_url', '' );

        $accountant_shortcode_missing = false;
        $client_shortcode_missing = false;

        if ( ! empty( $accountant_url ) ) {
            $accountant_page_id = url_to_postid( $accountant_url );
            if ( $accountant_page_id ) {
                $accountant_page = get_post( $accountant_page_id );
                if ( $accountant_page && ! has_shortcode( $accountant_page->post_content, 'pit_accountant_panel' ) ) {
                    $accountant_shortcode_missing = true;
                }
            }
        }

        if ( ! empty( $client_url ) ) {
            $client_page_id = url_to_postid( $client_url );
            if ( $client_page_id ) {
                $client_page = get_post( $client_page_id );
                if ( $client_page && ! has_shortcode( $client_page->post_content, 'pit_client_page' ) ) {
                    $client_shortcode_missing = true;
                }
            }
        }
        $manual_url = '';
        if ( ! empty( $accountant_url ) ) {
            $manual_url = add_query_arg( 'pit_tab', 'manual', $accountant_url );
        }
        ?>
        <div class="wrap securedownloader-wrap">
            <h1><?php esc_html_e( 'Ustawienia', 'securedownloader' ); ?></h1>
            <?php if ( $manual_url ) : ?>
                <p class="sd-settings-manual-link">
                    <a href="<?php echo esc_url( $manual_url ); ?>" target="_blank" class="button button-secondary">
                        <?php esc_html_e( 'Podręcznik użytkownika (User Manual)', 'securedownloader' ); ?>
                    </a>
                    <?php esc_html_e( 'Pełna instrukcja w panelu menadżera w zakładce „Podręcznik”.', 'securedownloader' ); ?>
                </p>
            <?php endif; ?>

            <div class="sd-about-section" style="margin-top: 16px; margin-bottom: 24px; padding: 24px; border: 1px solid #c3c4c7; border-radius: 4px; background: #f6f7f7; max-width: 640px;">
                <h2 style="margin-top: 0; margin-bottom: 16px; font-size: 1.3em;"><?php esc_html_e( 'Informacje o autorze i wtyczce WordPress', 'securedownloader' ); ?></h2>
                <div style="display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start;">
                    <div style="flex-shrink: 0;">
                        <img src="<?php echo esc_url( PIT_PLUGIN_URL . 'assets/secure-downloader-card.png' ); ?>" alt="<?php esc_attr_e( 'Secure Downloader', 'securedownloader' ); ?>" style="max-width: 280px; height: auto; border-radius: 4px; display: block;">
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Autor:', 'securedownloader' ); ?></strong> <a href="https://x.com/tomas3man" target="_blank" rel="noopener noreferrer">Tomasz Kalinowski</a></p>
                        <p style="margin: 0 0 8px 0;">
                            <strong>X / Twitter:</strong>
                            <a href="https://x.com/tomas3man" target="_blank" rel="noopener noreferrer">@tomas3man</a>
                        </p>
                        <p style="margin: 0 0 8px 0;">
                            <strong>Telegram:</strong>
                            <a href="https://t.me/t7jka" target="_blank" rel="noopener noreferrer">@t7jka</a>
                        </p>
                        <p style="margin: 0 0 8px 0;">
                            <strong>GitHub:</strong>
                            <a href="https://github.com/t7jk/securedownloader" target="_blank" rel="noopener noreferrer">github.com/t7jk/securedownloader</a>
                        </p>
                        <p style="margin: 0 0 12px 0;">
                            <strong><?php esc_html_e( 'Wersja wtyczki:', 'securedownloader' ); ?></strong>
                            <?php echo esc_html( pit_plugin_version() ); ?>
                        </p>
                        <p style="margin: 0; color: #50575e; line-height: 1.5;">
                            <?php esc_html_e( 'Wtyczka umożliwia menadżerom wgrywanie dokumentów, a klientom ich pobieranie po weryfikacji danych osobowych.', 'securedownloader' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php
            // Komunikaty po kliknięciu „Dodaj stronę” (menadżera / klienta)
            if ( isset( $_GET['page_created'] ) && $_GET['page_created'] === '1' ) :
                ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Strona została utworzona i adres został zapisany.', 'securedownloader' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['page_exists'] ) && $_GET['page_exists'] === '1' ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Strona o tym adresie (slug) już istnieje. Zmień slug lub usuń istniejącą stronę w Strony → Wszystkie strony.', 'securedownloader' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['page_linked'] ) && $_GET['page_linked'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Strona o tym adresie już istniała; adres został zapisany w ustawieniach.', 'securedownloader' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['pit_accountant_removed'] ) && $_GET['pit_accountant_removed'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Menadżer został usunięty z listy.', 'securedownloader' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <?php
                        switch ( $_GET['error'] ) {
                            case 'no_url':
                                esc_html_e( 'Błąd: brak zapisanego adresu URL. Wpisz adres w pole powyżej i kliknij „Zapisz zmiany”, a następnie „Dodaj”.', 'securedownloader' );
                                break;
                            case 'create_failed':
                                esc_html_e( 'Błąd: nie udało się utworzyć strony (np. brak uprawnień do tworzenia stron). Sprawdź uprawnienia użytkownika i zapis katalogu WordPress.', 'securedownloader' );
                                break;
                            case 'invalid_option':
                                esc_html_e( 'Błąd: nieprawidłowa opcja.', 'securedownloader' );
                                break;
                            default:
                                echo esc_html( __( 'Wystąpił błąd.', 'securedownloader' ) );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $accountant_url ) || empty( $client_url ) || $accountant_shortcode_missing || $client_shortcode_missing ) : ?>
                <div class="notice notice-warning is-dismissible" style="margin-top: 10px;">
                    <p><strong><?php esc_html_e( 'Uwaga!', 'securedownloader' ); ?></strong></p>
                    <?php if ( empty( $accountant_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony menadżera. Utwórz podstronę [/manager] z kodem [pit_accountant_panel] i wpisz jej URL powyżej.', 'securedownloader' ); ?></p>
                    <?php elseif ( $accountant_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona menadżera nie zawiera shortcode [pit_accountant_panel]. Dodaj go do treści strony.', 'securedownloader' ); ?></p>
                    <?php endif; ?>
                    <?php if ( empty( $client_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony klienta. Utwórz podstronę [/securedownloader] z kodem [pit_client_page] i wpisz jej URL powyżej.', 'securedownloader' ); ?></p>
                    <?php elseif ( $client_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona klienta nie zawiera shortcode [pit_client_page]. Dodaj go do treści strony.', 'securedownloader' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ( get_transient( 'pit_developer_mode_saved_notice' ) ) : ?>
                <?php delete_transient( 'pit_developer_mode_saved_notice' ); ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Zmiana trybu na developerski została zapisana.', 'securedownloader' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['pit_import_updated'] ) || isset( $_GET['pit_import_skipped'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        if ( isset( $_GET['pit_import_updated'] ) && (int) $_GET['pit_import_updated'] > 0 ) {
                            echo esc_html( sprintf( __( 'Zaktualizowano PESEL dla %d osób.', 'securedownloader' ), (int) $_GET['pit_import_updated'] ) );
                        }
                        if ( isset( $_GET['pit_import_skipped'] ) && (int) $_GET['pit_import_skipped'] > 0 ) {
                            echo ' ' . esc_html( sprintf( __( 'Pominięto %d wierszy (błędny format lub brak dopasowania w bazie).', 'securedownloader' ), (int) $_GET['pit_import_skipped'] ) );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" id="pit-settings-form">
                <?php
                settings_fields( 'pit_options_group' );
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( admin_url( 'admin.php?page=securedownloader-settings' ) ) . '">';
                do_settings_sections( 'securedownloader-settings' );
                submit_button( __( 'Zapisz zmiany', 'securedownloader' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Obsługuje ręczne ustawienie PESEL dla osoby (link „Nie dopasowano” na liście).
     */
    public function handle_set_pesel(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_set_pesel_nonce'] ?? '', 'pit_set_pesel' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $full_name = sanitize_text_field( $_POST['pit_set_pesel_full_name'] ?? '' );
        $pesel     = sanitize_text_field( $_POST['pit_set_pesel_value'] ?? '' );
        $pesel     = preg_replace( '/\D/', '', $pesel );

        if ( $full_name === '' || strlen( $pesel ) !== 11 ) {
            wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&pit_set_pesel_error=1' ) );
            exit;
        }

        $db = PIT_Database::get_instance();
        $db->update_pesel_for_person( $full_name, $pesel );

        wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&pit_set_pesel_ok=1' ) );
        exit;
    }

    /**
     * Przekierowuje do strony menadżera.
     */
    public function redirect_to_accountant_page(): void {
        $url = get_option( 'pit_accountant_page_url', home_url() );
        echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
        echo '<p>' . esc_html__( 'Przekierowywanie...', 'securedownloader' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Kliknij tutaj', 'securedownloader' ) . '</a>.</p>';
        exit;
    }

    /**
     * Przekierowuje po zapisaniu ustawień.
     */
    public function redirect_after_save(): void {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ustawienia zapisane.', 'securedownloader' ) . '</p></div>';
        } );
    }

    /**
     * Po zapisaniu opcji pit_developer_mode ustawia transient, żeby na stronie ustawień pokazać komunikat.
     */
    public function notice_developer_mode_saved(): void {
        set_transient( 'pit_developer_mode_saved_notice', 1, 30 );
    }

    /**
     * Usuwa jednego menadżera z listy (wywołane przyciskiem „Usuń” w wierszu).
     */
    public function handle_remove_one_accountant(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $user_id = isset( $_REQUEST['user_id'] ) ? (int) $_REQUEST['user_id'] : 0;
        if ( $user_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=securedownloader-settings' ) );
            exit;
        }

        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'pit_remove_accountant_' . $user_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $ids = get_option( 'pit_accountant_users', [] );
        if ( ! is_array( $ids ) ) {
            $ids = [];
        }
        $ids = array_values( array_filter( array_map( 'intval', $ids ), function( $id ) {
            return $id > 0;
        } ) );
        $ids = array_values( array_diff( $ids, [ $user_id ] ) );

        // Wymuszenie zapisu (delete + add omija ewentualne problemy z cache/porównaniem w update_option).
        delete_option( 'pit_accountant_users' );
        add_option( 'pit_accountant_users', $ids );

        wp_safe_redirect( admin_url( 'admin.php?page=securedownloader-settings&pit_accountant_removed=1' ) );
        exit;
    }

    /**
     * Obsługuje usuwanie pliku.
     */
    public function handle_delete_file(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $file_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_delete_' . $file_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $db = PIT_Database::get_instance();
        $db->delete_file( $file_id );

        wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&deleted=1' ) );
        exit;
    }

    public function handle_create_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $option_name = sanitize_text_field( $_GET['option_name'] ?? '' );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_create_page_' . $option_name ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $url = get_option( $option_name, '' );
        if ( empty( $url ) ) {
            wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&error=no_url' ) );
            exit;
        }

        $page_configs = [
            'pit_accountant_page_url' => [
                'slug'      => 'manager',
                'title'     => __( 'Menadżer', 'securedownloader' ),
                'shortcode' => '[pit_accountant_panel]',
            ],
            'pit_client_page_url' => [
                'slug'      => 'securedownloader',
                'title'     => __( 'Klient', 'securedownloader' ),
                'shortcode' => '[pit_client_page]',
            ],
        ];

        if ( ! isset( $page_configs[ $option_name ] ) ) {
            wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&error=invalid_option' ) );
            exit;
        }

        $config = $page_configs[ $option_name ];

        $existing_page = get_page_by_path( $config['slug'] );
        if ( $existing_page ) {
            // Strona o tym slugu już istnieje – zapisz jej URL w opcji
            $existing_url = get_permalink( $existing_page );
            update_option( $option_name, $existing_url );
            wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&page_linked=1' ) );
            exit;
        }

        $page_id = wp_insert_post( [
            'post_title'   => $config['title'],
            'post_name'    => $config['slug'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => $config['shortcode'],
        ] );

        if ( is_wp_error( $page_id ) ) {
            wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&error=create_failed' ) );
            exit;
        }

        $new_url = get_permalink( $page_id );
        update_option( $option_name, $new_url );

        wp_redirect( admin_url( 'admin.php?page=securedownloader-settings&page_created=1' ) );
        exit;
    }
}
