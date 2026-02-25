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
        add_action( 'admin_post_pit_save_company_data', [ $this, 'handle_save_company_data' ] );
        add_action( 'admin_post_nopriv_pit_save_company_data', [ $this, 'handle_save_company_data' ] );
        add_action( 'admin_post_pit_save_import_patterns', [ $this, 'handle_save_import_patterns' ] );
        add_action( 'admin_post_nopriv_pit_save_import_patterns', [ $this, 'handle_save_import_patterns' ] );
        add_action( 'admin_post_pit_reset_import_patterns', [ $this, 'handle_reset_import_patterns' ] );
        add_action( 'admin_post_nopriv_pit_reset_import_patterns', [ $this, 'handle_reset_import_patterns' ] );
        add_action( 'admin_post_pit_delete_downloaded_files', [ $this, 'handle_delete_downloaded_files' ] );
        add_action( 'admin_post_nopriv_pit_delete_downloaded_files', [ $this, 'handle_delete_downloaded_files' ] );
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
        $filename = trim( $filename );
        $filename = basename( $filename );
        if ( $filename === '' ) {
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
     * Zwraca listę domyślnych wzorców importu (używana przy instalacji i przy Resecie).
     *
     * @return array<int, array{wzorzec: string, strona: string, pozycja: string, pole: string, nazwa_pliku: string}>
     */
    private function get_default_import_patterns(): array {
        return [
            [ 'wzorzec' => 'PESEL', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => 'numer PESEL', 'nazwa_pliku' => '*PIT-11*.pdf' ],
            [ 'wzorzec' => 'PESEL', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Identyfikator', 'nazwa_pliku' => 'Informacja roczna dla*.pdf' ],
            [ 'wzorzec' => 'NAZWISKO', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Nazwisko', 'nazwa_pliku' => 'Informacja roczna*.pdf' ],
            [ 'wzorzec' => 'IMIĘ', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Imię', 'nazwa_pliku' => 'Informacja roczna*.pdf' ],
            [ 'wzorzec' => 'IMIĘ', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => '17 Pierwsze imie', 'nazwa_pliku' => '*PIT-11*.pdf' ],
            [ 'wzorzec' => 'NAZWISKO', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => '16. Nazwisko', 'nazwa_pliku' => '*PIT-11*.pdf' ],
        ];
    }

    /**
     * Zwraca wzorce importu (Wzorzec, Strona, Sekcja, Pole, Nazwa pliku) – używane przy imporcie do wyszukiwania PESEL itd.
     *
     * @return array<int, array{wzorzec: string, strona: string, pozycja: string, pole: string, nazwa_pliku: string}>
     */
    private function get_import_patterns_option(): array {
        $raw = get_option( 'pit_import_patterns', null );
        if ( is_array( $raw ) && ! empty( $raw ) ) {
            $out = [];
            foreach ( $raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $out[] = [
                    'wzorzec'     => isset( $row['wzorzec'] ) ? sanitize_text_field( (string) $row['wzorzec'] ) : '',
                    'strona'      => isset( $row['strona'] ) ? sanitize_text_field( (string) $row['strona'] ) : '',
                    'pozycja'     => isset( $row['pozycja'] ) ? sanitize_text_field( (string) $row['pozycja'] ) : '',
                    'pole'        => isset( $row['pole'] ) ? sanitize_text_field( (string) $row['pole'] ) : '',
                    'nazwa_pliku' => isset( $row['nazwa_pliku'] ) ? sanitize_text_field( (string) $row['nazwa_pliku'] ) : '',
                ];
            }
            $out = array_values( array_filter( $out, function ( $r ) {
                return $r['wzorzec'] !== '' || $r['pozycja'] !== '' || $r['pole'] !== '';
            } ) );
            if ( ! empty( $out ) ) {
                return $out;
            }
        }
        return $this->get_default_import_patterns();
    }

    /**
     * Sprawdza, czy nazwa pliku pasuje do wzorca (wzorzec może zawierać * jako wildcard).
     *
     * @param string $filename Nazwa pliku (np. Kowalski Jan PIT-11 2024.pdf).
     * @param string $pattern  Wzorzec (np. *PIT-11*.pdf lub Informacja roczna dla*.pdf).
     * @return bool
     */
    private function filename_matches_pattern( string $filename, string $pattern ): bool {
        if ( $pattern === '' ) {
            return true;
        }
        $parts = explode( '*', $pattern );
        $regex = '';
        foreach ( $parts as $part ) {
            $quoted = preg_quote( $part, '/' );
            // Nazwy plików po sanitize_file_name() mają spacje zamienione na myślniki – traktuj spację i myślnik jako równoważne.
            $quoted = str_replace( ' ', '[ \\x2d]+', $quoted );
            $regex .= $quoted;
            $regex .= '.*';
        }
        $regex = rtrim( $regex, '.*' );
        return (bool) preg_match( '/^' . $regex . '$/iu', $filename );
    }

    /**
     * Zwraca reguły wyszukiwania (PESEL itd.) w dokumentach – wyłącznie z wzorców (zakładka Wzorce).
     *
     * @param string $filename Opcjonalna nazwa pliku – jeśli podana, zwracane są tylko reguły pasujące do nazwy pliku.
     * @return array<int, array{szukany_numer: string, nazwa_naglowka: string, nazwa_sekcji: string, nr_pola: string, nazwa_pliku: string}>
     */
    private function get_pesel_search_rules_option( string $filename = '' ): array {
        $patterns = $this->get_import_patterns_option();
        $from_patterns = [];
        foreach ( $patterns as $p ) {
            if ( ( $p['wzorzec'] === '' && $p['pozycja'] === '' && $p['pole'] === '' ) ) {
                continue;
            }
            $nazwa_pliku = $p['nazwa_pliku'] ?? '';
            $nazwa_naglowka = ( $nazwa_pliku !== '' && stripos( $nazwa_pliku, 'Informacja' ) !== false ) ? 'Informacja' : 'PIT-11';
            $from_patterns[] = [
                'szukany_numer'  => $p['wzorzec'] !== '' ? $p['wzorzec'] : 'PESEL',
                'nazwa_naglowka' => $nazwa_naglowka,
                'nazwa_sekcji'   => $p['pozycja'],
                'nr_pola'        => $p['pole'],
                'nazwa_pliku'    => $nazwa_pliku,
            ];
        }
        if ( ! empty( $from_patterns ) ) {
            if ( $filename !== '' ) {
                $filtered = array_values( array_filter( $from_patterns, function ( $rule ) use ( $filename ) {
                    $pat = $rule['nazwa_pliku'] ?? '';
                    return $pat === '' || $this->filename_matches_pattern( $filename, $pat );
                } ) );
                if ( ! empty( $filtered ) ) {
                    return $filtered;
                }
            }
            return $from_patterns;
        }
        return [];
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
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'uploadPostUrl'    => admin_url( 'admin-post.php' ),
                'uploadChunkSize'  => (int) PIT_UPLOAD_CHUNK_SIZE,
                'uploadProgressLabel' => __( 'Wgrywanie pliku %1$s z %2$s', 'obsluga-dokumentow-ksiegowych' ),
                'nonce'            => wp_create_nonce( 'pit_manager_nonce' ),
                'confirmDelete'      => __( 'Czy na pewno usunąć ten dokument?', 'obsluga-dokumentow-ksiegowych' ),
                'confirmBulkDelete'  => __( 'Czy na pewno usunąć zaznaczone dokumenty?', 'obsluga-dokumentow-ksiegowych' ),
                'errorPesel'       => __( 'PESEL musi składać się z 11 cyfr.', 'obsluga-dokumentow-ksiegowych' ),
            ] );
        }
    }

    /**
     * Renderuje shortcode panelu księgowego.
     *
     * @return string HTML panelu.
     */
    public function render_shortcode(): string {
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
        if ( isset( $_GET['pit_downloaded_deleted'] ) ) {
            $count = (int) $_GET['pit_downloaded_deleted'];
            $message = sprintf(
                _n( 'Usunięto %d pobrany dokument z serwera.', 'Usunięto %d pobranych dokumentów z serwera.', $count, 'obsluga-dokumentow-ksiegowych' ),
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
        if ( isset( $_GET['pit_company_saved'] ) && $_GET['pit_company_saved'] === '1' ) {
            $message = __( 'Dane firmy zostały zapisane.', 'obsluga-dokumentow-ksiegowych' );
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
                    <?php esc_html_e( 'Dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
                <button type="button" class="pit-tab" role="tab" id="pit-tab-btn-upload" aria-selected="false" aria-controls="pit-tab-upload" data-pit-tab="upload">
                    <?php esc_html_e( 'Wgrywanie', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
                <button type="button" class="pit-tab" role="tab" id="pit-tab-btn-wzorce" aria-selected="false" aria-controls="pit-tab-wzorce" data-pit-tab="wzorce">
                    <?php esc_html_e( 'Wzorce', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
                <button type="button" class="pit-tab" role="tab" id="pit-tab-btn-dane-firmy" aria-selected="false" aria-controls="pit-tab-dane-firmy" data-pit-tab="dane-firmy">
                    <?php esc_html_e( 'Firma', 'obsluga-dokumentow-ksiegowych' ); ?>
                </button>
            </nav>

            <div id="pit-tab-lista" class="pit-tab-panel active" role="tabpanel" aria-labelledby="pit-tab-btn-lista">
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?></h3>

                <div id="pit-pesel-form-data" style="display:none;" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pit_set_pesel_front' ) ); ?>" data-url="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>"></div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-bulk-delete-form">
                <?php wp_nonce_field( 'pit_bulk_delete_nonce', 'pit_bulk_delete_nonce' ); ?>
                <input type="hidden" name="action" value="pit_bulk_delete_files">
                <input type="hidden" name="pit_delete_ids_csv" id="pit-delete-ids-csv" value="">

                <table class="wp-list-table widefat fixed striped" id="pit-files-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pit-select-all"></th>
                            <th class="sortable" data-sort="name"><?php esc_html_e( 'Nazwisko i imię', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="pesel"><?php esc_html_e( 'PESEL', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="year"><?php esc_html_e( 'Rok', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="date"><?php esc_html_e( 'Data pobrania', 'obsluga-dokumentow-ksiegowych' ); ?> <span class="sort-icon">↕</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $files ) ) : ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e( 'Brak dokumentów.', 'obsluga-dokumentow-ksiegowych' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php
                            $groups = [];
                            foreach ( $files as $file ) {
                                $key = pit_person_match_key( (string) ( $file->full_name ?? '' ) );
                                if ( $key === '' ) {
                                    $key = 'file_' . (int) $file->id;
                                }
                                if ( ! isset( $groups[ $key ] ) ) {
                                    $groups[ $key ] = [];
                                }
                                $groups[ $key ][] = $file;
                            }
                            foreach ( $groups as $group ) :
                                $first   = $group[0];
                                $ids     = array_map( function ( $f ) { return (int) $f->id; }, $group );
                                $pesel   = $first->pesel ?? '';
                                $pesel_empty = ( $pesel === '' || $pesel === null );
                                $years   = array_unique( array_map( function ( $f ) { return (int) $f->tax_year; }, $group ) );
                                sort( $years );
                                $last_dl = '';
                                $all_downloaded = true;
                                foreach ( $group as $f ) {
                                    if ( empty( $f->last_download ) ) {
                                        $all_downloaded = false;
                                    } elseif ( $last_dl === '' || strcmp( (string) $f->last_download, $last_dl ) > 0 ) {
                                        $last_dl = $f->last_download;
                                    }
                                }
                                $is_downloaded = $all_downloaded;
                            ?>
                                <tr class="<?php echo $is_downloaded ? '' : 'pit-not-downloaded'; ?>">
                                    <td><input type="checkbox" class="pit-checkbox" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" data-pit-id="<?php echo esc_attr( implode( ',', $ids ) ); ?>"></td>
                                    <td data-name="<?php echo esc_attr( $first->full_name ); ?>">
                                        <strong style="font-weight:700;text-transform:uppercase;"><?php echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $first->full_name, 'UTF-8' ) : strtoupper( (string) $first->full_name ) ); ?></strong>
                                        <div style="margin-top:5px;line-height:1.0;">
                                        <?php foreach ( $group as $f ) : ?>
                                            <?php if ( ! empty( $f->file_path ) ) : ?>
                                                <span style="font-size:0.85em;color:#666;display:block;line-height:1.0;font-weight:400;"><?php echo esc_html( strtolower( basename( $f->file_path ) ) ); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td data-pesel="<?php echo esc_attr( $pesel ); ?>">
                                        <?php if ( $pesel_empty ) : ?>
                                            <span class="pit-pesel-cell-empty">
                                                <a href="#" class="pit-brak-pesel-link"><?php esc_html_e( 'Nie dopasowano', 'obsluga-dokumentow-ksiegowych' ); ?></a>
                                                <span class="pit-set-pesel-form" style="display:none;" data-full-name="<?php echo esc_attr( $first->full_name ); ?>">
                                                    <input type="text" class="pit-set-pesel-value" placeholder="<?php esc_attr_e( '11 cyfr', 'obsluga-dokumentow-ksiegowych' ); ?>" maxlength="11" pattern="\d{11}" size="11">
                                                    <button type="button" class="pit-set-pesel-save button button-small"><?php esc_html_e( 'Zapisz', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                                                </span>
                                            </span>
                                        <?php else : ?>
                                            <?php echo esc_html( (string) $pesel ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-year="<?php echo esc_attr( implode( ', ', $years ) ); ?>"><?php echo esc_html( implode( ', ', $years ) ); ?></td>
                                    <td data-date="<?php echo esc_attr( $last_dl ); ?>">
                                        <?php
                                        echo $is_downloaded
                                            ? esc_html( $last_dl )
                                            : '<em>' . esc_html__( 'Nie pobrano', 'obsluga-dokumentow-ksiegowych' ) . '</em>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="pit-form-row" id="pit-bulk-actions" style="display:none; margin-top: 10px;">
                    <button type="button" class="button button-link-delete" id="pit-bulk-delete-btn">
                        <?php esc_html_e( 'Usuń zaznaczone', 'obsluga-dokumentow-ksiegowych' ); ?>
                    </button>
                    <span id="pit-selected-count"></span>
                </div>
            </form>

                <div class="pit-form-row pit-delete-downloaded-row" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--pit-border, #ddd); display: flex; flex-wrap: wrap; align-items: center; gap: 16px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-delete-downloaded-form" onsubmit="return confirm(<?php echo wp_json_encode( __( 'Czy na pewno usunąć z serwera wszystkie pliki, które zostały już pobrane? Tej operacji nie można cofnąć.', 'obsluga-dokumentow-ksiegowych' ) ); ?>);">
                        <?php wp_nonce_field( 'pit_delete_downloaded_files', 'pit_delete_downloaded_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_delete_downloaded_files">
                        <button type="submit" class="button pit-btn-delete-downloaded">
                            <?php esc_html_e( 'Usuń pobrane', 'obsluga-dokumentow-ksiegowych' ); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-report-pdf-form" class="pit-report-pdf-form-inline">
                        <?php wp_nonce_field( 'pit_report_pdf_nonce', 'pit_report_pdf_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_generate_report_pdf">
                        <div class="pit-report-year-block">
                            <div class="pit-report-year-row">
                                <span class="pit-report-year-label"><?php esc_html_e( 'Raport PDF – rok:', 'obsluga-dokumentow-ksiegowych' ); ?></span>
                                <select name="year" id="pit-report-year">
                                    <option value="0"><?php esc_html_e( 'Wszystkie lata', 'obsluga-dokumentow-ksiegowych' ); ?></option>
                                    <?php
                                    $years = $db->get_available_years();
                                    foreach ( $years as $y ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button pit-btn-generate-report"><?php esc_html_e( 'Generuj raport PDF', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            <script>
            (function() {
                var btn = document.getElementById('pit-bulk-delete-btn');
                if (!btn) return;
                btn.addEventListener('click', function() {
                    var form = document.getElementById('pit-bulk-delete-form');
                    if (!form) return;
                    var checked = form.querySelectorAll('.pit-checkbox:checked');
                    var ids = [];
                    for (var i = 0; i < checked.length; i++) { ids.push(checked[i].value); }
                    if (ids.length === 0) return;
                    if (!confirm(<?php echo wp_json_encode( __( 'Czy na pewno usunąć zaznaczone dokumenty?', 'obsluga-dokumentow-ksiegowych' ) ); ?>)) return;
                    var input = form.querySelector('input[name="pit_delete_ids_csv"]');
                    if (input) input.value = ids.join(',');
                    form.submit();
                });
            })();
            </script>
            </div>

            <div id="pit-tab-upload" class="pit-tab-panel" role="tabpanel" aria-labelledby="pit-tab-btn-upload" hidden>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Wgrywanie', 'obsluga-dokumentow-ksiegowych' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Możesz wgrać 1 lub więcej dokumentów jednocześnie.', 'obsluga-dokumentow-ksiegowych' ); ?>
                    <?php
                    $max_files = (int) ini_get( 'max_file_uploads' );
                    $post_max  = ini_get( 'post_max_size' );
                    if ( $max_files > 0 || $post_max ) {
                        echo '<br><span class="pit-upload-limits">';
                        printf(
                            /* translators: 1: max number of files, 2: post_max_size (e.g. 8M) */
                            esc_html__( 'Limit PHP: max %1$s plików jednocześnie, łącznie do %2$s.', 'obsluga-dokumentow-ksiegowych' ),
                            $max_files > 0 ? (string) $max_files : '?',
                            $post_max ? esc_html( $post_max ) : '?'
                        );
                        echo '</span>';
                    }
                    ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-upload-form" data-upload-url="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'pit_upload_nonce', 'pit_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_upload_files">

                    <div class="pit-form-row">
                        <input type="file" name="pit_pdfs[]" multiple accept=".pdf" required id="pit-upload-files">
                    </div>

                    <div class="pit-form-row pit-upload-progress-wrap" id="pit-upload-progress-wrap" style="display:none;">
                        <div class="pit-upload-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="pit-upload-progress-bar" id="pit-upload-progress-bar" style="width:0%;"></div>
                        </div>
                        <span class="pit-upload-progress-text" id="pit-upload-progress-text"></span>
                    </div>
                    <div class="pit-form-row">
                        <button type="submit" class="button button-primary" id="pit-upload-submit">
                            <?php esc_html_e( 'Wgraj dokumenty', 'obsluga-dokumentow-ksiegowych' ); ?>
                        </button>
                    </div>
                </form>
            <script>
            (function() {
                var form = document.getElementById('pit-upload-form');
                var fileInput = document.getElementById('pit-upload-files');
                var progressWrap = document.getElementById('pit-upload-progress-wrap');
                var progressBar = document.getElementById('pit-upload-progress-bar');
                var progressText = document.getElementById('pit-upload-progress-text');
                var submitBtn = document.getElementById('pit-upload-submit');
                if (!form || !fileInput) return;
                var uploadUrl = form.getAttribute('data-upload-url') || form.action;
                form.addEventListener('submit', function(e) {
                    var files = fileInput.files;
                    if (!files || files.length === 0) return true;
                    e.preventDefault();
                    var nonceEl = form.querySelector('input[name="pit_nonce"]');
                    if (!nonceEl || !nonceEl.value) { alert('Błąd: brak nonce.'); return false; }
                    var total = files.length;
                    var current = 0;
                    if (progressWrap) progressWrap.style.display = '';
                    if (submitBtn) submitBtn.disabled = true;
                    if (progressBar) progressBar.style.width = '0%';
                    function updateProgress(n, ofTotal) {
                        var pct = ofTotal > 0 ? Math.round((n / ofTotal) * 100) : 0;
                        if (progressBar) progressBar.style.width = pct + '%';
                        if (progressText) progressText.textContent = 'Wgrywanie pliku ' + n + ' z ' + ofTotal;
                    }
                    function sendNext() {
                        if (current >= total) {
                            if (progressWrap) progressWrap.style.display = 'none';
                            if (submitBtn) submitBtn.disabled = false;
                            return;
                        }
                        updateProgress(current + 1, total);
                        var fd = new FormData();
                        fd.append('pit_nonce', nonceEl.value);
                        fd.append('action', 'pit_upload_files');
                        fd.append('pit_upload_chunk_index', String(current));
                        fd.append('pit_upload_total_chunks', String(total));
                        fd.append('pit_pdfs[]', files[current]);
                        fetch(uploadUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data && data.success && data.data && data.data.done === true && data.data.redirect_url) {
                                    window.location.href = data.data.redirect_url;
                                    return;
                                }
                                current++;
                                sendNext();
                            })
                            .catch(function(err) {
                                if (progressText) progressText.textContent = 'Błąd: ' + (err.message || 'nieznany');
                                if (progressWrap) progressWrap.style.display = '';
                                if (submitBtn) submitBtn.disabled = false;
                            });
                    }
                    sendNext();
                    return false;
                });
            })();
            </script>
            </div>

            <div id="pit-tab-wzorce" class="pit-tab-panel" role="tabpanel" aria-labelledby="pit-tab-btn-wzorce" hidden>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Wzorce', 'obsluga-dokumentow-ksiegowych' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Wzorce stosowane przy imporcie do wyszukiwania danych (np. PESEL) w dokumentach. Strona – numer strony, Sekcja – np. sekcja formularza, Pole – etykieta pola.', 'obsluga-dokumentow-ksiegowych' ); ?></p>
                <?php if ( isset( $_GET['pit_patterns_saved'] ) && $_GET['pit_patterns_saved'] === '1' ) : ?>
                    <div class="notice notice-success is-dismissible" style="margin-top: 10px;"><p><?php esc_html_e( 'Wzorce zapisane.', 'obsluga-dokumentow-ksiegowych' ); ?></p></div>
                <?php endif; ?>
                <?php if ( isset( $_GET['pit_patterns_reset'] ) && $_GET['pit_patterns_reset'] === '1' ) : ?>
                    <div class="notice notice-success is-dismissible" style="margin-top: 10px;"><p><?php esc_html_e( 'Wzorce przywrócone do domyślnych.', 'obsluga-dokumentow-ksiegowych' ); ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-wzorce-form" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'pit_save_import_patterns', 'pit_import_patterns_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_save_import_patterns">
                    <input type="hidden" name="pit_redirect_tab" value="wzorce">
                    <?php $wzorce = $this->get_import_patterns_option(); ?>
                    <table class="pit-table widefat striped" id="pit-wzorce-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Szukam', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                                <th><?php esc_html_e( 'Strona', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                                <th><?php esc_html_e( 'Sekcja', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                                <th><?php esc_html_e( 'Pole', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                                <th><?php esc_html_e( 'Nazwa pliku', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                                <th style="width: 90px; min-width: 90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $wzorce as $idx => $row ) : ?>
                                <tr class="pit-wzorce-row">
                                    <td><input type="hidden" name="pit_import_patterns[<?php echo (int) $idx; ?>][_deleted]" value="0" class="pit-wzorce-deleted"><input type="text" name="pit_import_patterns[<?php echo (int) $idx; ?>][wzorzec]" value="<?php echo esc_attr( $row['wzorzec'] ); ?>" class="regular-text" placeholder="PESEL"></td>
                                    <td><input type="text" name="pit_import_patterns[<?php echo (int) $idx; ?>][strona]" value="<?php echo esc_attr( $row['strona'] ); ?>" class="small-text" placeholder="1" maxlength="10"></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][pozycja]" class="pit-wzorce-field regular-text" placeholder="C. DANE IDENTYFIKACYJNE" rows="2"><?php echo esc_textarea( $row['pozycja'] ); ?></textarea></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][pole]" class="pit-wzorce-field regular-text" placeholder="numer PESEL" rows="2"><?php echo esc_textarea( $row['pole'] ); ?></textarea></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][nazwa_pliku]" class="pit-wzorce-field regular-text" placeholder="*.pdf" rows="2"><?php echo esc_textarea( $row['nazwa_pliku'] ?? '' ); ?></textarea></td>
                                    <td style="white-space: nowrap;"><button type="button" class="button pit-remove-wzorce-row" aria-label="<?php esc_attr_e( 'Usuń wiersz', 'obsluga-dokumentow-ksiegowych' ); ?>"><?php esc_html_e( 'Usuń', 'obsluga-dokumentow-ksiegowych' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pit-wzorce-actions" style="margin-top: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
                        <button type="button" class="button" id="pit-add-wzorce-row"><?php esc_html_e( 'Dodaj wiersz', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz wzorce', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                    </div>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-reset-patterns-form" style="display: inline; margin: 0; margin-top: 12px;" onsubmit="return confirm(<?php echo wp_json_encode( __( 'Czy na pewno przywrócić wszystkie domyślne wzorce? Obecna lista zostanie zastąpiona.', 'obsluga-dokumentow-ksiegowych' ) ); ?>);">
                    <?php wp_nonce_field( 'pit_reset_import_patterns', 'pit_reset_patterns_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_reset_import_patterns">
                    <input type="hidden" name="pit_redirect_tab" value="wzorce">
                    <button type="submit" class="button"><?php esc_html_e( 'Reset', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                </form>
                <script>
                (function() {
                    var form = document.getElementById('pit-wzorce-form');
                    var tbody = form && form.querySelector('#pit-wzorce-table tbody');
                    var addBtn = document.getElementById('pit-add-wzorce-row');
                    if (!tbody || !addBtn) return;
                    addBtn.addEventListener('click', function() {
                        var inputs = tbody.querySelectorAll('input[name^="pit_import_patterns["]');
                        var maxIdx = -1;
                        inputs.forEach(function(inp) {
                            var m = inp.name.match(/pit_import_patterns\[(\d+)\]/);
                            if (m) maxIdx = Math.max(maxIdx, parseInt(m[1], 10));
                        });
                        var idx = maxIdx + 1;
                        var tr = document.createElement('tr');
                        tr.className = 'pit-wzorce-row';
                        tr.innerHTML = '<td><input type="hidden" name="pit_import_patterns[' + idx + '][_deleted]" value="0" class="pit-wzorce-deleted"><input type="text" name="pit_import_patterns[' + idx + '][wzorzec]" value="" class="regular-text" placeholder="PESEL"></td>' +
                            '<td><input type="text" name="pit_import_patterns[' + idx + '][strona]" value="" class="small-text" placeholder="1" maxlength="10"></td>' +
                            '<td><textarea name="pit_import_patterns[' + idx + '][pozycja]" class="pit-wzorce-field regular-text" placeholder="C. DANE IDENTYFIKACYJNE" rows="2"></textarea></td>' +
                            '<td><textarea name="pit_import_patterns[' + idx + '][pole]" class="pit-wzorce-field regular-text" placeholder="numer PESEL" rows="2"></textarea></td>' +
                            '<td><textarea name="pit_import_patterns[' + idx + '][nazwa_pliku]" class="pit-wzorce-field regular-text" placeholder="*.pdf" rows="2"></textarea></td>' +
                            '<td><button type="button" class="button pit-remove-wzorce-row" aria-label="Usuń wiersz">Usuń</button></td>';
                        tbody.appendChild(tr);
                    });
                    tbody.addEventListener('click', function(e) {
                        if (e.target && e.target.classList && e.target.classList.contains('pit-remove-wzorce-row')) {
                            var row = e.target.closest('tr');
                            if (!row || tbody.querySelectorAll('.pit-wzorce-row').length <= 1) return;
                            var deletedInput = row.querySelector('input.pit-wzorce-deleted');
                            if (deletedInput) deletedInput.value = '1';
                            row.style.display = 'none';
                            form.submit();
                        }
                    });
                })();
                </script>
            </div>

            <div id="pit-tab-dane-firmy" class="pit-tab-panel" role="tabpanel" aria-labelledby="pit-tab-btn-dane-firmy" hidden>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Firma', 'obsluga-dokumentow-ksiegowych' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'pit_save_company_data', 'pit_company_data_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_save_company_data">
                    <?php
                    $pit_company_name    = get_option( 'pit_company_name', '' );
                    $pit_company_address = get_option( 'pit_company_address', '' );
                    $pit_company_nip     = get_option( 'pit_company_nip', '' );
                    ?>
                    <div class="pit-form-row">
                        <label for="pit_company_name"><?php esc_html_e( 'Nazwa firmy', 'obsluga-dokumentow-ksiegowych' ); ?></label>
                        <input type="text" name="pit_company_name" id="pit_company_name" value="<?php echo esc_attr( $pit_company_name ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <label for="pit_company_address"><?php esc_html_e( 'Adres firmy', 'obsluga-dokumentow-ksiegowych' ); ?></label>
                        <input type="text" name="pit_company_address" id="pit_company_address" value="<?php echo esc_attr( $pit_company_address ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <label for="pit_company_nip"><?php esc_html_e( 'NIP firmy', 'obsluga-dokumentow-ksiegowych' ); ?></label>
                        <input type="text" name="pit_company_nip" id="pit_company_nip" value="<?php echo esc_attr( $pit_company_nip ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz zmiany', 'obsluga-dokumentow-ksiegowych' ); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Obsługuje wgrywanie plików (zwykłe lub chunked – wiele żądań po kilka plików).
     */
    public function handle_upload(): void {
        pit_debug_log( 'handle_upload: start' );
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
        pit_debug_log( 'handle_upload: after set_time_limit' );

        $is_chunked = $this->is_chunked_upload_request();
        $chunk_key  = 'pit_upload_chunk_state_' . get_current_user_id();

        if ( $is_chunked ) {
            $state = get_transient( $chunk_key );
            if ( ! is_array( $state ) ) {
                $state = [ 'uploaded' => 0, 'errors' => 0, 'skipped' => 0, 'failed' => [] ];
            }
        } else {
            $state = [ 'uploaded' => 0, 'errors' => 0, 'skipped' => 0, 'failed' => [] ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $db      = PIT_Database::get_instance();
        $filters = get_option( 'pit_filename_filters', [] );

        $files   = $_FILES['pit_pdfs'];
        $count   = count( $files['name'] );
        $uploaded = 0;
        $errors   = 0;
        $skipped  = 0;
        $failed   = [];
        pit_debug_log( 'handle_upload: files count=' . $count . ( $is_chunked ? ' (chunked)' : '' ) );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $i > 0 && $i % 10 === 0 ) {
                pit_debug_log( 'handle_upload: processed ' . $i . '/' . $count );
            }
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

        $state['uploaded'] += $uploaded;
        $state['errors']   += $errors;
        $state['skipped']  += $skipped;
        $state['failed']    = array_merge( $state['failed'], $failed );

        pit_debug_log( 'handle_upload: after loop, chunk uploaded=' . $uploaded . ', state uploaded=' . $state['uploaded'] );

        $chunk_index     = $is_chunked ? (int) ( $_POST['pit_upload_chunk_index'] ?? 0 ) : 0;
        $total_chunks    = $is_chunked ? (int) ( $_POST['pit_upload_total_chunks'] ?? 1 ) : 1;
        $is_last_chunk   = ( $chunk_index >= $total_chunks - 1 );

        if ( $is_chunked ) {
            set_transient( $chunk_key, $state, 300 );
            if ( $is_last_chunk ) {
                if ( $state['uploaded'] > 0 ) {
                    pit_debug_log( 'handle_upload: before fill_missing_pesel_after_upload (chunked last)' );
                    $this->fill_missing_pesel_after_upload( $db );
                }
                delete_transient( $chunk_key );
                $redirect = add_query_arg( [
                    'pit_uploaded' => $state['uploaded'],
                    'pit_errors'   => $state['errors'],
                    'pit_skipped'  => $state['skipped'],
                ], wp_get_referer() ?: home_url() );
                if ( ! empty( $state['failed'] ) ) {
                    set_transient( 'pit_upload_failed_files_' . get_current_user_id(), $state['failed'], 60 );
                    $redirect = add_query_arg( 'pit_upload_failed', '1', $redirect );
                }
                wp_send_json_success( [ 'done' => true, 'redirect_url' => $redirect ] );
            }
            wp_send_json_success( [ 'done' => false ] );
        }

        if ( $state['uploaded'] > 0 ) {
            pit_debug_log( 'handle_upload: before fill_missing_pesel_after_upload' );
            $this->fill_missing_pesel_after_upload( $db );
        }

        pit_debug_log( 'handle_upload: before redirect' );
        $redirect = add_query_arg( [
            'pit_uploaded' => $state['uploaded'],
            'pit_errors'   => $state['errors'],
            'pit_skipped'  => $state['skipped'],
        ], wp_get_referer() ?: home_url() );

        if ( ! empty( $state['failed'] ) ) {
            set_transient( 'pit_upload_failed_files_' . get_current_user_id(), $state['failed'], 60 );
            $redirect = add_query_arg( 'pit_upload_failed', '1', $redirect );
        }

        pit_redirect_safe( $redirect );
    }

    /**
     * Czy bieżące żądanie to upload po 1 pliku (AJAX) – zawsze zwracamy JSON zamiast redirectu.
     */
    private function is_chunked_upload_request(): bool {
        $header = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) : '';
        return ( $header === 'XMLHttpRequest' )
            && isset( $_POST['pit_upload_chunk_index'] )
            && isset( $_POST['pit_upload_total_chunks'] )
            && (int) $_POST['pit_upload_total_chunks'] >= 1;
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

        $found_pesels     = [];
        $pesel_near_label = [];
        $pit11_section_c  = null;
        foreach ( $rows as $row ) {
            if ( ! file_exists( $row->file_path ) ) {
                continue;
            }
            $text = $this->extract_text_from_pdf( $row->file_path );
            if ( $text === '' ) {
                continue;
            }
            $from_rules = $this->extract_pesel_by_document_rules( $text, $row->file_path );
            if ( $from_rules !== null ) {
                $pit11_section_c = $from_rules;
            }
            $lines = preg_split( '/\r\n|\r|\n/', $text );
            foreach ( $lines as $line ) {
                if ( preg_match_all( '/\b(\d{11})\b/', $line, $m ) ) {
                    $has_pesel_label = (bool) preg_match( '/PESEL/i', $line );
                    foreach ( $m[1] as $p ) {
                        $found_pesels[ $p ] = true;
                        if ( $has_pesel_label ) {
                            $pesel_near_label[ $p ] = true;
                        }
                    }
                }
            }
        }
        if ( $pit11_section_c !== null ) {
            return $pit11_section_c;
        }

        $unique = array_keys( $found_pesels );
        $valid  = array_values( array_filter( $unique, function ( $p ) {
            return function_exists( 'pit_validate_pesel_checksum' ) && pit_validate_pesel_checksum( $p );
        } ) );
        if ( count( $valid ) === 1 ) {
            return $valid[0];
        }
        if ( count( $valid ) > 1 ) {
            $valid_near_label = array_values( array_filter( $valid, function ( $p ) use ( $pesel_near_label ) {
                return ! empty( $pesel_near_label[ $p ] );
            } ) );
            if ( count( $valid_near_label ) === 1 ) {
                return $valid_near_label[0];
            }
            if ( ! empty( $valid_near_label ) ) {
                return $valid_near_label[0];
            }
            return $valid[0];
        }
        if ( count( $unique ) === 1 ) {
            return $unique[0];
        }
        return null;
    }

    /**
     * Wyciąga PESEL (11 cyfr) z tekstu PDF według reguł z zakładki Wzorce (nagłówek, sekcja, pole, nazwa pliku).
     *
     * @param string $text     Tekst wyciągnięty z PDF (pdftotext -layout).
     * @param string $file_path Ścieżka do pliku – używana do dopasowania wzorca „Nazwa pliku”.
     * @return string|null PESEL (11 cyfr) lub null.
     */
    private function extract_pesel_by_document_rules( string $text, string $file_path = '' ): ?string {
        $filename = $file_path !== '' ? basename( $file_path ) : '';
        $rules = $this->get_pesel_search_rules_option( $filename );
        foreach ( $rules as $rule ) {
            $header = $rule['nazwa_naglowka'];
            $section = $rule['nazwa_sekcji'];
            $field_num = $rule['nr_pola'];
            if ( $header === '' ) {
                continue;
            }
            $head = substr( $text, 0, 1000 );
            $header_esc = preg_quote( $header, '/' );
            if ( ! preg_match( '/' . $header_esc . '/iu', $head ) ) {
                continue;
            }
            $pos = 0;
            if ( $section !== '' ) {
                $section_esc = preg_quote( $section, '/' );
                if ( preg_match( '/Sekcja\s*' . $section_esc . '\b/ui', $text, $m, PREG_OFFSET_CAPTURE ) ) {
                    $pos = $m[0][1];
                } elseif ( preg_match( '/' . $section_esc . '\b/ui', $text, $m, PREG_OFFSET_CAPTURE ) ) {
                    $pos = $m[0][1];
                } elseif ( preg_match( '/Dane\s+osoby\s+ubezpieczonej?\b/ui', $text, $m, PREG_OFFSET_CAPTURE ) && ( strpos( $section, 'ubezpieczon' ) !== false ) ) {
                    $pos = $m[0][1];
                } elseif ( preg_match( '/DANE\s+IDENTYFIKACYJNE\s+I\s+ADRES\s+ZAMIESZKANIA\s+PODATNIKA/ui', $text, $m, PREG_OFFSET_CAPTURE ) && ( strtoupper( $section ) === 'C' || $section === 'C' ) ) {
                    $pos = $m[0][1];
                } else {
                    continue;
                }
            }
            $chunk = substr( $text, $pos, 1200 );
            if ( ! preg_match_all( '/\b(\d{11})\b/', $chunk, $nums ) ) {
                continue;
            }
            $valid = array_values( array_filter( array_unique( $nums[1] ), function ( $p ) {
                return function_exists( 'pit_validate_pesel_checksum' ) && pit_validate_pesel_checksum( $p );
            } ) );
            if ( count( $valid ) === 1 ) {
                return $valid[0];
            }
            if ( count( $valid ) > 1 && $field_num !== '' ) {
                $field_esc = preg_quote( $field_num, '/' );
                $lines = preg_split( '/\r\n|\r|\n/', $chunk );
                $lines_arr = array_values( $lines );
                foreach ( $lines_arr as $li => $line ) {
                    $has_field = (bool) preg_match( '/\b' . $field_esc . '\b|pole\s*' . $field_esc . '\b|numer\s*PESEL|Identyfikator\s+podatkowy|NIP\s*\/\s*numer\s*PESEL|Typ\s+identyfikatora/ui', $line );
                    if ( ! $has_field ) {
                        continue;
                    }
                    if ( preg_match( '/\b(\d{11})\b/', $line, $one ) && function_exists( 'pit_validate_pesel_checksum' ) && pit_validate_pesel_checksum( $one[1] ) ) {
                        return $one[1];
                    }
                    $next_line = isset( $lines_arr[ $li + 1 ] ) ? $lines_arr[ $li + 1 ] : '';
                    if ( $next_line !== '' && preg_match( '/\b(\d{11})\b/', $next_line, $one ) && function_exists( 'pit_validate_pesel_checksum' ) && pit_validate_pesel_checksum( $one[1] ) ) {
                        return $one[1];
                    }
                }
                return $valid[0];
            }
            if ( count( $valid ) > 0 ) {
                return $valid[0];
            }
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
        pit_debug_log( 'handle_bulk_delete: start' );
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_bulk_delete_nonce'] ?? '', 'pit_bulk_delete_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $ids = [];
        if ( ! empty( $_POST['pit_delete_ids_csv'] ) && is_string( $_POST['pit_delete_ids_csv'] ) ) {
            $raw   = array_map( 'trim', explode( ',', $_POST['pit_delete_ids_csv'] ) );
            $ids   = array_filter( array_map( 'intval', $raw ), function ( $id ) {
                return $id > 0;
            } );
        }
        $fallback = $_POST['pit_delete_ids'] ?? [];
        if ( empty( $ids ) && is_array( $fallback ) ) {
            $ids = array_filter( array_map( 'intval', $fallback ), function ( $id ) {
                return $id > 0;
            } );
        }
        pit_debug_log( 'handle_bulk_delete: ids count=' . count( $ids ) . ', raw_csv=' . ( isset( $_POST['pit_delete_ids_csv'] ) ? substr( (string) $_POST['pit_delete_ids_csv'], 0, 100 ) : 'none' ) );

        $db   = PIT_Database::get_instance();
        $count = 0;

        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && $db->delete_file( $id ) ) {
                $count++;
            }
        }

        pit_debug_log( 'handle_bulk_delete: after loop, deleted=' . $count . ', before redirect' );
        $redirect = add_query_arg( 'pit_bulk_deleted', $count, wp_get_referer() ?: home_url() );
        pit_redirect_safe( $redirect );
    }

    /**
     * Usuwa z serwera wszystkie pliki, które mają co najmniej jedno pobranie.
     */
    public function handle_delete_downloaded_files(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_delete_downloaded_nonce'] ?? '', 'pit_delete_downloaded_files' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $db    = PIT_Database::get_instance();
        $ids   = $db->get_downloaded_file_ids();
        $count = 0;

        foreach ( $ids as $id ) {
            if ( $id > 0 && $db->delete_file( $id ) ) {
                $count++;
            }
        }

        $redirect = add_query_arg( 'pit_downloaded_deleted', $count, wp_get_referer() ?: home_url() );
        pit_redirect_safe( $redirect );
    }

    /**
     * Obsługuje ręczne ustawienie PESEL z panelu księgowego (link „Nie dopasowano”).
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
     * Zapisuje dane firmy z zakładki Dane firmy.
     */
    public function handle_save_company_data(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_company_data_nonce'] ?? '', 'pit_save_company_data' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $company_name    = sanitize_text_field( $_POST['pit_company_name'] ?? '' );
        $company_address = sanitize_text_field( $_POST['pit_company_address'] ?? '' );
        $nip_raw         = is_string( $_POST['pit_company_nip'] ?? '' ) ? $_POST['pit_company_nip'] : '';
        $company_nip     = substr( preg_replace( '/[^0-9]/', '', $nip_raw ), 0, 10 );

        update_option( 'pit_company_name', $company_name );
        update_option( 'pit_company_address', $company_address );
        update_option( 'pit_company_nip', $company_nip );

        $redirect = add_query_arg( 'pit_company_saved', '1', wp_get_referer() ?: home_url() );
        wp_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Zapisuje wzorce importu (zakładka Wzorce).
     */
    public function handle_save_import_patterns(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_import_patterns_nonce'] ?? '', 'pit_save_import_patterns' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        $raw = isset( $_POST['pit_import_patterns'] ) && is_array( $_POST['pit_import_patterns'] ) ? $_POST['pit_import_patterns'] : [];
        $patterns = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( isset( $row['_deleted'] ) && (string) $row['_deleted'] === '1' ) {
                continue;
            }
            $wzorzec     = isset( $row['wzorzec'] ) ? sanitize_text_field( (string) $row['wzorzec'] ) : '';
            $strona      = isset( $row['strona'] ) ? sanitize_text_field( (string) $row['strona'] ) : '';
            $pozycja     = isset( $row['pozycja'] ) ? sanitize_text_field( (string) $row['pozycja'] ) : '';
            $pole        = isset( $row['pole'] ) ? sanitize_text_field( (string) $row['pole'] ) : '';
            $nazwa_pliku = isset( $row['nazwa_pliku'] ) ? sanitize_text_field( (string) $row['nazwa_pliku'] ) : '';
            if ( $wzorzec === '' && $pozycja === '' && $pole === '' ) {
                continue;
            }
            $patterns[] = [ 'wzorzec' => $wzorzec, 'strona' => $strona, 'pozycja' => $pozycja, 'pole' => $pole, 'nazwa_pliku' => $nazwa_pliku ];
        }
        if ( empty( $patterns ) ) {
            $patterns = $this->get_default_import_patterns();
        }
        update_option( 'pit_import_patterns', array_values( $patterns ) );

        $redirect = add_query_arg( 'pit_patterns_saved', '1', wp_get_referer() ?: home_url() );
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Przywraca domyślne wzorce importu (przycisk Reset w zakładce Wzorce).
     */
    public function handle_reset_import_patterns(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_reset_patterns_nonce'] ?? '', 'pit_reset_import_patterns' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'obsluga-dokumentow-ksiegowych' ) );
        }

        update_option( 'pit_import_patterns', array_values( $this->get_default_import_patterns() ) );

        $redirect = add_query_arg( 'pit_patterns_reset', '1', wp_get_referer() ?: home_url() );
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
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
        .pit-full-name { font-weight: 700; text-transform: uppercase; }
        .pit-doc-filename { font-size: 0.85em; color: #666; display: block; margin-top: 2px; }
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
                <th><?php esc_html_e( 'Nazwisko i imię', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                <th>PESEL</th>
                <th><?php esc_html_e( 'Data pobrania', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                <th><?php esc_html_e( 'Status', 'obsluga-dokumentow-ksiegowych' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $report_groups = [];
            foreach ( $data as $row ) {
                $key = pit_person_match_key( (string) ( $row->full_name ?? '' ) );
                if ( $key === '' ) {
                    $key = 'file_' . (int) $row->id;
                }
                if ( ! isset( $report_groups[ $key ] ) ) {
                    $report_groups[ $key ] = [];
                }
                $report_groups[ $key ][] = $row;
            }
            foreach ( $report_groups as $group ) :
                $first = $group[0];
                $pesel = $first->pesel ?? '';
                $last_dl = '';
                $all_downloaded = true;
                foreach ( $group as $r ) {
                    if ( empty( $r->downloaded_at ) ) {
                        $all_downloaded = false;
                    } elseif ( $last_dl === '' || strcmp( (string) $r->downloaded_at, $last_dl ) > 0 ) {
                        $last_dl = $r->downloaded_at;
                    }
                }
                $is_downloaded = $all_downloaded;
                $full_name_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $first->full_name, 'UTF-8' ) : strtoupper( (string) $first->full_name );
            ?>
                <tr class="<?php echo $is_downloaded ? '' : 'not-downloaded'; ?>">
                    <td>
                        <strong class="pit-full-name"><?php echo esc_html( $full_name_upper ); ?></strong>
                        <?php foreach ( $group as $r ) : ?>
                            <?php if ( ! empty( $r->file_path ) ) : ?>
                                <br><span class="pit-doc-filename"><?php echo esc_html( strtolower( basename( $r->file_path ) ) ); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo esc_html( $pesel ); ?></td>
                    <td><?php echo $is_downloaded ? esc_html( $last_dl ) : '—'; ?></td>
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
        .pit-full-name { font-weight: 700; text-transform: uppercase; }
        .pit-doc-filename { font-size: 0.85em; color: #666; display: block; margin-top: 2px; }
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
                <th><?php esc_html_e( 'Nazwisko i imię', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                <th>PESEL</th>
                <?php if ( $year === 0 ) : ?>
                <th><?php esc_html_e( 'Rok', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                <?php endif; ?>
                <th><?php esc_html_e( 'Data pobrania', 'obsluga-dokumentow-ksiegowych' ); ?></th>
                <th><?php esc_html_e( 'Status', 'obsluga-dokumentow-ksiegowych' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $report_groups = [];
            foreach ( $data as $row ) {
                $key = pit_person_match_key( (string) ( $row->full_name ?? '' ) );
                if ( $key === '' ) {
                    $key = 'file_' . (int) $row->id;
                }
                if ( ! isset( $report_groups[ $key ] ) ) {
                    $report_groups[ $key ] = [];
                }
                $report_groups[ $key ][] = $row;
            }
            foreach ( $report_groups as $group ) :
                $first = $group[0];
                $pesel = $first->pesel ?? '';
                $years = array_unique( array_map( function ( $r ) { return (int) $r->tax_year; }, $group ) );
                sort( $years );
                $last_dl = '';
                $all_downloaded = true;
                foreach ( $group as $r ) {
                    if ( empty( $r->downloaded_at ) ) {
                        $all_downloaded = false;
                    } elseif ( $last_dl === '' || strcmp( (string) $r->downloaded_at, $last_dl ) > 0 ) {
                        $last_dl = $r->downloaded_at;
                    }
                }
                $is_downloaded = $all_downloaded;
                $full_name_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $first->full_name, 'UTF-8' ) : strtoupper( (string) $first->full_name );
            ?>
                <tr class="<?php echo $is_downloaded ? '' : 'not-downloaded'; ?>">
                    <td>
                        <strong class="pit-full-name"><?php echo esc_html( $full_name_upper ); ?></strong>
                        <?php foreach ( $group as $r ) : ?>
                            <?php if ( ! empty( $r->file_path ) ) : ?>
                                <br><span class="pit-doc-filename"><?php echo esc_html( strtolower( basename( $r->file_path ) ) ); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo esc_html( $pesel ); ?></td>
                    <?php if ( $year === 0 ) : ?>
                    <td><?php echo esc_html( implode( ', ', $years ) ); ?></td>
                    <?php endif; ?>
                    <td><?php echo $is_downloaded ? esc_html( $last_dl ) : '—'; ?></td>
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
