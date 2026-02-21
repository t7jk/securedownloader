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
        add_action( 'admin_post_pit_remove_accountant', [ $this, 'handle_remove_accountant' ] );
        add_action( 'update_option_pit_accountant_users', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_accountant_page_url', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_client_page_url', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_company_name', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_company_address', [ $this, 'redirect_after_save' ] );
        add_action( 'update_option_pit_company_nip', [ $this, 'redirect_after_save' ] );
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
            __( 'Obsługa PIT', 'obsluga-pit' ),
            __( 'Obsługa PIT', 'obsluga-pit' ),
            'manage_options',
            'obsluga-pit-settings',
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

        add_settings_section(
            'pit_main_settings',
            '',
            null,
            'obsluga-pit-settings'
        );

        add_settings_field(
            'pit_company_name',
            __( 'Nazwa firmy', 'obsluga-pit' ),
            [ $this, 'render_field_text' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 'name' => 'pit_company_name' ]
        );

        add_settings_field(
            'pit_company_address',
            __( 'Adres firmy', 'obsluga-pit' ),
            [ $this, 'render_field_text' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 'name' => 'pit_company_address' ]
        );

        add_settings_field(
            'pit_company_nip',
            __( 'NIP firmy', 'obsluga-pit' ),
            [ $this, 'render_field_text' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 'name' => 'pit_company_nip' ]
        );

        add_settings_field(
            'pit_accountant_page_url',
            __( 'URL strony księgowego', 'obsluga-pit' ),
            [ $this, 'render_field_url' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_accountant_page_url',
                'description' => __( 'Utwórz podstronę [/ksiegowy] z kodem [pit_accountant_panel] dla Księgowego', 'obsluga-pit' )
            ]
        );

        add_settings_field(
            'pit_client_page_url',
            __( 'URL strony podatnika', 'obsluga-pit' ),
            [ $this, 'render_field_url' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_client_page_url',
                'description' => __( 'Utwórz podstronę [/podatnik] z kodem [pit_client_page] dla Podatnika', 'obsluga-pit' )
            ]
        );

        add_settings_field(
            'pit_accountant_users',
            __( 'Wybierz księgowego', 'obsluga-pit' ),
            [ $this, 'render_field_users' ],
            'obsluga-pit-settings',
            'pit_main_settings',
            [ 
                'name'        => 'pit_accountant_users',
                'description' => __( 'Zaznacz użytkowników, którzy będą mieli dostęp do panelu księgowego.', 'obsluga-pit' )
            ]
        );
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
        $name  = $args['name'];
        $value = get_option( $name, '' );
        printf(
            '<input type="url" name="%s" value="%s" class="regular-text" placeholder="https://">',
            esc_attr( $name ),
            esc_attr( $value )
        );
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
            esc_html__( 'Tak', 'obsluga-pit' )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    public function sanitize_user_ids( array $input ): array {
        $user_ids = [];

        foreach ( $input as $user_id ) {
            $id = (int) $user_id;
            if ( $id > 0 && get_user_by( 'id', $id ) ) {
                $user_ids[] = $id;
            }
        }

        return array_unique( $user_ids );
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
            echo '<p class="description">' . esc_html__( 'Brak użytkowników do wyświetlenia.', 'obsluga-pit' ) . '</p>';
            return;
        }

        printf(
            '<select name="%s[]" multiple="multiple" style="min-width: 300px; min-height: 150px;">',
            esc_attr( $name )
        );
        
        foreach ( $users as $user ) {
            $selected = in_array( $user->ID, $selected_ids, true );
            printf(
                '<option value="%d" %s>%s</option>',
                $user->ID,
                selected( $selected, true, false ),
                esc_html( $user->user_login )
            );
        }
        echo '</select>';

        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }

        if ( ! empty( $selected_ids ) ) {
            echo '<h4 style="margin-top: 15px; margin-bottom: 5px;">' . esc_html__( 'Lista obecnych księgowych:', 'obsluga-pit' ) . '</h4>';
            echo '<table class="wp-list-table widefat fixed striped" style="max-width: 400px;">';
            echo '<thead><tr><th>' . esc_html__( 'Login', 'obsluga-pit' ) . '</th><th style="width: 80px;">' . esc_html__( 'Akcje', 'obsluga-pit' ) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ( $selected_ids as $user_id ) {
                $user = get_user_by( 'id', $user_id );
                if ( ! $user ) continue;
                echo '<tr>';
                echo '<td>' . esc_html( $user->user_login ) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pit_remove_accountant&user_id=' . $user_id ), 'pit_remove_accountant_' . $user_id ) ) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_attr__( 'Czy na pewno usunąć tego księgowego?', 'obsluga-pit' ) . '\')">';
                echo esc_html__( 'Usuń', 'obsluga-pit' );
                echo '</a>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
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
        if ( ! str_contains( $hook, 'obsluga-pit' ) ) {
            return;
        }

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
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pit_manager_nonce' ),
            'confirmDelete' => __( 'Czy na pewno usunąć ten plik?', 'obsluga-pit' ),
        ] );
    }

    /**
     * Renderuje główny panel administratora.
     */
    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-pit' ) );
        }

        $db     = PIT_Database::get_instance();
        $years  = $db->get_available_years();
        $year   = isset( $_GET['year'] ) ? (int) $_GET['year'] : ( $years[0] ?? date( 'Y' ) );
        $files  = $db->get_all_files( $year );

        ?>
        <div class="wrap obsluga-pit-wrap">
            <h1><?php esc_html_e( 'Wgrane PIT-y', 'obsluga-pit' ); ?></h1>

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
                        window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=obsluga-pit' ) ); ?>&year=' + this.value;
                    });
                    </script>
                </div>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Lp.', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'Imię i nazwisko', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'PESEL', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'Rok', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'Wgrano', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'Data pobrania', 'obsluga-pit' ); ?></th>
                        <th><?php esc_html_e( 'Akcje', 'obsluga-pit' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $files ) ) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'Brak dokumentów dla wybranego roku.', 'obsluga-pit' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php $i = 1; foreach ( $files as $file ) : 
                            $is_downloaded = ! empty( $file->last_download );
                        ?>
                            <tr class="<?php echo $is_downloaded ? '' : 'pit-not-downloaded'; ?>">
                                <td><?php echo $i++; ?></td>
                                <td><?php echo esc_html( $file->full_name ); ?></td>
                                <td><?php echo esc_html( $file->pesel ); ?></td>
                                <td><?php echo esc_html( $file->tax_year ); ?></td>
                                <td><?php echo esc_html( $file->uploaded_at ); ?></td>
                                <td>
                                    <?php 
                                    echo $is_downloaded 
                                        ? esc_html( $file->last_download ) 
                                        : '<em>' . esc_html__( 'Nie pobrano', 'obsluga-pit' ) . '</em>'; 
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pit_delete_file&id=' . $file->id ), 'pit_delete_' . $file->id ) ); ?>"
                                       class="button button-small button-link-delete pit-confirm-delete">
                                        <?php esc_html_e( 'Usuń', 'obsluga-pit' ); ?>
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
            wp_die( __( 'Brak uprawnień.', 'obsluga-pit' ) );
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
        <div class="wrap obsluga-pit-wrap">
            <h1><?php esc_html_e( 'Ustawienia Obsługa PIT', 'obsluga-pit' ); ?></h1>

            <?php if ( empty( $accountant_url ) || empty( $client_url ) || $accountant_shortcode_missing || $client_shortcode_missing ) : ?>
                <div class="notice notice-warning is-dismissible" style="margin-top: 10px;">
                    <p><strong><?php esc_html_e( 'Uwaga!', 'obsluga-pit' ); ?></strong></p>
                    <?php if ( empty( $accountant_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony księgowego. Utwórz podstronę [/ksiegowy] z kodem [pit_accountant_panel] i wpisz jej URL powyżej.', 'obsluga-pit' ); ?></p>
                    <?php elseif ( $accountant_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona księgowego nie zawiera shortcode [pit_accountant_panel]. Dodaj go do treści strony.', 'obsluga-pit' ); ?></p>
                    <?php endif; ?>
                    <?php if ( empty( $client_url ) ) : ?>
                        <p><?php esc_html_e( 'Brak skonfigurowanej strony podatnika. Utwórz podstronę [/podatnik] z kodem [pit_client_page] i wpisz jej URL powyżej.', 'obsluga-pit' ); ?></p>
                    <?php elseif ( $client_shortcode_missing ) : ?>
                        <p><?php esc_html_e( 'Strona podatnika nie zawiera shortcode [pit_client_page]. Dodaj go do treści strony.', 'obsluga-pit' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields( 'pit_options_group' );
                do_settings_sections( 'obsluga-pit-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Przekierowuje do strony księgowego.
     */
    public function redirect_to_accountant_page(): void {
        $url = get_option( 'pit_accountant_page_url', home_url() );
        echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
        echo '<p>' . esc_html__( 'Przekierowywanie...', 'obsluga-pit' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Kliknij tutaj', 'obsluga-pit' ) . '</a>.</p>';
        exit;
    }

    /**
     * Przekierowuje po zapisaniu ustawień.
     */
    public function redirect_after_save(): void {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ustawienia zapisane.', 'obsluga-pit' ) . '</p></div>';
        } );
    }

    /**
     * Obsługuje usuwanie pliku.
     */
    public function handle_delete_file(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-pit' ) );
        }

        $file_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_delete_' . $file_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-pit' ) );
        }

        $db = PIT_Database::get_instance();
        $db->delete_file( $file_id );

        wp_redirect( admin_url( 'admin.php?page=obsluga-pit&deleted=1' ) );
        exit;
    }

    /**
     * Obsługuje usuwanie księgowego z listy.
     */
    public function handle_remove_accountant(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-pit' ) );
        }

        $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_remove_accountant_' . $user_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-pit' ) );
        }

        $accountant_ids = get_option( 'pit_accountant_users', [] );
        if ( ! is_array( $accountant_ids ) ) {
            $accountant_ids = [];
        }

        $accountant_ids = array_filter( $accountant_ids, fn( $id ) => $id !== $user_id );
        update_option( 'pit_accountant_users', array_values( $accountant_ids ) );

        wp_redirect( admin_url( 'admin.php?page=obsluga-pit-settings&accountant_removed=1' ) );
        exit;
    }
}
