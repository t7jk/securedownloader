<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Klasa panelu księgowego wtyczki PIT-11 Manager.
 * Umożliwia wgrywanie dokumentów księgowych i zarządzanie nimi.
 */
class PIT_Accountant {

    /** @var PIT_Accountant|null Instancja singletona */
    private static ?PIT_Accountant $instance = null;

    /**
     * Konstruktor – rejestruje hooki WordPress.
     */
    private function __construct() {
        add_action( 'init',               [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_pit_upload_files', [ $this, 'handle_upload' ] );
        add_action( 'admin_post_nopriv_pit_upload_files', [ $this, 'handle_upload' ] );
        add_action( 'admin_post_pit_delete_file_front', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_nopriv_pit_delete_file_front', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_pit_generate_report', [ $this, 'handle_generate_report' ] );
        add_action( 'admin_post_nopriv_pit_generate_report', [ $this, 'handle_generate_report' ] );
        add_action( 'admin_post_pit_generate_report_pdf', [ $this, 'handle_generate_report_pdf' ] );
        add_action( 'admin_post_nopriv_pit_generate_report_pdf', [ $this, 'handle_generate_report_pdf' ] );
        add_action( 'admin_post_pit_bulk_delete_files', [ $this, 'handle_bulk_delete' ] );
        add_action( 'admin_post_nopriv_pit_bulk_delete_files', [ $this, 'handle_bulk_delete' ] );
        add_action( 'admin_post_pit_set_pesel_front', [ $this, 'handle_set_pesel_front' ] );
        add_action( 'admin_post_nopriv_pit_set_pesel_front', [ $this, 'handle_set_pesel_front' ] );
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
     * Rejestruje shortcode [pit_accountant_panel].
     */
    public function register_shortcode(): void {
        add_shortcode( 'pit_accountant_panel', [ $this, 'render_shortcode' ] );
    }

    /**
     * Parsuje nazwę pliku wg formatu: PIT-11_rok_2021_Dobosz_Marzena_89120508744.pdf
     *
     * @param string $filename Nazwa pliku.
     * @return array|false     Tablica z danymi lub false przy błędzie.
     */
    public function parse_filename( string $filename ): array|false {
        $name  = pathinfo( $filename, PATHINFO_FILENAME );
        $parts = explode( '_', $name );

        if ( count( $parts ) < 6 ) {
            return false;
        }

        if ( strtoupper( $parts[0] ) !== 'PIT-11' ) {
            return false;
        }

        $tax_year = (int) $parts[2];
        if ( $tax_year < 2000 || $tax_year > date( 'Y' ) + 1 ) {
            return false;
        }

        $pesel = $parts[5];
        if ( ! preg_match( '/^\d{11}$/', $pesel ) ) {
            return false;
        }

        $full_name = $parts[3] . ' ' . $parts[4];

        return [
            'tax_year'  => $tax_year,
            'full_name' => $full_name,
            'pesel'     => $pesel,
        ];
    }

    /**
     * Parsuje nazwę pliku wg jednego filtra w formacie {VAR} i /literal/ (od lewej do prawej).
     *
     * @param string $filename Pełna nazwa pliku (np. z rozszerzeniem .pdf).
     * @param string $filter    Ciąg z blokami {NAZWISKO}, {IMIĘ}, {RRRR}, {PPPPPPPPPPP} oraz /literal/.
     * @return array|false Tablica full_name, tax_year, pesel lub false.
     */
    private function parse_filename_brace_filter( string $filename, string $filter ): array|false {
        if ( strpos( $filter, '{' ) === false || strpos( $filter, '/' ) === false ) {
            return false;
        }
        $tokens = [];
        $pos    = 0;
        $len    = strlen( $filter );
        while ( $pos < $len ) {
            if ( $filter[ $pos ] === '{' ) {
                $end = strpos( $filter, '}', $pos );
                if ( $end === false ) {
                    return false;
                }
                $tokens[] = [ 'type' => 'capture', 'value' => substr( $filter, $pos + 1, $end - $pos - 1 ) ];
                $pos      = $end + 1;
                continue;
            }
            if ( $filter[ $pos ] === '/' ) {
                $end = strpos( $filter, '/', $pos + 1 );
                if ( $end === false ) {
                    return false;
                }
                $tokens[] = [ 'type' => 'literal', 'value' => substr( $filter, $pos + 1, $end - $pos - 1 ) ];
                $pos      = $end + 1;
                continue;
            }
            $pos++;
        }
        if ( empty( $tokens ) ) {
            return false;
        }
        $regex         = '^';
        $capture_names = [];
        foreach ( $tokens as $t ) {
            if ( $t['type'] === 'capture' ) {
                $capture_names[] = $t['value'];
                if ( $t['value'] === 'RRRR' ) {
                    $regex .= '(\d{4})';
                } elseif ( $t['value'] === 'PPPPPPPPPPP' ) {
                    $regex .= '(\d{11})';
                } else {
                    $regex .= '(.+?)';
                }
            } else {
                $literal_regex = preg_quote( $t['value'], '/' );
                $literal_regex = preg_replace( '/\s+/', '\\s+', $literal_regex );
                $regex .= $literal_regex;
            }
        }
        $regex .= '$';
        if ( ! preg_match( '/' . $regex . '/us', $filename, $m ) ) {
            return false;
        }
        $vars = [];
        $idx  = 1;
        foreach ( $capture_names as $name ) {
            if ( isset( $m[ $idx ] ) ) {
                $vars[ $name ] = $m[ $idx ];
            }
            $idx++;
        }
        $current_year = (int) date( 'Y' );
        $tax_year     = isset( $vars['RRRR'] ) ? (int) $vars['RRRR'] : $current_year - 1;
        if ( $tax_year < 2000 || $tax_year > $current_year + 1 ) {
            $tax_year = $current_year - 1;
        }
        $pesel = isset( $vars['PPPPPPPPPPP'] ) && preg_match( '/^\d{11}$/', $vars['PPPPPPPPPPP'] ) ? $vars['PPPPPPPPPPP'] : '';
        $nazwisko = isset( $vars['NAZWISKO'] ) ? pit_normalize_full_name( $vars['NAZWISKO'] ) : '';
        $imie     = isset( $vars['IMIĘ'] ) ? pit_normalize_full_name( $vars['IMIĘ'] ) : '';
        $full_name = trim( $nazwisko . ' ' . $imie );
        if ( $full_name === '' ) {
            return false;
        }
        return [
            'tax_year'  => $tax_year,
            'full_name' => $full_name,
            'pesel'     => $pesel,
        ];
    }

    /**
     * Parsuje nazwę pliku wg listy filtrów (segmenty rozdzielone * lub format {VAR}/literal/).
     * Np. "NAZWISKO IMIĘ*PIT-11*RRRR" → full_name, tytuł dokumentu, rok.
     *
     * @param string   $filename Nazwa pliku (np. Zalewska Natalia - PIT-11 (29) - rok 2025.pdf).
     * @param string[] $filters  Lista rekordów filtrów.
     * @return array|false Tablica full_name, tax_year, pesel (pusty gdy brak) lub false.
     */
    public function parse_filename_by_filters( string $filename, array $filters ): array|false {
        $base = pathinfo( $filename, PATHINFO_FILENAME );
        if ( $base === '' ) {
            return false;
        }

        $current_year = (int) date( 'Y' );
        $tax_year     = null;
        if ( preg_match( '/\b(19|20)\d{2}\b/', $base, $ym ) ) {
            $y = (int) $ym[0];
            if ( $y >= 2000 && $y <= $current_year + 1 ) {
                $tax_year = $y;
            }
        }
        if ( $tax_year === null ) {
            $tax_year = $current_year - 1;
        }

        $pesel = '';
        if ( preg_match( '/\b(\d{11})\b/', $base, $pm ) ) {
            $pesel = $pm[1];
        }

        $name_base = $base;
        $name_base = preg_replace( '/\b(19|20)\d{2}\b/', '', $name_base );
        $name_base = preg_replace( '/\b\d{11}\b/', '', $name_base );
        $name_base = preg_replace( '/PIT-11/i', '', $name_base );
        $name_base = preg_replace( '/Informacja\s+roczna/i', '', $name_base );
        $name_base = preg_replace( '/[-\s()]+/', ' ', $name_base );
        $name_base = preg_replace( '/\brok\b/i', '', $name_base );
        $full_name = pit_normalize_full_name( $name_base );
        $full_name = preg_replace( '/^dla\s+/iu', '', $full_name );

        // Dokument uznany za poprawny, gdy w nazwie jest PESEL lub da się wyciągnąć imię i nazwisko (dowolny rodzaj dokumentu).
        $doc_found = ( $pesel !== '' || $full_name !== '' );

        foreach ( $filters as $filter_str ) {
            $filter_str = trim( (string) $filter_str );
            if ( $filter_str === '' ) {
                continue;
            }
            if ( strpos( $filter_str, '{' ) !== false ) {
                $parsed = $this->parse_filename_brace_filter( $filename, $filter_str );
                if ( $parsed !== false ) {
                    return $parsed;
                }
                continue;
            }
            $segments = array_map( 'trim', explode( '*', $filter_str ) );
            $needs_pesel = false;
            $needs_doc   = false;
            $needs_name  = false;
            $name_seg_index = -1;
            foreach ( $segments as $i => $seg ) {
                if ( stripos( $seg, 'PESEL' ) !== false || stripos( $seg, 'PPPPPPPPPPP' ) !== false ) {
                    $needs_pesel = true;
                }
                if ( stripos( $seg, 'PIT-11' ) !== false || stripos( $seg, 'Informacja' ) !== false ) {
                    $needs_doc = true;
                }
                if ( stripos( $seg, 'Nazwisko' ) !== false || stripos( $seg, 'Imię' ) !== false ) {
                    $needs_name = true;
                    if ( $name_seg_index < 0 ) {
                        $name_seg_index = $i;
                    }
                }
            }
            if ( $needs_pesel && $pesel === '' ) {
                continue;
            }
            if ( $needs_doc && ! $doc_found ) {
                continue;
            }
            if ( $needs_name && $full_name === '' ) {
                return false;
            }
            if ( $needs_doc && ! $doc_found ) {
                return false;
            }

            // Wyciągnij imię i nazwisko według filtra: usuń z początku nazwy pliku literał (prefix) przed segmentem "Nazwisko Imię".
            if ( $name_seg_index > 0 ) {
                $literal_parts = array_slice( $segments, 0, $name_seg_index );
                $literal_prefix = implode( ' ', $literal_parts );
                $literal_prefix = preg_quote( $literal_prefix, '/' );
                $literal_prefix = preg_replace( '/\s+/', '[\\s\\-()]+', $literal_prefix );
                if ( preg_match( '/^' . $literal_prefix . '[\s\-()]*(.*)$/ius', $base, $m ) ) {
                    $rest = $m[1];
                    // Gdy literał to "Informacja roczna ", * mogło dopasować "dla " – usuń ten prefix z reszty.
                    if ( stripos( $literal_parts[0] ?? '', 'Informacja roczna' ) === 0 && preg_match( '/^dla\s+/iu', $rest ) ) {
                        $rest = preg_replace( '/^dla\s+/iu', '', $rest );
                    }
                    $rest = preg_replace( '/\b(19|20)\d{2}\b/', '', $rest );
                    $rest = preg_replace( '/\b\d{11}\b/', '', $rest );
                    $rest = preg_replace( '/\.pdf$/i', '', $rest );
                    $rest = preg_replace( '/[-\s()]+/', ' ', $rest );
                    $rest = pit_normalize_full_name( $rest );
                    if ( $rest !== '' ) {
                        $full_name = $rest;
                    }
                }
            } elseif ( $name_seg_index === 0 && count( $segments ) > 1 ) {
                // Filtr typu "NAZWISKO IMIĘ - PIT-11* rok RRRR.pdf" – imię i nazwisko to wszystko przed " - PIT-11" w nazwie pliku.
                $seg0 = $segments[0];
                if ( stripos( $seg0, 'PIT-11' ) !== false && ( stripos( $seg0, 'Nazwisko' ) !== false || stripos( $seg0, 'Imię' ) !== false ) ) {
                    if ( preg_match( '/^(.+?)\s*-\s*PIT-11\b/i', $base, $m ) ) {
                        $rest = pit_normalize_full_name( $m[1] );
                        if ( $rest !== '' ) {
                            $full_name = $rest;
                        }
                    }
                }
            } elseif ( $name_seg_index === 0 && count( $segments ) === 1 ) {
                $seg = $segments[0];
                // Filtr typu "NAZWISKO IMIĘ - PIT-11 (29) - rok RRRR.pdf" (jeden segment) – imię i nazwisko to wszystko przed " - PIT-11".
                if ( stripos( $seg, 'PIT-11' ) !== false && ( stripos( $seg, 'Nazwisko' ) !== false || stripos( $seg, 'Imię' ) !== false ) ) {
                    if ( preg_match( '/^(.+?)\s*-\s*PIT-11\b/i', $base, $m ) ) {
                        $rest = pit_normalize_full_name( $m[1] );
                        if ( $rest !== '' ) {
                            $full_name = $rest;
                        }
                    }
                }
                // Filtr "Informacja roczna dla NAZWISKO IMIĘ.pdf" – usuń znany prefix z początku nazwy pliku.
                elseif ( stripos( $seg, 'Informacja roczna dla' ) === 0 && ( stripos( $seg, 'Nazwisko' ) !== false || stripos( $seg, 'Imię' ) !== false ) ) {
                    $literal_prefix = preg_quote( 'Informacja roczna dla', '/' );
                    $literal_prefix = preg_replace( '/\s+/', '[\\s\\-()]+', $literal_prefix );
                    if ( preg_match( '/^' . $literal_prefix . '[\s\-()]*(.*)$/ius', $base, $m ) ) {
                        $rest = $m[1];
                        $rest = preg_replace( '/\b(19|20)\d{2}\b/', '', $rest );
                        $rest = preg_replace( '/\b\d{11}\b/', '', $rest );
                        $rest = preg_replace( '/\.pdf$/i', '', $rest );
                        $rest = preg_replace( '/[-\s()]+/', ' ', $rest );
                        $rest = pit_normalize_full_name( $rest );
                        if ( $rest !== '' ) {
                            $full_name = $rest;
                        }
                    }
                }
            }

            $out_name = $full_name !== '' ? $full_name : 'Nieznany';
            $out_name = preg_replace( '/^dla\s+/iu', '', $out_name );
            return [
                'tax_year'  => $tax_year,
                'full_name' => $out_name,
                'pesel'     => $pesel,
            ];
        }

        if ( $full_name === '' ) {
            return false;
        }
        if ( ! $doc_found ) {
            return false;
        }

        $full_name = preg_replace( '/^dla\s+/iu', '', $full_name );
        return [
            'tax_year'  => $tax_year,
            'full_name' => $full_name,
            'pesel'     => $pesel,
        ];
    }

    /**
     * Sprawdza dostęp użytkownika do panelu księgowego.
     *
     * @return bool True jeśli użytkownik ma dostęp.
     */
    public function check_access(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();
        
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        $allowed_user_ids = get_option( 'pit_accountant_users', [] );
        if ( ! is_array( $allowed_user_ids ) ) {
            $allowed_user_ids = [];
        }

        return in_array( $user->ID, $allowed_user_ids, true );
    }

    /**
     * Ładuje style na froncie.
     */
    public function enqueue_assets(): void {
        global $post;

        if ( $post && has_shortcode( $post->post_content, 'pit_accountant_panel' ) ) {
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
                'confirmDelete' => __( 'Czy na pewno usunąć ten dokument?', 'obsluga-dokumentow-ksiegowych' ),
                'errorPesel'    => __( 'PESEL musi składać się z 11 cyfr.', 'obsluga-dokumentow-ksiegowych' ),
            ] );
        }
    }

    /**
     * Renderuje shortcode panelu księgowego.
     *
     * @return string HTML panelu.
     */
    public function render_shortcode(): string {
        if ( ! get_option( 'pit_enabled', 1 ) ) {
            return '';
        }

        if ( ! $this->check_access() ) {
            if ( ! is_user_logged_in() ) {
                $login_url = wp_login_url( get_permalink() );
                return '<p class="pit-error">' . sprintf(
                    esc_html__( 'Musisz się zalogować. %s', 'obsluga-dokumentow-ksiegowych' ),
                    '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Zaloguj się', 'obsluga-dokumentow-ksiegowych' ) . '</a>'
                ) . '</p>';
            }
            return '<p class="pit-error">' . esc_html__( 'Brak dostępu do tego panelu.', 'obsluga-dokumentow-ksiegowych' ) . '</p>';
        }

        $message       = '';
        $message_class = 'pit-success';
        if ( isset( $_GET['pit_uploaded'] ) ) {
            $uploaded = (int) $_GET['pit_uploaded'];
            $errors   = (int) $_GET['pit_errors'];
            $skipped  = (int) ( $_GET['pit_skipped'] ?? 0 );
            $message  = sprintf(
                __( 'Wgrano %d dokumentów.', 'obsluga-dokumentow-ksiegowych' ),
                $uploaded
            );
            if ( $skipped > 0 ) {
                $message .= ' ' . sprintf( __( 'Pominięto %d (już istnieją).', 'obsluga-dokumentow-ksiegowych' ), $skipped );
            }
            if ( $errors > 0 ) {
                $message .= ' ' . sprintf( __( 'Błędów: %d.', 'obsluga-dokumentow-ksiegowych' ), $errors );
            }
        }
        $upload_failed_list = [];
        if ( ! empty( $_GET['pit_upload_failed'] ) && $_GET['pit_upload_failed'] === '1' ) {
            $upload_failed_list = get_transient( 'pit_upload_failed_files_' . get_current_user_id() );
            if ( is_array( $upload_failed_list ) ) {
                delete_transient( 'pit_upload_failed_files_' . get_current_user_id() );
            } else {
                $upload_failed_list = [];
            }
        }
        if ( isset( $_GET['pit_deleted'] ) && $_GET['pit_deleted'] === '1' ) {
            $message = __( 'Dokument został usunięty.', 'obsluga-dokumentow-ksiegowych' );
        }
        if ( isset( $_GET['pit_bulk_deleted'] ) ) {
            $count = (int) $_GET['pit_bulk_deleted'];
            $message = sprintf(
                _n( 'Usunięto %d dokument.', 'Usunięto %d dokumentów.', $count, 'obsluga-dokumentow-ksiegowych' ),
                $count
            );
        }
        if ( isset( $_GET['pit_set_pesel_ok'] ) && $_GET['pit_set_pesel_ok'] === '1' ) {
            $message = __( 'PESEL został zapisany.', 'obsluga-dokumentow-ksiegowych' );
        }
        if ( isset( $_GET['pit_set_pesel_error'] ) && $_GET['pit_set_pesel_error'] === '1' ) {
            $message       = __( 'Błąd: podaj prawidłowy PESEL (11 cyfr).', 'obsluga-dokumentow-ksiegowych' );
            $message_class = 'pit-error';
        }

        $db = PIT_Database::get_instance();
        set_time_limit( 90 );
        $this->maybe_pack_zip_for_same_person_year( $db );
        $this->fill_missing_pesel_after_upload( $db );
        $files = $db->get_all_files_sorted();

        ob_start();

        ?>
        <div class="pit-accountant-panel">
            <h2><?php esc_html_e( 'Panel Księgowego', 'obsluga-dokumentow-ksiegowych' ); ?></h2>

            <?php if ( $message ) : ?>
                <div class="pit-message <?php echo esc_attr( $message_class ); ?>">
                    <?php echo esc_html( $message ); ?>
                </div>
            <?php endif; ?>
            <?php if ( ! empty( $upload_failed_list ) ) : ?>
                <div class="pit-message pit-error">
                    <p><?php esc_html_e( 'Nie zaimportowano następujących plików:', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                    <ul class="pit-failed-files-list">
                        <?php foreach ( $upload_failed_list as $item ) : ?>
                            <li><strong><?php echo esc_html( $item['name'] ); ?></strong> — <?php echo esc_html( $item['reason'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <nav class="pit-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Zakładki panelu', 'obsluga-dokumentow-ksiegowych' ); ?>">
                <button type="button" class="pit-tab active" role="tab" id="pit-tab-btn-lista" aria-selected="true" aria-controls="pit-tab-lista" data-pit-tab="lista">
                    <?php esc_html_e( 'Lista dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
                <button type="button" class="pit-tab" role="tab" id="pit-tab-btn-upload" aria-selected="false" aria-controls="pit-tab-upload" data-pit-tab="upload">
                    <?php esc_html_e( 'Wgraj dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
                <button type="button" class="pit-tab" role="tab" id="pit-tab-btn-raport" aria-selected="false" aria-controls="pit-tab-raport" data-pit-tab="raport">
                    <?php esc_html_e( 'Generuj raport', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
            </nav>

            <div id="pit-tab-lista" class="pit-tab-panel active" role="tabpanel" aria-labelledby="pit-tab-btn-lista">
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Lista dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?></h3>

                <div id="pit-pesel-form-data" style="display:none;" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pit_set_pesel_front' ) ); ?>" data-url="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>"></div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-bulk-delete-form">
                <?php wp_nonce_field( 'pit_bulk_delete_nonce', 'pit_bulk_delete_nonce' ); ?>
                <input type="hidden" name="action" value="pit_bulk_delete_files">

                <table class="wp-list-table widefat fixed striped" id="pit-files-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pit-select-all"></th>
                            <th><?php esc_html_e( 'Lp.', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                            <th class="sortable" data-sort="name"><?php esc_html_e( 'Imię i nazwisko', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="pesel"><?php esc_html_e( 'PESEL', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="year"><?php esc_html_e( 'Rok', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="date"><?php esc_html_e( 'Data pobrania', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $files ) ) : ?>
                            <tr>
                                <td colspan="6"><?php esc_html_e( 'Brak dokumentów.', 'obsluga-dokumentow-ksiegowych' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php $i = 1; foreach ( $files as $file ) : 
                                $is_downloaded = ! empty( $file->last_download );
                            ?>
                                <tr class="<?php echo $is_downloaded ? '' : 'pit-not-downloaded'; ?>">
                                    <td><input type="checkbox" name="pit_delete_ids[]" value="<?php echo esc_attr( $file->id ); ?>" class="pit-checkbox"></td>
                                    <td class="pit-lp"><?php echo $i++; ?></td>
                                    <td data-name="<?php echo esc_attr( $file->full_name ); ?>"><?php echo esc_html( $file->full_name ); ?></td>
                                    <td data-pesel="<?php echo esc_attr( $file->pesel ?? '' ); ?>">
                                        <?php
                                        $pesel_empty = ( $file->pesel === '' || $file->pesel === null );
                                        if ( $pesel_empty ) :
                                            ?>
                                            <span class="pit-pesel-cell-empty">
                                                <a href="#" class="pit-brak-pesel-link"><?php esc_html_e( 'Brak PESEL', 'obsluga-dokumentow-ksiegowych' ); ?></a>
                                                <span class="pit-set-pesel-form" style="display:none;" data-full-name="<?php echo esc_attr( $file->full_name ); ?>">
                                                    <input type="text" class="pit-set-pesel-value" placeholder="<?php esc_attr_e( '11 cyfr', 'obsluga-dokumentow-ksiegowych' ); ?>" maxlength="11" pattern="\d{11}" size="11">
                                                    <button type="button" class="pit-set-pesel-save button button-small"><?php esc_html_e( 'Zapisz', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                                                </span>
                                            </span>
                                        <?php else : ?>
                                            <?php echo esc_html( substr( (string) $file->pesel, 0, 4 ) . '....' . substr( (string) $file->pesel, 8, 3 ) ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-year="<?php echo esc_attr( $file->tax_year ); ?>"><?php echo esc_html( $file->tax_year ); ?></td>
                                    <td data-date="<?php echo $is_downloaded ? esc_attr( $file->last_download ) : ''; ?>">
                                        <?php 
                                        echo $is_downloaded 
                                            ? esc_html( $file->last_download ) 
                                            : '<em>' . esc_html__( 'Nie pobrano', 'obsluga-dokumentow-ksiegowych' ) . '</em>'; 
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="pit-form-row" id="pit-bulk-actions" style="display:none; margin-top: 10px;">
                    <button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Czy na pewno usunąć zaznaczone dokumenty?', 'obsluga-dokumentow-ksiegowych' ); ?>')">
                        <?php esc_html_e( 'Usuń zaznaczone', 'obsluga-dokumentow-ksiegowych' ); ?>
                    </button>
                    <span id="pit-selected-count"></span>
                </div>
            </form>
            </div>

            <div id="pit-tab-upload" class="pit-tab-panel" role="tabpanel" aria-labelledby="pit-tab-btn-upload" hidden>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Wgraj dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?></h3>
                <p class="description">
                    <?php
                    $filters = get_option( 'pit_filename_filters', [] );
                    if ( ! empty( $filters ) && is_array( $filters ) ) {
                        esc_html_e( 'Aktywne filtry:', 'obsluga-dokumentow-ksiegowych' );
                        echo '<br>';
                        foreach ( array_slice( $filters, 0, 10 ) as $filter ) {
                            echo '<strong>' . esc_html( $filter ) . '</strong><br>';
                        }
                    }
                    ?>
                    <br>
                    <?php esc_html_e( 'Możesz wgrać 1 lub więcej dokumentów jednocześnie.', 'obsluga-dokumentow-ksiegowych' ); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'pit_upload_nonce', 'pit_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_upload_files">

                    <div class="pit-form-row">
                        <input type="file" name="pit_pdfs[]" multiple accept=".pdf" required>
                    </div>

                    <div class="pit-form-row">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Wgraj dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div id="pit-tab-raport" class="pit-tab-panel" role="tabpanel" aria-labelledby="pit-tab-btn-raport" hidden>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Raport pobrania dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'pit_report_pdf_nonce', 'pit_report_pdf_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_generate_report_pdf">

                    <div class="pit-form-row">
                        <label for="pit-report-year"><?php esc_html_e( 'Wybierz rok:', 'obsluga-dokumentow-ksiegowych' ); ?></label>
                        <select name="year" id="pit-report-year">
                            <option value="0"><?php esc_html_e( 'Wszystkie lata', 'obsluga-dokumentow-ksiegowych' ); ?></option>
                            <?php
                            $years = $db->get_available_years();
                            foreach ( $years as $y ) :
                            ?>
                                <option value="<?php echo esc_attr( $y ); ?>">
                                    <?php echo esc_html( $y ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pit-form-row">
                        <button type="submit" class="button">
                            <?php esc_html_e( 'Generuj raport PDF', 'obsluga-dokumentow-ksiegowych' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Obsługuje wgrywanie plików.
     */
    public function handle_upload(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_nonce'] ?? '', 'pit_upload_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( empty( $_FILES['pit_pdfs'] ) || empty( $_FILES['pit_pdfs']['name'] ) ) {
            wp_die( __( 'Nie wybrano dokumentów.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        set_time_limit( 120 );

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $uploaded   = 0;
        $errors     = 0;
        $skipped    = 0;
        $failed     = [];
        $db         = PIT_Database::get_instance();
        $filters    = get_option( 'pit_filename_filters', [] );
        $inserted_ids = [];

        $files = $_FILES['pit_pdfs'];
        $count = count( $files['name'] );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $files['error'][$i] !== UPLOAD_ERR_OK ) {
                $errors++;
                $failed[] = [
                    'name'   => $files['name'][$i],
                    'reason' => __( 'Błąd wgrywania pliku.', 'obsluga-dokumentow-ksiegowych' ),
                ];
                continue;
            }

            $tmp_name = $files['tmp_name'][$i];
            $name     = $files['name'][$i];
            $type     = $files['type'][$i];

            if ( 'application/pdf' !== $type && ! str_ends_with( strtolower( $name ), '.pdf' ) ) {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Nieprawidłowy typ pliku (wymagany PDF).', 'obsluga-dokumentow-ksiegowych' ),
                ];
                continue;
            }

            $parsed = null;
            if ( ! empty( $filters ) && is_array( $filters ) ) {
                $parsed = $this->parse_filename_by_filters( $name, $filters );
            }
            if ( $parsed === false ) {
                $parsed = $this->parse_filename( $name );
            }
            if ( ! $parsed ) {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Nie rozpoznano wzorca nazwy pliku.', 'obsluga-dokumentow-ksiegowych' ),
                ];
                continue;
            }

            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/obsluga-dokumentow-ksiegowych/' . $parsed['tax_year'] . '/';

            if ( ! is_dir( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
            }

            $safe_name   = sanitize_file_name( $name );
            $target_path = $target_dir . $safe_name;

            if ( ! move_uploaded_file( $tmp_name, $target_path ) ) {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Nie udało się zapisać pliku na serwerze.', 'obsluga-dokumentow-ksiegowych' ),
                ];
                continue;
            }

            $file_url = $upload_dir['baseurl'] . '/obsluga-dokumentow-ksiegowych/' . $parsed['tax_year'] . '/' . $safe_name;

            $result = $db->insert_file( [
                'full_name' => $parsed['full_name'],
                'pesel'     => $parsed['pesel'] ?? '',
                'tax_year'  => $parsed['tax_year'],
                'file_path' => $target_path,
                'file_url'  => $file_url,
            ] );

            if ( $result ) {
                $uploaded++;
                $inserted_ids[] = $result;
            } else {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Błąd zapisu do bazy danych.', 'obsluga-dokumentow-ksiegowych' ),
                ];
                if ( file_exists( $target_path ) ) {
                    unlink( $target_path );
                }
            }
        }

        if ( $uploaded > 0 ) {
            $this->fill_missing_pesel_from_db_only( $db );
        }

        $redirect = add_query_arg( [
            'pit_uploaded' => $uploaded,
            'pit_errors'   => $errors,
            'pit_skipped'  => $skipped,
        ], wp_get_referer() ?: home_url() );

        if ( ! empty( $failed ) ) {
            set_transient( 'pit_upload_failed_files_' . get_current_user_id(), $failed, 60 );
            $redirect = add_query_arg( 'pit_upload_failed', '1', $redirect );
        }

        wp_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Dla każdej pary (tax_year, full_name) z więcej niż jednym plikiem pakuje pliki do ZIP.
     */
    private function maybe_pack_zip_for_same_person_year( PIT_Database $db ): void {
        global $wpdb;

        $table = PIT_Database::$table_files;
        $rows  = $wpdb->get_results(
            "SELECT tax_year, full_name, COUNT(*) as cnt FROM {$table} GROUP BY tax_year, full_name HAVING cnt > 1",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] . '/obsluga-dokumentow-ksiegowych/';
        $base_url   = $upload_dir['baseurl'] . '/obsluga-dokumentow-ksiegowych/';

        foreach ( $rows as $row ) {
            $tax_year  = (int) $row['tax_year'];
            $full_name = $row['full_name'];
            $files     = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, file_path, pesel FROM {$table} WHERE tax_year = %d AND full_name = %s ORDER BY id ASC",
                    $tax_year,
                    $full_name
                )
            );

            $pdf_files = array_filter( $files, function ( $f ) {
                return str_ends_with( strtolower( $f->file_path ), '.pdf' );
            } );
            if ( count( $pdf_files ) < 2 ) {
                continue;
            }

            $zip_name = sanitize_file_name( $full_name . ' ' . $tax_year . '.zip' );
            $zip_path = $base_dir . $tax_year . '/' . $zip_name;

            if ( ! class_exists( 'ZipArchive' ) ) {
                continue;
            }

            $zip = new ZipArchive();
            if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
                continue;
            }

            $first_pesel = '';
            foreach ( $pdf_files as $file ) {
                if ( file_exists( $file->file_path ) ) {
                    $zip->addFile( $file->file_path, basename( $file->file_path ) );
                }
                if ( ! empty( $file->pesel ) ) {
                    $first_pesel = $file->pesel;
                }
            }
            $zip->close();

            $zip_url = $base_url . $tax_year . '/' . $zip_name;

            $db->insert_file( [
                'full_name' => $full_name,
                'pesel'     => $first_pesel,
                'tax_year'  => $tax_year,
                'file_path' => $zip_path,
                'file_url'  => $zip_url,
            ] );

            foreach ( $pdf_files as $file ) {
                if ( file_exists( $file->file_path ) ) {
                    unlink( $file->file_path );
                }
                $db->delete_file( (int) $file->id );
            }
        }
    }

    /**
     * Uzupełnia brakujący PESEL wyłącznie z bazy (inna pozycja tej samej osoby). Bez wywołań pdftotext.
     */
    private function fill_missing_pesel_from_db_only( PIT_Database $db ): void {
        $persons = $db->get_person_ids_with_empty_pesel();
        foreach ( $persons as $full_name ) {
            $pesel = $db->get_pesel_by_full_name( $full_name );
            if ( $pesel !== null ) {
                $db->update_pesel_for_person( $full_name, $pesel );
            }
        }
    }

    /**
     * Uzupełnia brakujący PESEL: najpierw z bazy (inna pozycja tej osoby), potem z treści PDF.
     * Gdy w PDF jest więcej niż jeden różny PESEL – nie przypisuje, zgłasza błąd.
     */
    private function fill_missing_pesel_after_upload( PIT_Database $db ): void {
        $persons = $db->get_person_ids_with_empty_pesel();
        foreach ( $persons as $full_name ) {
            $pesel = $db->get_pesel_by_full_name( $full_name );
            if ( $pesel !== null ) {
                $db->update_pesel_for_person( $full_name, $pesel );
                continue;
            }
            $pesel = $this->extract_pesel_from_person_pdfs( $full_name, $db );
            if ( $pesel !== null ) {
                $db->update_pesel_for_person( $full_name, $pesel );
            }
        }
    }

    /**
     * Próbuje wyciągnąć jeden PESEL z plików PDF danej osoby. Gdy wiele różnych – zwraca null.
     *
     * @param string       $full_name full_name osoby.
     * @param PIT_Database $db        Instancja bazy.
     * @return string|null PESEL lub null.
     */
    private function extract_pesel_from_person_pdfs( string $full_name, PIT_Database $db ): ?string {
        global $wpdb;

        $key  = pit_person_match_key( $full_name );
        if ( $key === '' ) {
            return null;
        }

        $table = PIT_Database::$table_files;
        $all   = $wpdb->get_results(
            "SELECT id, full_name, file_path FROM {$table} WHERE (pesel IS NULL OR pesel = '') ORDER BY id ASC",
            OBJECT
        );
        $rows = array_filter( $all, function ( $row ) use ( $key ) {
            return pit_person_match_key( (string) $row->full_name ) === $key;
        } );

        $found_pesels = [];
        foreach ( $rows as $row ) {
            if ( ! file_exists( $row->file_path ) ) {
                continue;
            }
            $text = $this->extract_text_from_pdf( $row->file_path );
            if ( $text === '' ) {
                continue;
            }
            if ( preg_match_all( '/\b(\d{11})\b/', $text, $m ) ) {
                foreach ( $m[1] as $p ) {
                    $found_pesels[ $p ] = true;
                }
            }
        }

        $unique = array_keys( $found_pesels );
        if ( count( $unique ) === 1 ) {
            return $unique[0];
        }
        if ( count( $unique ) > 1 ) {
            return null;
        }
        return null;
    }

    /**
     * Wyciąga tekst z pliku PDF (jeśli dostępne narzędzie).
     *
     * @param string $file_path Ścieżka do PDF.
     * @return string Tekst lub pusty.
     */
    private function extract_text_from_pdf( string $file_path ): string {
        if ( ! file_exists( $file_path ) ) {
            return '';
        }
        $path = escapeshellarg( $file_path );
        $cmd = "pdftotext -layout {$path} - 2>/dev/null";
        if ( function_exists( 'shell_exec' ) && ! ini_get( 'safe_mode' ) ) {
            $out = @shell_exec( $cmd );
            return is_string( $out ) ? $out : '';
        }
        return '';
    }

    /**
     * Obsługuje usuwanie pliku z frontu.
     */
    public function handle_delete(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $file_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_delete_' . $file_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $db = PIT_Database::get_instance();
        $db->delete_file( $file_id );

        $redirect = add_query_arg( 'pit_deleted', '1', wp_get_referer() ?: home_url() );
        wp_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Obsługuje masowe usuwanie plików.
     */
    public function handle_bulk_delete(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_bulk_delete_nonce'] ?? '', 'pit_bulk_delete_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $ids = $_POST['pit_delete_ids'] ?? [];
        if ( ! is_array( $ids ) ) {
            $ids = [];
        }

        $db = PIT_Database::get_instance();
        $count = 0;

        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && $db->delete_file( $id ) ) {
                $count++;
            }
        }

        $redirect = add_query_arg( 'pit_bulk_deleted', $count, wp_get_referer() ?: home_url() );
        wp_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Obsługuje ręczne ustawienie PESEL z panelu księgowego (link „Brak PESEL”).
     */
    public function handle_set_pesel_front(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_set_pesel_front_nonce'] ?? '', 'pit_set_pesel_front' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $full_name = sanitize_text_field( $_POST['pit_set_pesel_full_name'] ?? '' );
        $pesel     = sanitize_text_field( $_POST['pit_set_pesel_value'] ?? '' );
        $pesel     = preg_replace( '/\D/', '', $pesel );

        if ( $full_name === '' || strlen( $pesel ) !== 11 ) {
            $redirect = add_query_arg( 'pit_set_pesel_error', '1', wp_get_referer() ?: home_url() );
            wp_redirect( esc_url_raw( $redirect ) );
            exit;
        }

        $db = PIT_Database::get_instance();
        $db->update_pesel_for_person( $full_name, $pesel );

        $redirect = add_query_arg( 'pit_set_pesel_ok', '1', wp_get_referer() ?: home_url() );
        wp_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Generuje i wysyła raport HTML.
     */
    public function handle_generate_report(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_report_nonce'] ?? '', 'pit_report_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $year = (int) ( $_POST['year'] ?? date( 'Y' ) );
        $this->generate_report( $year );
    }

    /**
     * Generuje i wysyła raport PDF.
     */
    public function handle_generate_report_pdf(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_report_pdf_nonce'] ?? '', 'pit_report_pdf_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $year = (int) ( $_POST['year'] ?? date( 'Y' ) );
        $this->generate_report_pdf( $year );
    }

    /**
     * Generuje raport HTML.
     *
     * @param int $year Rok podatkowy.
     */
    public function generate_report( int $year ): void {
        $db    = PIT_Database::get_instance();
        $data  = $db->get_report_data( $year );

        $company_name    = get_option( 'pit_company_name', '' );
        $company_address = get_option( 'pit_company_address', '' );
        $company_nip     = get_option( 'pit_company_nip', '' );
        $generated_at    = date_i18n( 'Y-m-d H:i:s' );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html__( 'Raport dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?> – <?php echo esc_html( $year ); ?></title>
    <style>
        body { font-family: Georgia, 'Times New Roman', serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        h1 { font-size: 24px; margin-bottom: 5px; }
        h2 { font-size: 18px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-family: Arial, sans-serif; font-size: 14px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        tr:nth-child(even) { background: #f9f9f9; }
        .not-downloaded { background: #fff8e1; }
        .downloaded { color: #2e7d32; }
        footer { margin-top: 40px; font-size: 12px; color: #666; text-align: center; }
    </style>
</head>
<body>
    <header>
        <h1><?php echo esc_html( $company_name ); ?></h1>
        <?php if ( $company_address ) : ?>
            <p><?php echo esc_html( $company_address ); ?></p>
        <?php endif; ?>
        <?php if ( $company_nip ) : ?>
            <p>NIP: <?php echo esc_html( $company_nip ); ?></p>
        <?php endif; ?>
        <h2><?php echo esc_html__( 'Raport pobrania dokumentów za rok', 'obsluga-dokumentow-ksiegowych' ); ?> <?php echo esc_html( $year ); ?></h2>
        <p>Wygenerowano: <?php echo esc_html( $generated_at ); ?></p>
    </header>

    <table>
        <thead>
            <tr>
                <th>Lp.</th>
                <th>Imię i nazwisko</th>
                <th>PESEL</th>
                <th>Data pobrania</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ( $data as $row ) :
                $is_downloaded = ! empty( $row->downloaded_at );
            ?>
                <tr class="<?php echo $is_downloaded ? '' : 'not-downloaded'; ?>">
                    <td><?php echo $i++; ?></td>
                    <td><?php echo esc_html( $row->full_name ); ?></td>
                    <td><?php echo esc_html( $row->pesel ); ?></td>
                    <td>
                        <?php echo $is_downloaded ? esc_html( $row->downloaded_at ) : '—'; ?>
                    </td>
                    <td class="<?php echo $is_downloaded ? 'downloaded' : ''; ?>">
                        <?php echo $is_downloaded ? esc_html__( 'Pobrano', 'obsluga-dokumentow-ksiegowych' ) : esc_html__( 'Nie pobrano', 'obsluga-dokumentow-ksiegowych' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer>
        <p><?php echo esc_html__( 'Dokument wygenerowany przez Obsługa dokumentów księgowych', 'obsluga-dokumentow-ksiegowych' ); ?></p>
    </footer>
</body>
</html>
        <?php

        $html = ob_get_clean();

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="raport-dokumentow-' . $year . '.html"' );
        echo $html;
        exit;
    }

    /**
     * Generuje raport PDF (jako HTML z rozszerzeniem .pdf do druku).
     *
     * @param int $year Rok podatkowy (0 = wszystkie).
     */
    public function generate_report_pdf( int $year = 0 ): void {
        $db    = PIT_Database::get_instance();
        $data  = $db->get_report_data( $year );

        $company_name    = get_option( 'pit_company_name', '' );
        $company_address = get_option( 'pit_company_address', '' );
        $company_nip     = get_option( 'pit_company_nip', '' );
        $generated_at    = date_i18n( 'Y-m-d H:i:s' );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_attr__( 'Raport dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body { font-family: Georgia, 'Times New Roman', serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        h1 { font-size: 24px; margin-bottom: 5px; }
        h2 { font-size: 18px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-family: Arial, sans-serif; font-size: 14px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        tr:nth-child(even) { background: #f9f9f9; }
        .not-downloaded { background: #fff8e1; }
        .downloaded { color: #2e7d32; }
        footer { margin-top: 40px; font-size: 12px; color: #666; text-align: center; }
        .print-btn { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="no-print print-btn">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
            <?php esc_html_e( 'Drukuj / Zapisz jako PDF', 'obsluga-dokumentow-ksiegowych' ); ?>
        </button>
    </div>

    <header>
        <h1><?php echo esc_html( $company_name ); ?></h1>
        <?php if ( $company_address ) : ?>
            <p><?php echo esc_html( $company_address ); ?></p>
        <?php endif; ?>
        <?php if ( $company_nip ) : ?>
            <p>NIP: <?php echo esc_html( $company_nip ); ?></p>
        <?php endif; ?>
        <h2><?php echo esc_html__( 'Raport pobrania dokumentów', 'obsluga-dokumentow-ksiegowych' ); ?><?php echo $year > 0 ? ' ' . esc_html__( 'za rok', 'obsluga-dokumentow-ksiegowych' ) . ' ' . esc_html( $year ) : ''; ?></h2>
        <p>Wygenerowano: <?php echo esc_html( $generated_at ); ?></p>
    </header>

    <table>
        <thead>
            <tr>
                <th>Lp.</th>
                <th>Imię i nazwisko</th>
                <th>PESEL</th>
                <?php if ( $year === 0 ) : ?>
                <th>Rok</th>
                <?php endif; ?>
                <th>Data pobrania</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ( $data as $row ) :
                $is_downloaded = ! empty( $row->downloaded_at );
            ?>
                <tr class="<?php echo $is_downloaded ? '' : 'not-downloaded'; ?>">
                    <td><?php echo $i++; ?></td>
                    <td><?php echo esc_html( $row->full_name ); ?></td>
                    <td><?php echo esc_html( $row->pesel ); ?></td>
                    <?php if ( $year === 0 ) : ?>
                    <td><?php echo esc_html( $row->tax_year ); ?></td>
                    <?php endif; ?>
                    <td>
                        <?php echo $is_downloaded ? esc_html( $row->downloaded_at ) : '—'; ?>
                    </td>
                    <td class="<?php echo $is_downloaded ? 'downloaded' : ''; ?>">
                        <?php echo $is_downloaded ? esc_html__( 'Pobrano', 'obsluga-dokumentow-ksiegowych' ) : esc_html__( 'Nie pobrano', 'obsluga-dokumentow-ksiegowych' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer>
        <p><?php echo esc_html__( 'Dokument wygenerowany przez Obsługa dokumentów księgowych', 'obsluga-dokumentow-ksiegowych' ); ?></p>
    </footer>
</body>
</html>
        <?php

        $html = ob_get_clean();

        echo $html;
        exit;
    }
}
