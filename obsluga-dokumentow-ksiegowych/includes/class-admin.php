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
        add_action( 'update_option_pit_accountant_users', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_accountant_page_url', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_client_page_url', [ $this, 'redirect_after_save' ] );
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
            __( 'Obsługa dokumentów księgowych', 'obsluga-dokumentow-ksiegowych' ),
            __( 'Obsługa dokumentów księgowych', 'obsluga-dokumentow-ksiegowych' ),
            'manage_options',
            'obsluga-dokumentow-ksiegowych-settings',
            [ $this, 'render_settings' ]
        );
    }

    /**
     * Rejestruje ustawienia wtyczki.
     */
    public function register_settings(): void {
        register_setting( 'pit_options_group', 'pit_enabled', [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
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

        add_settings_section(
            'pit_main_settings',
            '',
            null,
            'obsluga-dokumentow-ksiegowych-settings'
        );

        add_settings_field(
            'pit_accountant_page_url',
            __( 'URL strony księgowego', 'obsluga-dokumentow-ksiegowych' ),
            [ $this, 'render_field_url' ],
            'obsluga-dokumentow-ksiegowych-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_accountant_page_url',
                'shortcode'   => 'pit_accountant_panel',
                'description' => __( 'Utwórz podstronę [/ksiegowy] z kodem [pit_accountant_panel] dla Księgowego', 'obsluga-dokumentow-ksiegowych' )
            ]
        );

        add_settings_field(
            'pit_client_page_url',
            __( 'URL strony podatnika', 'obsluga-dokumentow-ksiegowych' ),
            [ $this, 'render_field_url' ],
            'obsluga-dokumentow-ksiegowych-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_client_page_url',
                'shortcode'   => 'pit_client_page',
                'description' => __( 'Utwórz podstronę [/podatnik] z kodem [pit_client_page] dla Podatnika', 'obsluga-dokumentow-ksiegowych' )
            ]
        );

        add_settings_field(
            'pit_accountant_users',
            __( 'Wybierz księgowego', 'obsluga-dokumentow-ksiegowych' ),
            [ $this, 'render_field_users' ],
            'obsluga-dokumentow-ksiegowych-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_accountant_users',
                'description' => __( 'Wybierz użytkownika i kliknij "Zapisz zmiany", aby dodać go do listy księgowych.', 'obsluga-dokumentow-ksiegowych' )
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
                    esc_html__( 'Dodaj', 'obsluga-dokumentow-ksiegowych' )
                );
            }
            printf(
                ' <a href="%s" target="_blank" class="button button-small">%s</a>',
                esc_url( $value ),
                esc_html__( 'Otwórz', 'obsluga-dokumentow-ksiegowych' )
            );
        }
        
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function render_field_checkbox( array $args ): void {
        $name  = $args['name'];
        $value = get_option( $name, 0 );
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            esc_attr( $name ),
            checked( 1, $value, false ),
            esc_html__( 'Tak', 'obsluga-dokumentow-ksiegowych' )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function render_field_toggle( array $args ): void {
        $name  = $args['name'];
        $value = get_option( $name, 1 );
        $checked = checked( 1, $value, false );
        echo '<label class="pit-toggle">';
        printf( '<input type="checkbox" name="%s" value="1" %s>', esc_attr( $name ), $checked );
        echo '<span class="pit-toggle-slider"></span>';
        echo '</label>';
        echo '<span class="pit-toggle-label">' . ( $value ? esc_html__( 'ON', 'obsluga-dokumentow-ksiegowych' ) : esc_html__( 'OFF', 'obsluga-dokumentow-ksiegowych' ) ) . '</span>';
    }

    public function sanitize_checkbox( $input ): int {
        return empty( $input ) ? 0 : 1;
    }

    public function sanitize_user_ids( $input ): array {
        $existing_ids = get_option( 'pit_accountant_users', [] );
        if ( ! is_array( $existing_ids ) ) {
            $existing_ids = [];
        }

        $remove_ids = [];
        if ( isset( $_POST['pit_remove_accountants'] ) && is_array( $_POST['pit_remove_accountants'] ) ) {
            $remove_ids = array_map( 'intval', $_POST['pit_remove_accountants'] );
        }

        $existing_ids = array_filter( $existing_ids, fn( $id ) => ! in_array( $id, $remove_ids, true ) );

        $new_id = 0;
        if ( is_array( $input ) && ! empty( $input ) ) {
            $new_id = (int) $input[0];
        } elseif ( is_numeric( $input ) ) {
            $new_id = (int) $input;
        }

        if ( $new_id > 0 && get_user_by( 'id', $new_id ) ) {
            $existing_ids[] = $new_id;
        }

        return array_unique( array_filter( $existing_ids ) );
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
            echo '<p class="description">' . esc_html__( 'Brak użytkowników do wyświetlenia.', 'obsluga-dokumentow-ksiegowych' ) . '</p>';
            return;
        }

        printf(
            '<select name="%s[]" style="min-width: 300px;">',
            esc_attr( $name )
        );
        echo '<option value="">' . esc_html__( '— Wybierz księgowego —', 'obsluga-dokumentow-ksiegowych' ) . '</option>';
        
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

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }

        if ( ! empty( $selected_ids ) ) {
            echo '<h4 style="margin-top: 15px; margin-bottom: 5px;">' . esc_html__( 'Lista obecnych księgowych:', 'obsluga-dokumentow-ksiegowych' ) . '</h4>';
            echo '<table class="wp-list-table widefat fixed striped" style="max-width: 400px;">';
            echo '<thead><tr><th>' . esc_html__( 'Login', 'obsluga-dokumentow-ksiegowych' ) . '</th><th style="width: 80px;">' . esc_html__( 'Usunięcie', 'obsluga-dokumentow-ksiegowych' ) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ( $selected_ids as $user_id ) {
                $user = get_user_by( 'id', $user_id );
                if ( ! $user ) continue;
                echo '<tr>';
                echo '<td>' . esc_html( $user->user_login ) . '</td>';
                echo '<td style="text-align: center;">';
                printf(
                    '<input type="checkbox" name="pit_remove_accountants[]" value="%d">',
                    $user_id
                );
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__( 'Zaznacz księgowych do usunięcia i kliknij "Zapisz zmiany".', 'obsluga-dokumentow-ksiegowych' ) . '</p>';
        }
    }

    public function sanitize_nip( string $input ): string {
        $nip = preg_replace( '/[^0-9]/', '', $input );
        return substr( $nip, 0, 10 );
    }

    /**
     * Ładuje style i skrypty tylko na stronach wtyczki.
     */
    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'obsluga-dokumentow-ksiegowych' ) ) {
            return;
        }

        wp_enqueue_style(
            'obsluga-dokumentow-ksiegowych-style',
            PIT_PLUGIN_URL . 'assets/style.css',
            [],
            PIT_VERSION
        );

        wp_enqueue_script(
            'obsluga-dokumentow-ksiegowych-script',
            PIT_PLUGIN_URL . 'assets/script.js',
            [ 'jquery' ],
            PIT_VERSION,
            true
        );

        wp_localize_script( 'obsluga-dokumentow-ksiegowych-script', 'pitManager', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pit_manager_nonce' ),
            'confirmDelete' => __( 'Czy na pewno usunąć ten plik?', 'obsluga-dokumentow-ksiegowych' ),
        ] );
    }

    /**
     * Renderuje główny panel administratora.
     */
    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $db     = PIT_Database::get_instance();
        $years  = $db->get_available_years();
        $year   = isset( $_GET['year'] ) ? (int) $_GET['year'] : ( $years[0] ?? date( 'Y' ) );
        $files  = $db->get_all_files( $year );

        ?>
        <div class="wrap obsluga-dokumentow-ksiegowych-wrap">
            <h1><?php esc_html_e( 'Wgrane PIT-y', 'obsluga-dokumentow-ksiegowych' ); ?></h1>

            <?php if ( isset( $_GET['pit_set_pesel_ok'] ) && $_GET['pit_set_pesel_ok'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PESEL został zapisany.', 'obsluga-dokumentow-ksiegowych' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['pit_set_pesel_error'] ) && $_GET['pit_set_pesel_error'] === '1' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Błąd: podaj prawidłowy PESEL (11 cyfr).', 'obsluga-dokumentow-ksiegowych' ); ?></p></div>
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
                        window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych' ) ); ?>&year=' + this.value;
                    });
                    </script>
                </div>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Lp.', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'Nazwisko i imię', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'PESEL', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'Rok', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'Wgrano', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'Data pobrania', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                        <th><?php esc_html_e( 'Akcje', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $files ) ) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'Brak dokumentów dla wybranego roku.', 'obsluga-dokumentow-ksiegowych' ); ?></td>
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
                                            <a href="#" class="pit-brak-pesel-link"><?php esc_html_e( 'Nie dopasowano', 'obsluga-dokumentow-ksiegowych' ); ?></a>
                                            <span class="pit-set-pesel-form" style="display:none;">
                                                <input type="text" name="pit_set_pesel_value" placeholder="<?php esc_attr_e( '11 cyfr', 'obsluga-dokumentow-ksiegowych' ); ?>" maxlength="11" pattern="\d{11}" size="11" style="width:100px;">
                                                <button type="submit" class="button button-small"><?php esc_html_e( 'Zapisz', 'obsluga-dokumentow-ksiegowych' ); ?></button>
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
                                        : '<em>' . esc_html__( 'Nie pobrano', 'obsluga-dokumentow-ksiegowych' ) . '</em>'; 
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pit_delete_file&id=' . $file->id ), 'pit_delete_' . $file->id ) ); ?>"
                                       class="button button-small button-link-delete pit-confirm-delete">
                                        <?php esc_html_e( 'Usuń', 'obsluga-dokumentow-ksiegowych' ); ?>
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
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
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
        ?>
        <div class="wrap obsluga-dokumentow-ksiegowych-wrap">
            <h1><?php esc_html_e( 'Ustawienia', 'obsluga-dokumentow-ksiegowych' ); ?></h1>
            <p class="description" style="margin-top: -8px;">
                <?php
                printf(
                    /* translators: 1: version number, 2: build number */
                    esc_html__( 'Wersja %1$s (Build %2$s)', 'obsluga-dokumentow-ksiegowych' ),
                    esc_html( PIT_VERSION ),
                    esc_html( (string) PIT_BUILD )
                );
                ?>
            </p>

            <?php if ( empty( $accountant_url ) || empty( $client_url ) || $accountant_shortcode_missing || $client_shortcode_missing ) : ?>
                <div class="notice notice-warning is-dismissible" style="margin-top: 10px;">
                    <p><strong><?php esc_html_e( 'Uwaga!', 'obsluga-dokumentow-ksiegowych' ); ?></strong></p>
                    <?php if ( empty( $accountant_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony księgowego. Utwórz podstronę [/ksiegowy] z kodem [pit_accountant_panel] i wpisz jej URL powyżej.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                    <?php elseif ( $accountant_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona księgowego nie zawiera shortcode [pit_accountant_panel]. Dodaj go do treści strony.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                    <?php endif; ?>
                    <?php if ( empty( $client_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony podatnika. Utwórz podstronę [/podatnik] z kodem [pit_client_page] i wpisz jej URL powyżej.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                    <?php elseif ( $client_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona podatnika nie zawiera shortcode [pit_client_page]. Dodaj go do treści strony.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ( isset( $_GET['pit_import_updated'] ) || isset( $_GET['pit_import_skipped'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        if ( isset( $_GET['pit_import_updated'] ) && (int) $_GET['pit_import_updated'] > 0 ) {
                            echo esc_html( sprintf( __( 'Zaktualizowano PESEL dla %d osób.', 'obsluga-dokumentow-ksiegowych' ), (int) $_GET['pit_import_updated'] ) );
                        }
                        if ( isset( $_GET['pit_import_skipped'] ) && (int) $_GET['pit_import_skipped'] > 0 ) {
                            echo ' ' . esc_html( sprintf( __( 'Pominięto %d wierszy (błędny format lub brak dopasowania w bazie).', 'obsluga-dokumentow-ksiegowych' ), (int) $_GET['pit_import_skipped'] ) );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
                <?php
                settings_fields( 'pit_options_group' );
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings' ) ) . '">';
                do_settings_sections( 'obsluga-dokumentow-ksiegowych-settings' );
                submit_button();
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
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_set_pesel_nonce'] ?? '', 'pit_set_pesel' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $full_name = sanitize_text_field( $_POST['pit_set_pesel_full_name'] ?? '' );
        $pesel     = sanitize_text_field( $_POST['pit_set_pesel_value'] ?? '' );
        $pesel     = preg_replace( '/\D/', '', $pesel );

        if ( $full_name === '' || strlen( $pesel ) !== 11 ) {
            wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&pit_set_pesel_error=1' ) );
            exit;
        }

        $db = PIT_Database::get_instance();
        $db->update_pesel_for_person( $full_name, $pesel );

        wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&pit_set_pesel_ok=1' ) );
        exit;
    }

    /**
     * Przekierowuje do strony księgowego.
     */
    public function redirect_to_accountant_page(): void {
        $url = get_option( 'pit_accountant_page_url', home_url() );
        echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
        echo '<p>' . esc_html__( 'Przekierowywanie...', 'obsluga-dokumentow-ksiegowych' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Kliknij tutaj', 'obsluga-dokumentow-ksiegowych' ) . '</a>.</p>';
        exit;
    }

    /**
     * Przekierowuje po zapisaniu ustawień.
     */
    public function redirect_after_save(): void {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ustawienia zapisane.', 'obsluga-dokumentow-ksiegowych' ) . '</p></div>';
        } );
    }

    /**
     * Obsługuje usuwanie pliku.
     */
    public function handle_delete_file(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $file_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_delete_' . $file_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $db = PIT_Database::get_instance();
        $db->delete_file( $file_id );

        wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&deleted=1' ) );
        exit;
    }

    public function handle_create_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $option_name = sanitize_text_field( $_GET['option_name'] ?? '' );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_create_page_' . $option_name ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $url = get_option( $option_name, '' );
        if ( empty( $url ) ) {
            wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&error=no_url' ) );
            exit;
        }

        $page_configs = [
            'pit_accountant_page_url' => [
                'slug'      => 'ksiegowy',
                'title'     => __( 'Księgowy', 'obsluga-dokumentow-ksiegowych' ),
                'shortcode' => '[pit_accountant_panel]',
            ],
            'pit_client_page_url' => [
                'slug'      => 'podatnik',
                'title'     => __( 'Podatnik', 'obsluga-dokumentow-ksiegowych' ),
                'shortcode' => '[pit_client_page]',
            ],
        ];

        if ( ! isset( $page_configs[ $option_name ] ) ) {
            wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&error=invalid_option' ) );
            exit;
        }

        $config = $page_configs[ $option_name ];

        $existing_page = get_page_by_path( $config['slug'] );
        if ( $existing_page ) {
            wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&page_exists=1' ) );
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
            wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&error=create_failed' ) );
            exit;
        }

        $new_url = get_permalink( $page_id );
        update_option( $option_name, $new_url );

        wp_redirect( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings&page_created=1' ) );
        exit;
    }
}
