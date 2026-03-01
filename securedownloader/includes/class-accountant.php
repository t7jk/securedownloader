<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Klasa panelu menadżera wtyczki PIT-11 Manager.
 * Umożliwia wgrywanie dokumentów i zarządzanie nimi.
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
        add_action( 'admin_post_pit_scan_uploaded_files', [ $this, 'handle_scan_uploaded_files' ] );
        add_action( 'admin_post_nopriv_pit_scan_uploaded_files', [ $this, 'handle_scan_uploaded_files' ] );
        add_action( 'admin_post_pit_delete_all_files', [ $this, 'handle_delete_all_files' ] );
        add_action( 'admin_post_nopriv_pit_delete_all_files', [ $this, 'handle_delete_all_files' ] );
        add_action( 'admin_post_pit_delete_downloaded_files', [ $this, 'handle_delete_downloaded_files' ] );
        add_action( 'admin_post_nopriv_pit_delete_downloaded_files', [ $this, 'handle_delete_downloaded_files' ] );
        add_filter( 'template_include', [ $this, 'use_fullscreen_template' ], 99 );
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
     * Dla strony menadżera zwraca szablon pełnoekranowy (bez motywu).
     *
     * @param string $template Ścieżka do aktualnego szablonu.
     * @return string Ścieżka do szablonu.
     */
    public function use_fullscreen_template( string $template ): string {
        $accountant_url = get_option( 'pit_accountant_page_url', '' );
        if ( $accountant_url === '' ) {
            return $template;
        }
        $accountant_page_id = url_to_postid( $accountant_url );
        if ( $accountant_page_id <= 0 || ! is_singular() ) {
            return $template;
        }
        if ( (int) get_queried_object_id() !== (int) $accountant_page_id ) {
            return $template;
        }
        $fullscreen = PIT_PLUGIN_DIR . 'templates/fullwidth-accountant.php';
        return is_readable( $fullscreen ) ? $fullscreen : $template;
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
            [ 'wzorzec' => 'PESEL', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => 'numer PESEL', 'nazwa_pliku' => 'NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf' ],
            [ 'wzorzec' => 'PESEL', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Identyfikator', 'nazwa_pliku' => 'Informacja roczna dla NAZWISKO IMIĘ.pdf' ],
            [ 'wzorzec' => 'NAZWISKO', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Nazwisko', 'nazwa_pliku' => 'Informacja roczna dla NAZWISKO IMIĘ.pdf' ],
            [ 'wzorzec' => 'IMIĘ', 'strona' => '1', 'pozycja' => 'Dane osoby ubezpieczonej', 'pole' => 'Imię', 'nazwa_pliku' => 'Informacja roczna dla NAZWISKO IMIĘ.pdf' ],
            [ 'wzorzec' => 'IMIĘ', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => '17 Pierwsze imie', 'nazwa_pliku' => 'NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf' ],
            [ 'wzorzec' => 'NAZWISKO', 'strona' => '1', 'pozycja' => 'C. DANE IDENTYFIKACYJNE', 'pole' => '16. Nazwisko', 'nazwa_pliku' => 'NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf' ],
            [ 'wzorzec' => 'RRRR', 'strona' => '1', 'pozycja' => 'w roku', 'pole' => '4. Rok', 'nazwa_pliku' => 'NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf' ],
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
     * Sprawdza dostęp użytkownika do panelu menadżera.
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
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'uploadPostUrl'    => admin_url( 'admin-post.php' ),
                'uploadChunkSize'  => (int) PIT_UPLOAD_CHUNK_SIZE,
                'uploadProgressLabel' => __( 'Wgrywanie pliku %1$s z %2$s', 'securedownloader' ),
                'nonce'            => wp_create_nonce( 'pit_manager_nonce' ),
                'confirmDelete'      => __( 'Czy na pewno usunąć ten dokument?', 'securedownloader' ),
                'confirmBulkDelete'  => __( 'Czy na pewno usunąć zaznaczone dokumenty?', 'securedownloader' ),
                'errorPesel'       => __( 'PESEL musi składać się z 11 cyfr.', 'securedownloader' ),
                'closeMessage'     => __( 'Zamknij', 'securedownloader' ),
            ] );
        }
    }

    /**
     * Renderuje shortcode panelu menadżera.
     *
     * @return string HTML panelu.
     */
    public function render_shortcode(): string {
        if ( ! $this->check_access() ) {
            if ( ! is_user_logged_in() ) {
                $login_url = wp_login_url( get_permalink() );
                return '<p class="pit-error">' . sprintf(
                    esc_html__( 'Musisz się zalogować. %s', 'securedownloader' ),
                    '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Zaloguj się', 'securedownloader' ) . '</a>'
                ) . '</p>';
            }
            return '<p class="pit-error">' . esc_html__( 'Brak dostępu do tego panelu.', 'securedownloader' ) . '</p>';
        }

        $message       = '';
        $message_class = 'pit-success';
        if ( isset( $_GET['pit_uploaded'] ) ) {
            $uploaded = (int) $_GET['pit_uploaded'];
            $errors   = (int) $_GET['pit_errors'];
            $skipped  = (int) ( $_GET['pit_skipped'] ?? 0 );
            $message  = sprintf(
                __( 'Wgrano %d dokumentów.', 'securedownloader' ),
                $uploaded
            );
            if ( $skipped > 0 ) {
                $message .= ' ' . sprintf( __( 'Pominięto %d (już istnieją).', 'securedownloader' ), $skipped );
            }
            if ( $errors > 0 ) {
                $message .= ' ' . sprintf( __( 'Błędów: %d.', 'securedownloader' ), $errors );
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
            $message = __( 'Dokument został usunięty.', 'securedownloader' );
        }
        if ( isset( $_GET['pit_bulk_deleted'] ) ) {
            $count = (int) $_GET['pit_bulk_deleted'];
            $message = sprintf(
                _n( 'Usunięto %d dokument.', 'Usunięto %d dokumentów.', $count, 'securedownloader' ),
                $count
            );
        }
        if ( isset( $_GET['pit_all_deleted'] ) ) {
            $count = (int) $_GET['pit_all_deleted'];
            $message = sprintf(
                _n( 'Usunięto %d dokument z serwera i bazy.', 'Usunięto %d dokumentów z serwera i bazy.', $count, 'securedownloader' ),
                $count
            );
        }
        if ( isset( $_GET['pit_downloaded_deleted'] ) ) {
            $count = (int) $_GET['pit_downloaded_deleted'];
            $message = sprintf(
                _n( 'Usunięto %d pobrany dokument z serwera.', 'Usunięto %d pobranych dokumentów z serwera.', $count, 'securedownloader' ),
                $count
            );
        }
        if ( isset( $_GET['pit_set_pesel_ok'] ) && $_GET['pit_set_pesel_ok'] === '1' ) {
            $message = __( 'PESEL został zapisany.', 'securedownloader' );
        }
        if ( isset( $_GET['pit_set_pesel_error'] ) && $_GET['pit_set_pesel_error'] === '1' ) {
            $message       = __( 'Błąd: podaj prawidłowy PESEL (11 cyfr).', 'securedownloader' );
            $message_class = 'pit-error';
        }
        if ( isset( $_GET['pit_company_saved'] ) && $_GET['pit_company_saved'] === '1' ) {
            $message = __( 'Dane firmy zostały zapisane.', 'securedownloader' );
        }
        if ( isset( $_GET['pit_scan_added'] ) || isset( $_GET['pit_scan_removed'] ) ) {
            $added   = (int) ( $_GET['pit_scan_added'] ?? 0 );
            $removed = (int) ( $_GET['pit_scan_removed'] ?? 0 );
            $parts   = [];
            if ( $added > 0 ) {
                $parts[] = sprintf( _n( 'Dodano %d plik do bazy.', 'Dodano %d plików do bazy.', $added, 'securedownloader' ), $added );
            }
            if ( $removed > 0 ) {
                $parts[] = sprintf( _n( 'Usunięto %d rekord (brak pliku na dysku).', 'Usunięto %d rekordów (brak pliku na dysku).', $removed, 'securedownloader' ), $removed );
            }
            $message = implode( ' ', $parts );
            if ( $message === '' ) {
                $message = __( 'Skanowanie zakończone. Nie znaleziono nowych plików PDF na dysku.', 'securedownloader' );
            }
        }
        $db = PIT_Database::get_instance();
        set_time_limit( 90 );
        if ( function_exists( 'pit_raise_memory_for_pdf' ) ) {
            pit_raise_memory_for_pdf();
        }
        $this->fill_missing_pesel_after_upload( $db );
        $files = $db->get_all_files_sorted();

        $current_tab = isset( $_GET['pit_tab'] ) ? sanitize_key( (string) $_GET['pit_tab'] ) : 'lista';
        $valid_tabs  = [ 'lista', 'upload', 'wzorce', 'dane-firmy', 'manual' ];
        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            $current_tab = 'lista';
        }

        ob_start();

        ?>
        <div class="pit-accountant-panel">
            <h2><?php esc_html_e( 'Panel menadżera 1.7', 'securedownloader' ); ?></h2>

            <?php if ( $message ) : ?>
                <div class="pit-message <?php echo esc_attr( $message_class ); ?>">
                    <?php echo esc_html( $message ); ?>
                </div>
            <?php endif; ?>
            <?php if ( ! empty( $upload_failed_list ) ) : ?>
                <div class="pit-message pit-error">
                    <p><?php esc_html_e( 'Nie zaimportowano następujących plików:', 'securedownloader' ); ?></p>
                    <ul class="pit-failed-files-list">
                        <?php foreach ( $upload_failed_list as $item ) : ?>
                            <li><strong><?php echo esc_html( $item['name'] ); ?></strong> — <?php echo nl2br( esc_html( $item['reason'] ) ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php
            $pesel_diagnostics = get_transient( 'pit_upload_pesel_diagnostics_' . get_current_user_id() );
            if ( is_array( $pesel_diagnostics ) && ! empty( $pesel_diagnostics ) ) :
                delete_transient( 'pit_upload_pesel_diagnostics_' . get_current_user_id() );
                ?>
                <div class="pit-message pit-warning pit-pesel-diagnostics">
                    <p><strong><?php esc_html_e( 'Tryb deweloperski: nie rozpoznano PESEL dla części osób', 'securedownloader' ); ?></strong></p>
                    <p><?php esc_html_e( 'Poniższe informacje pomagają zdiagnozować problem na produkcji (gdy u Ciebie działa, a na serwerze klienta nie).', 'securedownloader' ); ?></p>
                    <?php foreach ( $pesel_diagnostics as $diag ) : ?>
                        <p><strong><?php echo esc_html( (string) ( $diag['full_name'] ?? '' ) ); ?></strong></p>
                        <ul class="pit-pesel-diagnostics-list">
                            <?php foreach ( (array) ( $diag['reasons'] ?? [] ) as $reason ) : ?>
                                <li><?php echo esc_html( (string) $reason ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ( ! pit_pdftotext_available() ) : ?>
                <div class="pit-message pit-warning">
                    <p><?php esc_html_e( 'Rozpoznawanie PESEL z PDF wymaga narzędzia pdftotext (pakiet poppler-utils). Na tym serwerze nie jest ono dostępne – zainstaluj je lub uzupełniaj PESEL ręcznie.', 'securedownloader' ); ?></p>
                    <p><?php esc_html_e( 'Alternatywa: wgraj wtyczkę wraz z folderem vendor/ (po uruchomieniu composer install w katalogu wtyczki) – wtedy PESEL będzie rozpoznawany z PDF bez pdftotext.', 'securedownloader' ); ?></p>
                </div>
            <?php endif; ?>

            <nav class="pit-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Zakładki panelu', 'securedownloader' ); ?>">
                <button type="button" class="pit-tab <?php echo $current_tab === 'lista' ? 'active' : ''; ?>" role="tab" id="pit-tab-btn-lista" aria-selected="<?php echo $current_tab === 'lista' ? 'true' : 'false'; ?>" aria-controls="pit-tab-lista" data-pit-tab="lista">
                    <?php esc_html_e( 'Dokumenty', 'securedownloader' ); ?>
                </button>
                <button type="button" class="pit-tab <?php echo $current_tab === 'upload' ? 'active' : ''; ?>" role="tab" id="pit-tab-btn-upload" aria-selected="<?php echo $current_tab === 'upload' ? 'true' : 'false'; ?>" aria-controls="pit-tab-upload" data-pit-tab="upload">
                    <?php esc_html_e( 'Wgrywanie', 'securedownloader' ); ?>
                </button>
                <button type="button" class="pit-tab <?php echo $current_tab === 'wzorce' ? 'active' : ''; ?>" role="tab" id="pit-tab-btn-wzorce" aria-selected="<?php echo $current_tab === 'wzorce' ? 'true' : 'false'; ?>" aria-controls="pit-tab-wzorce" data-pit-tab="wzorce">
                    <?php esc_html_e( 'Wzorce', 'securedownloader' ); ?>
                </button>
                <button type="button" class="pit-tab <?php echo $current_tab === 'dane-firmy' ? 'active' : ''; ?>" role="tab" id="pit-tab-btn-dane-firmy" aria-selected="<?php echo $current_tab === 'dane-firmy' ? 'true' : 'false'; ?>" aria-controls="pit-tab-dane-firmy" data-pit-tab="dane-firmy">
                    <?php esc_html_e( 'Firma', 'securedownloader' ); ?>
                </button>
                <button type="button" class="pit-tab <?php echo $current_tab === 'manual' ? 'active' : ''; ?>" role="tab" id="pit-tab-btn-manual" aria-selected="<?php echo $current_tab === 'manual' ? 'true' : 'false'; ?>" aria-controls="pit-tab-manual" data-pit-tab="manual">
                    <?php esc_html_e( 'Podręcznik', 'securedownloader' ); ?>
                </button>
            </nav>

            <div id="pit-tab-lista" class="pit-tab-panel <?php echo $current_tab === 'lista' ? 'active' : ''; ?>" role="tabpanel" aria-labelledby="pit-tab-btn-lista" <?php echo $current_tab !== 'lista' ? 'hidden' : ''; ?>>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Dokumenty', 'securedownloader' ); ?></h3>

                <div id="pit-pesel-form-data" style="display:none;" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pit_set_pesel_front' ) ); ?>" data-url="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>"></div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-bulk-delete-form">
                <?php wp_nonce_field( 'pit_bulk_delete_nonce', 'pit_bulk_delete_nonce' ); ?>
                <input type="hidden" name="action" value="pit_bulk_delete_files">
                <input type="hidden" name="pit_redirect_tab" value="lista">
                <input type="hidden" name="pit_delete_ids_csv" id="pit-delete-ids-csv" value="">

                <table class="wp-list-table widefat fixed striped" id="pit-files-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pit-select-all"></th>
                            <th class="sortable" data-sort="name"><?php esc_html_e( 'Nazwisko i imię', 'securedownloader' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="pesel"><?php esc_html_e( 'PESEL', 'securedownloader' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="year"><?php esc_html_e( 'Rok', 'securedownloader' ); ?> <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-sort="date"><?php esc_html_e( 'Data pobrania', 'securedownloader' ); ?> <span class="sort-icon">↕</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $files ) ) : ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e( 'Brak dokumentów.', 'securedownloader' ); ?></td>
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
                                                <a href="#" class="pit-brak-pesel-link"><?php esc_html_e( 'Nie dopasowano', 'securedownloader' ); ?></a>
                                                <span class="pit-set-pesel-form" style="display:none;" data-full-name="<?php echo esc_attr( $first->full_name ); ?>">
                                                    <input type="text" class="pit-set-pesel-value" placeholder="<?php esc_attr_e( '11 cyfr', 'securedownloader' ); ?>" maxlength="11" pattern="\d{11}" size="11">
                                                    <button type="button" class="pit-set-pesel-save button button-small"><?php esc_html_e( 'Zapisz', 'securedownloader' ); ?></button>
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
                                            : '<em>' . esc_html__( 'Nie pobrano', 'securedownloader' ) . '</em>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="pit-form-row" id="pit-bulk-actions" style="display:none; margin-top: 10px;">
                    <button type="button" class="button button-link-delete" id="pit-bulk-delete-btn">
                        <?php esc_html_e( 'Usuń zaznaczone', 'securedownloader' ); ?>
                    </button>
                    <span id="pit-selected-count"></span>
                </div>
            </form>

                <div class="pit-form-row pit-delete-downloaded-row" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--pit-border, #ddd); display: flex; flex-wrap: wrap; align-items: center; gap: 16px; align-content: center;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-delete-all-form" style="display: inline-block;" onsubmit="return confirm(<?php echo wp_json_encode( __( 'Czy na pewno usunąć wszystkie dokumenty z serwera i bazy? Tej operacji nie można cofnąć.', 'securedownloader' ) ); ?>);">
                        <?php wp_nonce_field( 'pit_delete_all_files', 'pit_delete_all_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_delete_all_files">
                        <input type="hidden" name="pit_redirect_tab" value="lista">
                        <button type="submit" class="button pit-btn-delete-all">
                            <?php esc_html_e( 'Usuń wszystkie', 'securedownloader' ); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-delete-downloaded-form" onsubmit="return confirm(<?php echo wp_json_encode( __( 'Czy na pewno usunąć z serwera wszystkie pliki, które zostały już pobrane? Tej operacji nie można cofnąć.', 'securedownloader' ) ); ?>);">
                        <?php wp_nonce_field( 'pit_delete_downloaded_files', 'pit_delete_downloaded_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_delete_downloaded_files">
                        <input type="hidden" name="pit_redirect_tab" value="lista">
                        <button type="submit" class="button pit-btn-delete-downloaded">
                            <?php esc_html_e( 'Usuń pobrane', 'securedownloader' ); ?>
                        </button>
                    </form>
                    <style type="text/css">
                        #pit-report-pdf-form { display: inline-flex !important; flex-wrap: nowrap !important; align-items: center !important; gap: 8px !important; }
                        #pit-report-pdf-form .pit-report-year-label { white-space: nowrap !important; flex-shrink: 0 !important; }
                        #pit-report-pdf-form .pit-btn-generate-report { white-space: nowrap !important; flex-shrink: 0 !important; min-width: 180px !important; }
                    </style>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-report-pdf-form" class="pit-report-pdf-form-inline" style="display: inline-flex !important; flex-wrap: nowrap !important; align-items: center !important; gap: 8px !important;">
                        <?php wp_nonce_field( 'pit_report_pdf_nonce', 'pit_report_pdf_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_generate_report_pdf">
                        <input type="hidden" name="pit_redirect_tab" value="lista">
                        <span class="pit-report-year-label" style="white-space: nowrap !important;"><?php esc_html_e( 'Raport PDF – rok:', 'securedownloader' ); ?></span>
                        <select name="year" id="pit-report-year">
                            <option value="0"><?php esc_html_e( 'Wszystkie lata', 'securedownloader' ); ?></option>
                            <?php
                            $years = $db->get_available_years();
                            foreach ( $years as $y ) :
                            ?>
                                <option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button pit-btn-generate-report" style="white-space: nowrap !important; min-width: 180px;"><?php esc_html_e( 'Generuj raport PDF', 'securedownloader' ); ?></button>
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
                    if (!confirm(<?php echo wp_json_encode( __( 'Czy na pewno usunąć zaznaczone dokumenty?', 'securedownloader' ) ); ?>)) return;
                    var input = form.querySelector('input[name="pit_delete_ids_csv"]');
                    if (input) input.value = ids.join(',');
                    form.submit();
                });
            })();
            </script>
            </div>

            <div id="pit-tab-upload" class="pit-tab-panel <?php echo $current_tab === 'upload' ? 'active' : ''; ?>" role="tabpanel" aria-labelledby="pit-tab-btn-upload" <?php echo $current_tab !== 'upload' ? 'hidden' : ''; ?>>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Wgrywanie', 'securedownloader' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Możesz wgrać 1 lub więcej dokumentów jednocześnie.', 'securedownloader' ); ?>
                    <?php
                    $max_files = (int) ini_get( 'max_file_uploads' );
                    $post_max  = ini_get( 'post_max_size' );
                    if ( $max_files > 0 || $post_max ) {
                        echo '<br><span class="pit-upload-limits">';
                        printf(
                            /* translators: 1: max number of files, 2: post_max_size (e.g. 8M) */
                            esc_html__( 'Limit PHP: max %1$s plików jednocześnie, łącznie do %2$s.', 'securedownloader' ),
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
                    <input type="hidden" name="pit_redirect_tab" value="upload">

                    <div class="pit-form-row">
                        <input type="file" name="pit_pdfs[]" multiple accept=".pdf" required id="pit-upload-files">
                    </div>

                    <div class="pit-form-row pit-upload-progress-wrap" id="pit-upload-progress-wrap" style="display:none;">
                        <div class="pit-upload-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="pit-upload-progress-bar" id="pit-upload-progress-bar" style="width:0%;"></div>
                        </div>
                        <span class="pit-upload-progress-text" id="pit-upload-progress-text"></span>
                    </div>
                    <?php if ( (int) get_option( 'pit_developer_mode', 0 ) === 1 ) : ?>
                    <div class="pit-form-row pit-upload-debug-wrap" id="pit-upload-debug-wrap" style="display:none;">
                        <label class="pit-upload-debug-label"><?php esc_html_e( 'Log rozpoznawania (Ctrl+A aby skopiować)', 'securedownloader' ); ?></label>
                        <pre class="pit-upload-debug-log" id="pit-upload-debug-log" aria-label="<?php esc_attr_e( 'Log rozpoznawania plików', 'securedownloader' ); ?>"></pre>
                        <div class="pit-upload-debug-actions" id="pit-upload-debug-actions" style="display:none;">
                            <p class="pit-upload-debug-done-text"><?php esc_html_e( 'Wgrywanie zakończone. Skopiuj log (Ctrl+A), następnie kliknij Kontynuuj.', 'securedownloader' ); ?></p>
                            <button type="button" class="button button-primary" id="pit-upload-continue-btn"><?php esc_html_e( 'Kontynuuj', 'securedownloader' ); ?></button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="pit-form-row">
                        <button type="submit" class="button button-primary" id="pit-upload-submit">
                            <?php esc_html_e( 'Wgraj dokumenty', 'securedownloader' ); ?>
                        </button>
                    </div>
                </form>
                <div class="pit-form-row pit-scan-uploaded-row" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--pit-border, #ddd);">
                    <p style="margin: 0 0 8px 0; font-weight: 600;"><?php esc_html_e( 'Wyszukaj wgrane pliki', 'securedownloader' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-scan-uploaded-form" style="display: inline-block;">
                        <?php wp_nonce_field( 'pit_scan_uploaded_files', 'pit_scan_nonce' ); ?>
                        <input type="hidden" name="action" value="pit_scan_uploaded_files">
                        <input type="hidden" name="pit_redirect_tab" value="upload">
                        <button type="submit" class="button button-secondary" id="pit-scan-uploaded-btn">
                            <?php esc_html_e( 'Wyszukaj wgrane pliki', 'securedownloader' ); ?>
                        </button>
                    </form>
                    <p class="description" style="margin: 8px 0 0 0; display: block;">
                        <?php esc_html_e( 'Skanuje katalog uploadów na serwerze, dodaje do listy dokumentów znalezione pliki PDF (rozpoznanie jak przy wgrywaniu) i uzupełnia PESEL z treści PDF.', 'securedownloader' ); ?>
                    </p>
                </div>
            <script>
            (function() {
                var form = document.getElementById('pit-upload-form');
                var fileInput = document.getElementById('pit-upload-files');
                var progressWrap = document.getElementById('pit-upload-progress-wrap');
                var progressBar = document.getElementById('pit-upload-progress-bar');
                var progressText = document.getElementById('pit-upload-progress-text');
                var debugWrap = document.getElementById('pit-upload-debug-wrap');
                var debugLog = document.getElementById('pit-upload-debug-log');
                var debugActions = document.getElementById('pit-upload-debug-actions');
                var continueBtn = document.getElementById('pit-upload-continue-btn');
                var submitBtn = document.getElementById('pit-upload-submit');
                var pendingRedirectUrl = null;
                if (continueBtn) {
                    continueBtn.addEventListener('click', function() {
                        if (pendingRedirectUrl) { window.location.href = pendingRedirectUrl; }
                    });
                }
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
                    pendingRedirectUrl = null;
                    if (debugActions) debugActions.style.display = 'none';
                    if (progressWrap) progressWrap.style.display = '';
                    if (debugWrap) { debugWrap.style.display = ''; }
                    if (debugLog) { debugLog.textContent = ''; }
                    if (submitBtn) submitBtn.disabled = true;
                    if (progressBar) progressBar.style.width = '0%';
                    function updateProgress(n, ofTotal) {
                        var pct = ofTotal > 0 ? Math.round((n / ofTotal) * 100) : 0;
                        if (progressBar) progressBar.style.width = pct + '%';
                        if (progressText) progressText.textContent = 'Wgrywanie pliku ' + n + ' z ' + ofTotal;
                    }
                    function setDebugLog(lines) {
                        if (!debugLog || !lines) return;
                        var text = Array.isArray(lines) ? lines.join('\n') : String(lines);
                        debugLog.textContent = text;
                        debugLog.scrollTop = debugLog.scrollHeight;
                    }
                    function sendNext() {
                        if (current >= total) {
                            if (debugWrap && debugActions && debugLog && debugLog.textContent.trim() !== '') {
                                debugActions.style.display = '';
                                pendingRedirectUrl = window.location.href;
                                if (submitBtn) submitBtn.disabled = false;
                                debugActions.scrollIntoView({ behavior: 'smooth', block: 'end' });
                            } else {
                                if (progressWrap) progressWrap.style.display = 'none';
                                if (submitBtn) submitBtn.disabled = false;
                            }
                            return;
                        }
                        updateProgress(current + 1, total);
                        var fd = new FormData();
                        fd.append('pit_nonce', nonceEl.value);
                        fd.append('action', 'pit_upload_files');
                        fd.append('pit_upload_chunk_index', String(current));
                        fd.append('pit_upload_total_chunks', String(total));
                        fd.append('pit_pdfs[]', files[current]);
                        var tabEl = form.querySelector('input[name="pit_redirect_tab"]');
                        if (tabEl && tabEl.value) fd.append('pit_redirect_tab', tabEl.value);
                        fetch(uploadUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function(r) { return r.text(); })
                            .then(function(text) {
                                var data;
                                try {
                                    data = JSON.parse(text);
                                } catch (e) {
                                    var preview = text.length > 300 ? text.substring(0, 300) + '...' : text;
                                    throw new Error('Odpowiedź serwera nie jest JSON. Początek: ' + preview.replace(/\s+/g, ' ').trim());
                                }
                                return data;
                            })
                            .then(function(data) {
                                if (data && data.success && data.data) {
                                    if (data.data.debug_log && data.data.debug_log.length) setDebugLog(data.data.debug_log);
                                    if (data.data.done === true && data.data.redirect_url) {
                                        if (debugWrap && debugActions) {
                                            pendingRedirectUrl = data.data.redirect_url;
                                            if (progressBar) progressBar.style.width = '100%';
                                            if (progressText) progressText.textContent = '';
                                            debugActions.style.display = '';
                                            debugActions.scrollIntoView({ behavior: 'smooth', block: 'end' });
                                            if (submitBtn) submitBtn.disabled = false;
                                            return;
                                        }
                                        window.location.href = data.data.redirect_url;
                                        return;
                                    }
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

            <div id="pit-tab-wzorce" class="pit-tab-panel <?php echo $current_tab === 'wzorce' ? 'active' : ''; ?>" role="tabpanel" aria-labelledby="pit-tab-btn-wzorce" <?php echo $current_tab !== 'wzorce' ? 'hidden' : ''; ?>>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Wzorce', 'securedownloader' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Wzorce stosowane przy imporcie do wyszukiwania danych (np. PESEL) w dokumentach. Strona – numer strony, Sekcja – np. sekcja formularza, Pole – etykieta pola.', 'securedownloader' ); ?></p>
                <div class="pit-wzorce-help description" style="margin-top: 12px; padding: 14px; background: #f0f6fc; border-left: 4px solid var(--pit-accent, #2c5282); max-width: 800px;">
                    <p style="margin-top: 0;"><strong><?php esc_html_e( 'Jak działa wyszukiwanie', 'securedownloader' ); ?></strong><br>
                    <?php esc_html_e( 'Wtyczka czyta tekst wyciągnięty z PDF (strona po stronie). Dla każdego pliku wybiera wzorce, których kolumna „Nazwa pliku” pasuje do nazwy pliku (znak * oznacza dowolny ciąg). Dla wybranego wzorca szuka w treści strony najpierw tekstu „Sekcja”, potem w jego pobliżu etykiety „Pole” i odczytuje wartość (np. 11 cyfr PESEL). Kolumna „Szukam” określa, co zapisujemy (PESEL, NAZWISKO, IMIĘ, RRRR).', 'securedownloader' ); ?></p>
                    <p><strong><?php esc_html_e( 'Przykład 1 – formularz PIT-11', 'securedownloader' ); ?></strong><br>
                    <?php esc_html_e( 'Plik np. „Kowalski Jan PIT-11 2024.pdf” pasuje do wzorca nazwy „NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf”. Wzorzec: Szukam = PESEL, Strona = 1, Sekcja = „C. DANE IDENTYFIKACYJNE”, Pole = „numer PESEL”. Wtyczka znajduje w PDF nagłówek sekcji „C. DANE IDENTYFIKACYJNE”, potem etykietę „numer PESEL” i odczytuje 11 cyfr obok – to PESEL osoby.', 'securedownloader' ); ?></p>
                    <p><strong><?php esc_html_e( 'Przykład 2 – informacja roczna', 'securedownloader' ); ?></strong><br>
                    <?php esc_html_e( 'Plik np. „Informacja roczna dla Kowalski Jan.pdf” pasuje do wzorca „Informacja roczna dla NAZWISKO IMIĘ.pdf”. Wzorzec: Szukam = PESEL, Strona = 1, Sekcja = „Dane osoby ubezpieczonej”, Pole = „Identyfikator”. Wtyczka szuka na stronie 1 bloku „Dane osoby ubezpieczonej”, w nim etykiety „Identyfikator” i pobiera numer PESEL.', 'securedownloader' ); ?></p>
                    <p style="margin-bottom: 0;"><?php esc_html_e( 'Aby dodać nowy wzorzec: kliknij „Dodaj wiersz”, wypełnij Szukam (PESEL / NAZWISKO / IMIĘ / RRRR), numer Strony, dokładny tekst Sekcji i Pole z Twojego PDF oraz wzorzec Nazwa pliku (np. *MojaFirma*RRRR.pdf). Zapisz wzorce.', 'securedownloader' ); ?></p>
                </div>
                <?php if ( isset( $_GET['pit_patterns_saved'] ) && $_GET['pit_patterns_saved'] === '1' ) : ?>
                    <div class="notice notice-success is-dismissible" style="margin-top: 10px;"><p><?php esc_html_e( 'Wzorce zapisane.', 'securedownloader' ); ?></p></div>
                <?php endif; ?>
                <?php if ( isset( $_GET['pit_patterns_reset'] ) && $_GET['pit_patterns_reset'] === '1' ) : ?>
                    <div class="notice notice-success is-dismissible" style="margin-top: 10px;"><p><?php esc_html_e( 'Wzorce przywrócone do domyślnych.', 'securedownloader' ); ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-wzorce-form" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'pit_save_import_patterns', 'pit_import_patterns_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_save_import_patterns">
                    <input type="hidden" name="pit_redirect_tab" value="wzorce">
                    <?php $wzorce = $this->get_import_patterns_option(); ?>
                    <div class="pit-wzorce-table-wrap">
                    <table class="pit-table widefat striped" id="pit-wzorce-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Szukam', 'securedownloader' ); ?></th>
                                <th><?php esc_html_e( 'Strona', 'securedownloader' ); ?></th>
                                <th><?php esc_html_e( 'Sekcja', 'securedownloader' ); ?></th>
                                <th><?php esc_html_e( 'Pole', 'securedownloader' ); ?></th>
                                <th><?php esc_html_e( 'Nazwa pliku', 'securedownloader' ); ?></th>
                                <th style="width: 90px; min-width: 90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $wzorce as $idx => $row ) : ?>
                                <tr class="pit-wzorce-row">
                                    <td><input type="hidden" name="pit_import_patterns[<?php echo (int) $idx; ?>][_deleted]" value="0" class="pit-wzorce-deleted"><input type="text" name="pit_import_patterns[<?php echo (int) $idx; ?>][wzorzec]" value="<?php echo esc_attr( $row['wzorzec'] ); ?>" class="regular-text" placeholder="PESEL"></td>
                                    <td><input type="text" name="pit_import_patterns[<?php echo (int) $idx; ?>][strona]" value="<?php echo esc_attr( $row['strona'] ); ?>" class="small-text" placeholder="1" maxlength="10"></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][pozycja]" class="pit-wzorce-field regular-text" placeholder="C. DANE IDENTYFIKACYJNE" rows="3"><?php echo esc_textarea( $row['pozycja'] ); ?></textarea></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][pole]" class="pit-wzorce-field regular-text" placeholder="numer PESEL" rows="3"><?php echo esc_textarea( $row['pole'] ); ?></textarea></td>
                                    <td><textarea name="pit_import_patterns[<?php echo (int) $idx; ?>][nazwa_pliku]" class="pit-wzorce-field regular-text" placeholder="*.pdf" rows="3"><?php echo esc_textarea( $row['nazwa_pliku'] ?? '' ); ?></textarea></td>
                                    <td style="white-space: nowrap;"><button type="button" class="button pit-remove-wzorce-row" aria-label="<?php esc_attr_e( 'Usuń wiersz', 'securedownloader' ); ?>"><?php esc_html_e( 'Usuń', 'securedownloader' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="pit-wzorce-actions" style="margin-top: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
                        <button type="button" class="button" id="pit-add-wzorce-row"><?php esc_html_e( 'Dodaj wiersz', 'securedownloader' ); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz wzorce', 'securedownloader' ); ?></button>
                    </div>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pit-reset-patterns-form" style="display: inline; margin: 0; margin-top: 12px;" onsubmit="return confirm(<?php echo wp_json_encode( __( 'Czy na pewno przywrócić wszystkie domyślne wzorce? Obecna lista zostanie zastąpiona.', 'securedownloader' ) ); ?>);">
                    <?php wp_nonce_field( 'pit_reset_import_patterns', 'pit_reset_patterns_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_reset_import_patterns">
                    <input type="hidden" name="pit_redirect_tab" value="wzorce">
                    <button type="submit" class="button"><?php esc_html_e( 'Ustaw standardowe wartości', 'securedownloader' ); ?></button>
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
                            '<td><textarea name="pit_import_patterns[' + idx + '][pozycja]" class="pit-wzorce-field regular-text" placeholder="C. DANE IDENTYFIKACYJNE" rows="3"></textarea></td>' +
                            '<td><textarea name="pit_import_patterns[' + idx + '][pole]" class="pit-wzorce-field regular-text" placeholder="numer PESEL" rows="3"></textarea></td>' +
                            '<td><textarea name="pit_import_patterns[' + idx + '][nazwa_pliku]" class="pit-wzorce-field regular-text" placeholder="*.pdf" rows="3"></textarea></td>' +
                            '<td><button type="button" class="button pit-remove-wzorce-row" aria-label="' . esc_attr( __( 'Usuń wiersz', 'securedownloader' ) ) . '">' . esc_html( __( 'Usuń', 'securedownloader' ) ) . '</button></td>';
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

            <div id="pit-tab-dane-firmy" class="pit-tab-panel <?php echo $current_tab === 'dane-firmy' ? 'active' : ''; ?>" role="tabpanel" aria-labelledby="pit-tab-btn-dane-firmy" <?php echo $current_tab !== 'dane-firmy' ? 'hidden' : ''; ?>>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Firma', 'securedownloader' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'pit_save_company_data', 'pit_company_data_nonce' ); ?>
                    <input type="hidden" name="action" value="pit_save_company_data">
                    <input type="hidden" name="pit_redirect_tab" value="dane-firmy">
                    <?php
                    $pit_company_name    = get_option( 'pit_company_name', '' );
                    $pit_company_address = get_option( 'pit_company_address', '' );
                    $pit_company_nip     = get_option( 'pit_company_nip', '' );
                    ?>
                    <div class="pit-form-row">
                        <label for="pit_company_name"><?php esc_html_e( 'Nazwa firmy', 'securedownloader' ); ?></label>
                        <input type="text" name="pit_company_name" id="pit_company_name" value="<?php echo esc_attr( $pit_company_name ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <label for="pit_company_address"><?php esc_html_e( 'Adres firmy', 'securedownloader' ); ?></label>
                        <input type="text" name="pit_company_address" id="pit_company_address" value="<?php echo esc_attr( $pit_company_address ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <label for="pit_company_nip"><?php esc_html_e( 'NIP firmy', 'securedownloader' ); ?></label>
                        <input type="text" name="pit_company_nip" id="pit_company_nip" value="<?php echo esc_attr( $pit_company_nip ); ?>" class="regular-text">
                    </div>
                    <div class="pit-form-row">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz zmiany', 'securedownloader' ); ?></button>
                    </div>
                </form>
            </div>

            <div id="pit-tab-manual" class="pit-tab-panel <?php echo $current_tab === 'manual' ? 'active' : ''; ?>" role="tabpanel" aria-labelledby="pit-tab-btn-manual" <?php echo $current_tab !== 'manual' ? 'hidden' : ''; ?>>
                <h3 class="pit-tab-panel-title"><?php esc_html_e( 'Podręcznik użytkownika', 'securedownloader' ); ?></h3>
                <div class="pit-manual-content">
                    <?php
                    $manual_locale = function_exists( 'get_user_locale' ) && is_user_logged_in() ? get_user_locale() : get_locale();
                    $manual_is_pl   = ( $manual_locale === 'pl_PL' );
                    ?>
                    <?php if ( $manual_is_pl ) : ?>
                        <div class="pit-manual-lang pit-manual-pl">
                            <p><strong><?php esc_html_e( 'Do czego służy wtyczka', 'securedownloader' ); ?></strong><br>
                            <?php esc_html_e( 'Secure Downloader umożliwia menadżerom wgrywanie dokumentów PDF (np. PIT-11), a klientom – pobieranie ich po weryfikacji (PESEL, imię i nazwisko). Dokumenty są grupowane po osobie i roku.', 'securedownloader' ); ?></p>

                            <p><strong><?php esc_html_e( 'Zakładka Dokumenty', 'securedownloader' ); ?></strong><br>
                            <?php esc_html_e( 'Tabela listuje wszystkie wgrane dokumenty. Kolumny: Nazwisko i imię (z pliku), PESEL (jeśli rozpoznany lub ustawiony ręcznie), Rok, Data pobrania. Zaznacz checkboxy obok wybranych wierszy i kliknij „Usuń zaznaczone”, aby usunąć dokumenty. Przy braku PESEL kliknij „Nie dopasowano”, wpisz 11 cyfr i „Zapisz”. „Raport PDF – rok” i przycisk „Generuj raport PDF” tworzą zbiorczy raport dla wybranego roku.', 'securedownloader' ); ?></p>

                            <p><strong><?php esc_html_e( 'Zakładka Wgrywanie', 'securedownloader' ); ?></strong><br>
                            <?php esc_html_e( 'Pole „Wybierz pliki” – wybierz jeden lub wiele plików PDF. Nazwy plików muszą pasować do wzorców z zakładki Wzorce (np. NAZWISKO IMIĘ, rok). Przycisk „Wgraj dokumenty” rozpoczyna wgrywanie; postęp i ewentualne błędy są wyświetlane. „Wyszukaj wgrane pliki” skanuje katalog na serwerze i dopisuje znalezione PDF do listy (z rozpoznawaniem jak przy wgrywaniu). W trybie deweloperskim (Ustawienia) widoczny jest szczegółowy log rozpoznawania.', 'securedownloader' ); ?></p>

                            <p><strong><?php esc_html_e( 'Zakładka Wzorce', 'securedownloader' ); ?></strong><br>
                            <?php esc_html_e( 'Wzorce określają, jak z nazwy i treści PDF wyciągane są: osoba (imię, nazwisko), PESEL i rok. Kolumny: Szukam (np. nagłówek „PIT-11”), Strona, Sekcja, Pole, Nazwa pliku. Nazwa pliku musi pasować do Twoich plików (np. „NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf”). Po zmianach kliknij „Zapisz wzorce”. „Przywróć domyślne” przywraca fabryczne wzorce.', 'securedownloader' ); ?></p>

                            <p><strong><?php esc_html_e( 'Zakładka Firma', 'securedownloader' ); ?></strong><br>
                            <?php esc_html_e( 'Pola: Nazwa firmy, Adres firmy, NIP firmy – wyświetlane klientowi na stronie pobierania oraz w raportach PDF. Zapisz zmiany po edycji.', 'securedownloader' ); ?></p>
                        </div>
                    <?php else : ?>
                        <div class="pit-manual-lang pit-manual-en">
                            <p><strong>What the plugin does</strong><br>
                            Secure Downloader lets managers upload PDF documents (e.g. PIT-11) and clients download them after verification (PESEL, first and last name). Documents are grouped by person and year.</p>

                            <p><strong>Documents tab</strong><br>
                            The table lists all uploaded documents. Columns: Last and first name (from file), PESEL (if recognised or set manually), Year, Download date. Check the boxes next to rows and click "Delete selected" to remove documents. If PESEL is missing, click "Not matched", enter 11 digits and "Save". "PDF report – year" and "Generate PDF report" create a summary report for the selected year.</p>

                            <p><strong>Upload tab</strong><br>
                            "Select files" – choose one or more PDF files. File names must match the patterns from the Patterns tab (e.g. LASTNAME FIRSTNAME, year). The "Upload documents" button starts the upload; progress and any errors are shown. "Search uploaded files" scans the server folder and adds found PDFs to the list (with the same recognition as upload). In developer mode (Settings) a detailed recognition log is shown.</p>

                            <p><strong>Patterns tab</strong><br>
                            Patterns define how the plugin extracts person (name), PESEL and year from file names and PDF content. Columns: Search for (e.g. header "PIT-11"), Page, Section, Field, File name. File name must match your files (e.g. "LASTNAME FIRSTNAME*PIT-11*year RRRR.pdf"). After changes click "Save patterns". "Restore defaults" restores factory patterns.</p>

                            <p><strong>Company tab</strong><br>
                            Fields: Company name, Company address, Company NIP – shown to the client on the download page and in PDF reports. Save changes after editing.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pit-panel-footer-cache-notice">
                <p><?php esc_html_e( 'Po wgraniu dokumentów, mogą nie być widoczne, mimo odświeżania (CTRL+F5) z powodu cache na serwerze. Nie pozostaje nic innego jak czekać do kilku minut. Zmiana zwykle staje się widoczna po mniej niż minucie. Możesz użyć przycisku „Wyszukaj wgrane pliki”, aby ponownie przeskanować wgrane pliki. Ustawienia cache na serwerach mogą się różnić, czasy odświeżania mogą być zmienne.', 'securedownloader' ); ?></p>
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
        $is_chunked = $this->is_chunked_upload_request();
        if ( $is_chunked ) {
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            ob_start();
        }

        if ( ! $this->check_access() ) {
            if ( $is_chunked ) {
                ob_end_clean();
                wp_send_json_error( [ 'message' => __( 'Brak uprawnień.', 'securedownloader' ) ] );
            }
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_nonce'] ?? '', 'pit_upload_nonce' ) ) {
            if ( $is_chunked ) {
                ob_end_clean();
                wp_send_json_error( [ 'message' => __( 'Błąd bezpieczeństwa.', 'securedownloader' ) ] );
            }
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        if ( empty( $_FILES['pit_pdfs'] ) || empty( $_FILES['pit_pdfs']['name'] ) ) {
            if ( $is_chunked ) {
                ob_end_clean();
                wp_send_json_error( [ 'message' => __( 'Nie wybrano dokumentów.', 'securedownloader' ) ] );
            }
            wp_die( __( 'Nie wybrano dokumentów.', 'securedownloader' ) );
        }

        set_time_limit( 120 );
        if ( function_exists( 'pit_raise_memory_for_pdf' ) ) {
            pit_raise_memory_for_pdf();
        }
        pit_debug_log( 'handle_upload: after set_time_limit' );

        if ( function_exists( 'pit_create_upload_directory' ) ) {
            pit_create_upload_directory();
        }

        $chunk_key = 'pit_upload_chunk_state_' . get_current_user_id();

        if ( $is_chunked ) {
            $state = get_transient( $chunk_key );
            if ( ! is_array( $state ) ) {
                $state = [ 'uploaded' => 0, 'errors' => 0, 'skipped' => 0, 'failed' => [], 'debug_log' => [] ];
            }
            if ( ! isset( $state['debug_log'] ) || ! is_array( $state['debug_log'] ) ) {
                $state['debug_log'] = [];
            }
        } else {
            $state = [ 'uploaded' => 0, 'errors' => 0, 'skipped' => 0, 'failed' => [], 'debug_log' => [] ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $db      = PIT_Database::get_instance();
        $filters = get_option( 'pit_filename_filters', [] );
        if ( empty( $filters ) || ! is_array( $filters ) ) {
            $filters = pit_get_default_filename_filters();
        }

        $files   = $_FILES['pit_pdfs'];
        $count   = count( $files['name'] );
        $uploaded = 0;
        $errors   = 0;
        $skipped  = 0;
        $failed   = [];
        $dev_log  = (int) get_option( 'pit_developer_mode', 0 ) === 1;
        $chunk_log = [];
        $chunk_index_one = $is_chunked ? (int) ( $_POST['pit_upload_chunk_index'] ?? 0 ) + 1 : 1;
        $total_chunks_one = $is_chunked ? (int) ( $_POST['pit_upload_total_chunks'] ?? 1 ) : $count;
        pit_debug_log( 'handle_upload: files count=' . $count . ( $is_chunked ? ' (chunked)' : '' ) );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $i > 0 && $i % 10 === 0 ) {
                pit_debug_log( 'handle_upload: processed ' . $i . '/' . $count );
            }
            $num = $is_chunked ? $chunk_index_one : ( $i + 1 );
            $den = $is_chunked ? $total_chunks_one : $count;
            $line_prefix = $dev_log ? '[' . $num . '/' . $den . '] ' : '';

            if ( $files['error'][$i] !== UPLOAD_ERR_OK ) {
                $errors++;
                $failed[] = [
                    'name'   => $files['name'][$i],
                    'reason' => __( 'Błąd wgrywania pliku.', 'securedownloader' ),
                ];
                if ( $dev_log ) {
                    $chunk_log[] = $line_prefix . __( 'Plik:', 'securedownloader' ) . ' ' . $files['name'][$i];
                    $chunk_log[] = '  → ' . __( 'błąd: Błąd wgrywania pliku (upload error code)', 'securedownloader' );
                }
                continue;
            }

            $tmp_name = $files['tmp_name'][$i];
            $name     = $files['name'][$i];
            $type     = $files['type'][$i];

            if ( 'application/pdf' !== $type && ! str_ends_with( strtolower( $name ), '.pdf' ) ) {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Nieprawidłowy typ pliku (wymagany PDF).', 'securedownloader' ),
                ];
                if ( $dev_log ) {
                    $chunk_log[] = $line_prefix . __( 'Plik:', 'securedownloader' ) . ' ' . $name;
                    $chunk_log[] = '  → ' . __( 'błąd: Nieprawidłowy typ pliku (wymagany PDF)', 'securedownloader' );
                }
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
                $reason = __( 'Nie rozpoznano wzorca nazwy pliku.', 'securedownloader' );
                if ( $dev_log ) {
                    $from_option = get_option( 'pit_filename_filters', [] );
                    $used_default = ( empty( $from_option ) || ! is_array( $from_option ) );
                    $filter_source = $used_default
                        ? __( 'domyślne (pit_get_default_filename_filters())', 'securedownloader' )
                        : __( 'opcja pit_filename_filters w Ustawieniach', 'securedownloader' );
                    $reason .= "\n\n" . __( 'Szczegóły (tryb deweloperski):', 'securedownloader' )
                        . "\n- " . __( 'Nazwa pliku:', 'securedownloader' ) . ' "' . $name . '"'
                        . "\n- " . sprintf(
                            /* translators: 1: number of filters, 2: filter source description */
                            __( 'Sprawdzono %1$d filtrów (%2$s). Żaden filtr nie dopasował nazwy.', 'securedownloader' ),
                            count( $filters ),
                            $filter_source
                        )
                        . "\n- " . __( 'Fallback parse_filename() wymaga formatu: PIT-11_rok_RRRR_Nazwisko_Imię_11cyfrPESEL.pdf (np. PIT-11_rok_2025_Kowalski_Jan_12345678901.pdf).', 'securedownloader' )
                        . "\n- " . __( 'Co zrobić: dopasuj nazwę pliku do jednego z wzorców w Ustawieniach (Filtry nazw plików) albo zmień wzorce tak, by obejmowały Twoje nazwy.', 'securedownloader' );
                    $chunk_log[] = $line_prefix . __( 'Plik:', 'securedownloader' ) . ' ' . $name;
                    $chunk_log[] = '  → ' . __( 'błąd: Nie rozpoznano wzorca nazwy pliku', 'securedownloader' );
                }
                $failed[] = [
                    'name'   => $name,
                    'reason' => $reason,
                ];
                continue;
            }

            if ( $dev_log ) {
                $chunk_log[] = $line_prefix . __( 'Plik:', 'securedownloader' ) . ' ' . $name;
                $chunk_log[] = '  → full_name=' . ( $parsed['full_name'] ?? '' ) . ', tax_year=' . ( $parsed['tax_year'] ?? '' ) . ', pesel=' . ( $parsed['pesel'] ?? '' );
            }

            $target_dir = pit_get_upload_dir() . $parsed['tax_year'] . '/';

            if ( ! is_dir( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
                if ( ! is_dir( $target_dir ) && function_exists( 'pit_create_upload_directory' ) ) {
                    pit_create_upload_directory();
                    wp_mkdir_p( $target_dir );
                }
            }

            $safe_name   = sanitize_file_name( $name );
            $target_path = $target_dir . $safe_name;

            if ( $dev_log ) {
                $chunk_log[] = '  → ' . __( 'próba zapisu PDF, pełna ścieżka:', 'securedownloader' ) . ' ' . $target_path;
            }

            if ( ! move_uploaded_file( $tmp_name, $target_path ) ) {
                $errors++;
                $reason = __( 'Nie udało się zapisać pliku na serwerze.', 'securedownloader' );
                if ( ! is_dir( $target_dir ) ) {
                    $reason .= ' ' . __( 'Katalog docelowy nie istnieje (np. brak uprawnień do utworzenia).', 'securedownloader' );
                } elseif ( ! is_writable( $target_dir ) ) {
                    $reason .= ' ' . __( 'Brak uprawnień do zapisu w katalogu – ustaw np. chmod 755 lub 775 dla katalogu uploads wtyczki.', 'securedownloader' );
                } else {
                    $reason .= ' ' . __( 'Sprawdź uprawnienia do katalogu uploads wtyczki (np. chmod 755) i czy serwer WWW ma prawo zapisu.', 'securedownloader' );
                }
                $failed[] = [
                    'name'   => $name,
                    'reason' => $reason,
                ];
                if ( $dev_log ) {
                    $chunk_log[] = '  → ' . __( 'błąd: Nie udało się zapisać pliku na serwerze', 'securedownloader' ) . ' (' . $target_path . ')';
                    if ( ! is_dir( $target_dir ) ) {
                        $chunk_log[] = '  → katalog nie istnieje: ' . $target_dir;
                    } elseif ( ! is_writable( $target_dir ) ) {
                        $chunk_log[] = '  → katalog nie ma uprawnień do zapisu: ' . $target_dir;
                    }
                }
                continue;
            }

            if ( $dev_log ) {
                $chunk_log[] = '  → ' . __( 'zapis PDF na dysk: OK', 'securedownloader' ) . ', ' . $target_path;
            }

            $year_from_pdf = null;
            $text = $this->extract_text_from_pdf( $target_path );
            if ( $text !== '' ) {
                $year_from_pdf = $this->extract_year_from_pdf_by_rules( $text, $target_path );
            }
            if ( $year_from_pdf !== null && (int) $year_from_pdf !== (int) $parsed['tax_year'] ) {
                @unlink( $target_path );
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => sprintf(
                        /* translators: 1: year from PDF, 2: year from filename */
                        __( 'Rok w dokumencie PDF (%1$d) nie zgadza się z rokiem w nazwie pliku (%2$d).', 'securedownloader' ),
                        $year_from_pdf,
                        (int) $parsed['tax_year']
                    ),
                ];
                if ( $dev_log ) {
                    $chunk_log[] = '  → ' . sprintf(
                        __( 'błąd: Rok w PDF (%1$d) ≠ rok w nazwie (%2$d)', 'securedownloader' ),
                        $year_from_pdf,
                        (int) $parsed['tax_year']
                    );
                }
                continue;
            }

            if ( $dev_log && $year_from_pdf !== null ) {
                $chunk_log[] = '  → rok z PDF: ' . $year_from_pdf;
            }

            $file_url = pit_get_upload_url() . $parsed['tax_year'] . '/' . $safe_name;

            $result = $db->insert_file( [
                'full_name' => $parsed['full_name'],
                'pesel'     => $parsed['pesel'] ?? '',
                'tax_year'  => $parsed['tax_year'],
                'file_path' => $target_path,
                'file_url'  => $file_url,
            ] );

            if ( $result ) {
                $uploaded++;
                if ( $dev_log ) {
                    $chunk_log[] = '  → ' . __( 'zapis do bazy: OK', 'securedownloader' );
                }
            } else {
                $errors++;
                $failed[] = [
                    'name'   => $name,
                    'reason' => __( 'Błąd zapisu do bazy danych.', 'securedownloader' ),
                ];
                if ( $dev_log ) {
                    $chunk_log[] = '  → ' . __( 'błąd: Błąd zapisu do bazy danych', 'securedownloader' );
                }
                if ( file_exists( $target_path ) ) {
                    unlink( $target_path );
                }
            }
        }

        if ( $dev_log && ! empty( $chunk_log ) ) {
            $state['debug_log'] = array_merge( $state['debug_log'], $chunk_log );
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
                    $this->collect_pesel_diagnostics_after_upload( $db );
                    if ( $dev_log ) {
                        $state['debug_log'] = array_merge(
                            $state['debug_log'],
                            $this->get_pesel_debug_log_lines( $db )
                        );
                    }
                }
                delete_transient( $chunk_key );
                $redirect = add_query_arg( [
                    'pit_uploaded' => $state['uploaded'],
                    'pit_errors'   => $state['errors'],
                    'pit_skipped'  => $state['skipped'],
                    'pit_nocache'  => time(),
                ], wp_get_referer() ?: home_url() );
                if ( ! empty( $state['failed'] ) ) {
                    set_transient( 'pit_upload_failed_files_' . get_current_user_id(), $state['failed'], 60 );
                    $redirect = add_query_arg( 'pit_upload_failed', '1', $redirect );
                }
                $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
                if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
                    $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
                }
                $response = [ 'done' => true, 'redirect_url' => $redirect ];
                if ( $dev_log && ! empty( $state['debug_log'] ) ) {
                    $response['debug_log'] = $state['debug_log'];
                }
                if ( ob_get_level() ) {
                    ob_end_clean();
                }
                wp_send_json_success( $response );
            }
            $response = [ 'done' => false ];
            if ( $dev_log && ! empty( $state['debug_log'] ) ) {
                $response['debug_log'] = $state['debug_log'];
            }
            if ( ob_get_level() ) {
                ob_end_clean();
            }
            wp_send_json_success( $response );
        }

        if ( $state['uploaded'] > 0 ) {
            pit_debug_log( 'handle_upload: before fill_missing_pesel_after_upload' );
            $this->fill_missing_pesel_after_upload( $db );
            $this->collect_pesel_diagnostics_after_upload( $db );
        }

        pit_debug_log( 'handle_upload: before redirect' );
        $redirect = add_query_arg( [
            'pit_uploaded' => $state['uploaded'],
            'pit_errors'   => $state['errors'],
            'pit_skipped'  => $state['skipped'],
            'pit_nocache'  => time(),
        ], wp_get_referer() ?: home_url() );

        if ( ! empty( $state['failed'] ) ) {
            set_transient( 'pit_upload_failed_files_' . get_current_user_id(), $state['failed'], 60 );
            $redirect = add_query_arg( 'pit_upload_failed', '1', $redirect );
        }

        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
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
     * Diagnostyka: dlaczego nie rozpoznano PESEL dla danej osoby (tryb deweloperski).
     * Zwraca listę zrozumiałych przyczyn – do wyświetlenia po wgraniu, gdy PESEL pozostał pusty.
     *
     * @param string       $full_name full_name osoby.
     * @param PIT_Database $db        Instancja bazy.
     * @return string[] Lista przyczyn (po polsku).
     */
    private function diagnose_pesel_failure( string $full_name, PIT_Database $db ): array {
        global $wpdb;

        $key   = pit_person_match_key( $full_name );
        if ( $key === '' ) {
            return [ __( 'Nie można zidentyfikować osoby (pusta full_name).', 'securedownloader' ) ];
        }

        $table = PIT_Database::$table_files;
        $all   = $wpdb->get_results(
            "SELECT id, full_name, file_path FROM {$table} WHERE (pesel IS NULL OR pesel = '') ORDER BY id ASC",
            OBJECT
        );
        $rows  = array_filter( $all, function ( $row ) use ( $key ) {
            return pit_person_match_key( (string) $row->full_name ) === $key;
        } );
        $rows  = array_values( $rows );

        if ( empty( $rows ) ) {
            return [ __( 'Brak plików w bazie z pustym PESEL dla tej osoby.', 'securedownloader' ) ];
        }

        $reasons   = [];
        $first_row = $rows[0];
        $file_path = $first_row->file_path;
        $filename  = $file_path !== '' ? basename( $file_path ) : '';

        if ( ! preg_match( '/\d{11}/', $filename ) ) {
            $reasons[] = __( 'W nazwie pliku nie ma numeru PESEL (11 cyfr). Wtyczka szuka PESEL w treści PDF – poniżej wynik tej próby.', 'securedownloader' );
        }

        if ( ! file_exists( $file_path ) ) {
            $reasons[] = __( 'Plik PDF nie istnieje na dysku (ścieżka usunięta lub niedostępna).', 'securedownloader' );
            return $reasons;
        }

        $text = $this->extract_text_from_pdf( $file_path );
        if ( $text === '' ) {
            $reasons[] = __( 'Nie udało się wyciągnąć tekstu z PDF. Upewnij się, że folder vendor wtyczki jest wgrany na serwer (biblioteka PHP do odczytu PDF). Alternatywnie na serwerze może być dostępne narzędzie pdftotext (poppler-utils) – na hostingu często jest wyłączone wywołanie shell (shell_exec).', 'securedownloader' );
            return $reasons;
        }

        $rules = $this->get_pesel_search_rules_option( $filename );
        if ( empty( $rules ) ) {
            $reasons[] = sprintf(
                /* translators: %s: filename */
                __( 'Brak wzorców w zakładce Wzorce pasujących do nazwy pliku. Kolumna „Nazwa pliku” we wzorcach musi pasować do Twoich plików (np. „NAZWISKO IMIĘ*PIT-11*rok RRRR.pdf” lub „Informacja roczna dla NAZWISKO IMIĘ.pdf”). Sprawdzana nazwa: %s', 'securedownloader' ),
                $filename
            );
        } else {
            $from_rules = $this->extract_pesel_by_document_rules( $text, $file_path );
            if ( $from_rules === null ) {
                $reasons[] = __( 'Wzorce z zakładki Wzorce nie znalazły PESEL w treści PDF. Możliwe przyczyny: nagłówek (np. „PIT-11” lub „Informacja”) nie występuje w pierwszych znakach dokumentu; sekcja (np. „C. DANE IDENTYFIKACYJNE” lub „Dane osoby ubezpieczonej”) nie została znaleziona w PDF; w wyznaczonym fragmencie nie ma numeru 11-cyfrowego lub suma kontrolna PESEL jest błędna. Porównaj wzorce z rzeczywistą treścią PDF na serwerze.', 'securedownloader' );
            }
        }

        $found_11 = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
            if ( preg_match_all( '/\b(\d{11})\b/', $line, $m ) ) {
                foreach ( $m[1] as $p ) {
                    $found_11[ $p ] = true;
                }
            }
        }
        $unique = array_keys( $found_11 );
        if ( empty( $unique ) ) {
            $reasons[] = __( 'W wyciągniętym tekście PDF nie znaleziono żadnego numeru 11-cyfrowego (PESEL). Dokument może być zeskanowany jako obraz – pdftotext nie odczyta tekstu z obrazów.', 'securedownloader' );
            return $reasons;
        }

        $valid = array_values( array_filter( $unique, function ( $p ) {
            return function_exists( 'pit_validate_pesel_checksum' ) && pit_validate_pesel_checksum( $p );
        } ) );
        if ( empty( $valid ) ) {
            $reasons[] = sprintf(
                /* translators: %d: count of 11-digit numbers found */
                __( 'Znaleziono %d numer(ów) 11-cyfrowych, ale żaden nie przeszedł walidacji sumy kontrolnej PESEL (funkcja pit_validate_pesel_checksum).', 'securedownloader' ),
                count( $unique )
            );
            return $reasons;
        }
        if ( count( $valid ) > 1 ) {
            $reasons[] = __( 'W dokumencie znaleziono więcej niż jeden różny prawidłowy PESEL – wtyczka nie przypisuje automatycznie, aby uniknąć pomyłki. Ustaw PESEL ręcznie (link „Nie dopasowano”) lub doprecyzuj wzorce w zakładce Wzorce.', 'securedownloader' );
        }

        return $reasons;
    }

    /**
     * Zwraca linie logu debugowego dotyczące rozpoznawania PESEL (osoby z pustym PESEL i przyczyny).
     *
     * @param PIT_Database $db Instancja bazy.
     * @return string[] Linie do dopisania do debug_log.
     */
    private function get_pesel_debug_log_lines( PIT_Database $db ): array {
        $persons = $db->get_person_ids_with_empty_pesel();
        if ( empty( $persons ) ) {
            return [];
        }
        $lines   = [];
        $lines[] = '';
        $lines[] = '--- ' . __( 'Rozpoznawanie PESEL (osoby bez PESEL)', 'securedownloader' ) . ' ---';
        foreach ( $persons as $full_name ) {
            $reasons = $this->diagnose_pesel_failure( $full_name, $db );
            $lines[] = __( 'Osoba:', 'securedownloader' ) . ' ' . $full_name;
            if ( empty( $reasons ) ) {
                $lines[] = '  → ' . __( 'Brak szczegółowych przyczyn (PESEL mógł zostać uzupełniony z bazy).', 'securedownloader' );
            } else {
                foreach ( $reasons as $r ) {
                    $lines[] = '  → ' . $r;
                }
            }
        }
        return $lines;
    }

    /**
     * Zbiera diagnostykę PESEL dla wszystkich osób z pustym PESEL i zapisuje w transiencie (tylko w trybie deweloperskim).
     */
    private function collect_pesel_diagnostics_after_upload( PIT_Database $db ): void {
        if ( (int) get_option( 'pit_developer_mode', 0 ) !== 1 ) {
            return;
        }
        $persons = $db->get_person_ids_with_empty_pesel();
        if ( empty( $persons ) ) {
            return;
        }
        $out = [];
        foreach ( $persons as $full_name ) {
            $reasons = $this->diagnose_pesel_failure( $full_name, $db );
            if ( ! empty( $reasons ) ) {
                $out[] = [ 'full_name' => $full_name, 'reasons' => $reasons ];
            }
        }
        if ( ! empty( $out ) ) {
            set_transient( 'pit_upload_pesel_diagnostics_' . get_current_user_id(), $out, 120 );
        }
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
     * Wyciąga rok (RRRR) z tekstu PDF według reguł RRRR z zakładki Wzorce (np. sekcja "w roku", pole "4. Rok").
     *
     * @param string $text      Tekst wyciągnięty z PDF.
     * @param string $file_path Ścieżka do pliku – do dopasowania wzorca „Nazwa pliku”.
     * @return int|null Rok (np. 2025) lub null gdy nie znaleziono.
     */
    private function extract_year_from_pdf_by_rules( string $text, string $file_path = '' ): ?int {
        $filename = $file_path !== '' ? basename( $file_path ) : '';
        $rules    = $this->get_pesel_search_rules_option( $filename );
        $current_year = (int) date( 'Y' );
        foreach ( $rules as $rule ) {
            if ( ( $rule['szukany_numer'] ?? '' ) !== 'RRRR' ) {
                continue;
            }
            $header  = $rule['nazwa_naglowka'] ?? '';
            $section = $rule['nazwa_sekcji'] ?? '';
            $field   = $rule['nr_pola'] ?? '';
            if ( $header !== '' ) {
                $head = substr( $text, 0, 1500 );
                $header_esc = preg_quote( $header, '/' );
                if ( ! preg_match( '/' . $header_esc . '/iu', $head ) ) {
                    continue;
                }
            }
            $pos = 0;
            if ( $section !== '' ) {
                $section_esc = preg_quote( $section, '/' );
                if ( preg_match( '/' . $section_esc . '\b/ui', $text, $m, PREG_OFFSET_CAPTURE ) ) {
                    $pos = $m[0][1];
                } else {
                    continue;
                }
            }
            $chunk = substr( $text, $pos, 800 );
            if ( $field !== '' ) {
                $field_esc = preg_quote( $field, '/' );
                if ( ! preg_match( '/' . $field_esc . '\b/ui', $chunk ) ) {
                    continue;
                }
            }
            if ( preg_match( '/\b(19\d{2}|20\d{2})\b/', $chunk, $ym ) ) {
                $y = (int) $ym[1];
                if ( $y >= 2000 && $y <= $current_year + 1 ) {
                    return $y;
                }
                if ( $y >= 1990 && $y <= 1999 ) {
                    return $y;
                }
            }
        }
        return null;
    }

    /**
     * Wyciąga tekst z pliku PDF (najpierw biblioteka PHP Smalot\PdfParser, potem pdftotext).
     * Kolejność: PHP nie wymaga shell_exec, więc działa na hostingu bez pdftotext lub z wyłączonym shell_exec.
     *
     * @param string $file_path Ścieżka do PDF.
     * @return string Tekst lub pusty.
     */
    private function extract_text_from_pdf( string $file_path ): string {
        if ( ! file_exists( $file_path ) ) {
            return '';
        }
        if ( function_exists( 'pit_raise_memory_for_pdf' ) ) {
            pit_raise_memory_for_pdf();
        }

        if ( class_exists( 'Smalot\PdfParser\Parser' ) ) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf    = $parser->parseFile( $file_path );
                $text   = $pdf->getText();
                if ( is_string( $text ) && trim( $text ) !== '' ) {
                    return $text;
                }
            } catch ( \Exception $e ) {
                // Fallback do pdftotext poniżej.
            }
        }

        $path = escapeshellarg( $file_path );
        $cmd  = "pdftotext -layout {$path} - 2>/dev/null";
        if ( function_exists( 'shell_exec' ) ) {
            $out = @shell_exec( $cmd );
            if ( is_string( $out ) && trim( $out ) !== '' ) {
                return $out;
            }
        }
        return '';
    }

    /**
     * Obsługuje usuwanie pliku z frontu.
     */
    public function handle_delete(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        $file_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pit_delete_' . $file_id ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $db = PIT_Database::get_instance();
        $db->delete_file( $file_id );

        $redirect = add_query_arg( [ 'pit_deleted' => '1', 'pit_tab' => 'lista' ], wp_get_referer() ?: home_url() );
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Obsługuje masowe usuwanie plików.
     */
    public function handle_bulk_delete(): void {
        pit_debug_log( 'handle_bulk_delete: start' );
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_bulk_delete_nonce'] ?? '', 'pit_bulk_delete_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
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
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Skanuje katalog uploadów, dodaje brakujące pliki PDF do bazy i uzupełnia PESEL (jak przy wgrywaniu).
     */
    public function handle_scan_uploaded_files(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_scan_nonce'] ?? '', 'pit_scan_uploaded_files' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        set_time_limit( 90 );
        if ( function_exists( 'pit_raise_memory_for_pdf' ) ) {
            pit_raise_memory_for_pdf();
        }
        $db     = PIT_Database::get_instance();
        $result = $db->sync_files();

        if ( $result['added'] > 0 ) {
            $this->fill_missing_pesel_after_upload( $db );
            $this->collect_pesel_diagnostics_after_upload( $db );
        }

        $redirect = add_query_arg( [
            'pit_scan_added'   => $result['added'],
            'pit_scan_removed' => $result['removed'],
            'pit_nocache'      => time(),
        ], wp_get_referer() ?: home_url() );
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Usuwa wszystkie dokumenty z bazy i z dysku serwera.
     */
    public function handle_delete_all_files(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_delete_all_nonce'] ?? '', 'pit_delete_all_files' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $db    = PIT_Database::get_instance();
        $files = $db->get_all_files_sorted();
        $count = 0;

        foreach ( $files as $f ) {
            $id = (int) ( $f->id ?? 0 );
            if ( $id > 0 && $db->delete_file( $id ) ) {
                $count++;
            }
        }

        $redirect = add_query_arg( 'pit_all_deleted', $count, wp_get_referer() ?: home_url() );
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Usuwa z serwera wszystkie pliki, które mają co najmniej jedno pobranie.
     */
    public function handle_delete_downloaded_files(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_delete_downloaded_nonce'] ?? '', 'pit_delete_downloaded_files' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
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
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Obsługuje ręczne ustawienie PESEL z panelu menadżera (link „Nie dopasowano”).
     */
    public function handle_set_pesel_front(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_set_pesel_front_nonce'] ?? '', 'pit_set_pesel_front' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $full_name = sanitize_text_field( $_POST['pit_set_pesel_full_name'] ?? '' );
        $pesel     = sanitize_text_field( $_POST['pit_set_pesel_value'] ?? '' );
        $pesel     = preg_replace( '/\D/', '', $pesel );

        if ( $full_name === '' || strlen( $pesel ) !== 11 ) {
            $redirect = add_query_arg( [ 'pit_set_pesel_error' => '1', 'pit_tab' => 'lista' ], wp_get_referer() ?: home_url() );
            wp_safe_redirect( esc_url_raw( $redirect ) );
            exit;
        }

        $db = PIT_Database::get_instance();
        $db->update_pesel_for_person( $full_name, $pesel );

        $redirect = add_query_arg( [ 'pit_set_pesel_ok' => '1', 'pit_tab' => 'lista' ], wp_get_referer() ?: home_url() );
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Zapisuje dane firmy z zakładki Dane firmy.
     */
    public function handle_save_company_data(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_company_data_nonce'] ?? '', 'pit_save_company_data' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $company_name    = sanitize_text_field( $_POST['pit_company_name'] ?? '' );
        $company_address = sanitize_text_field( $_POST['pit_company_address'] ?? '' );
        $nip_raw         = is_string( $_POST['pit_company_nip'] ?? '' ) ? $_POST['pit_company_nip'] : '';
        $company_nip     = substr( preg_replace( '/[^0-9]/', '', $nip_raw ), 0, 10 );

        update_option( 'pit_company_name', $company_name );
        update_option( 'pit_company_address', $company_address );
        update_option( 'pit_company_nip', $company_nip );

        $redirect = add_query_arg( 'pit_company_saved', '1', wp_get_referer() ?: home_url() );
        $tab = isset( $_POST['pit_redirect_tab'] ) ? sanitize_key( (string) $_POST['pit_redirect_tab'] ) : '';
        if ( $tab !== '' && in_array( $tab, [ 'lista', 'upload', 'wzorce', 'dane-firmy' ], true ) ) {
            $redirect = add_query_arg( 'pit_tab', $tab, $redirect );
        }
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /**
     * Zapisuje wzorce importu (zakładka Wzorce).
     */
    public function handle_save_import_patterns(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_import_patterns_nonce'] ?? '', 'pit_save_import_patterns' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
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
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_reset_patterns_nonce'] ?? '', 'pit_reset_import_patterns' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
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
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_report_nonce'] ?? '', 'pit_report_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
        }

        $year = (int) ( $_POST['year'] ?? date( 'Y' ) );
        $this->generate_report( $year );
    }

    /**
     * Generuje i wysyła raport PDF.
     */
    public function handle_generate_report_pdf(): void {
        if ( ! $this->check_access() ) {
            wp_die( __( 'Brak uprawnień.', 'securedownloader' ) );
        }

        if ( ! wp_verify_nonce( $_POST['pit_report_pdf_nonce'] ?? '', 'pit_report_pdf_nonce' ) ) {
            wp_die( __( 'Błąd bezpieczeństwa.', 'securedownloader' ) );
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
    <title><?php echo esc_html__( 'Raport dokumentów', 'securedownloader' ); ?> – <?php echo esc_html( $year ); ?></title>
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
        .report-actions { margin-bottom: 20px; }
        .report-actions button { padding: 10px 20px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="report-actions">
        <button type="button" onclick="window.history.back()"><?php esc_html_e( 'Powrót', 'securedownloader' ); ?></button>
    </div>
    <header>
        <h1><?php echo esc_html( $company_name ); ?></h1>
        <?php if ( $company_address ) : ?>
            <p><?php echo esc_html( $company_address ); ?></p>
        <?php endif; ?>
        <?php if ( $company_nip ) : ?>
            <p>NIP: <?php echo esc_html( $company_nip ); ?></p>
        <?php endif; ?>
        <h2><?php echo esc_html__( 'Raport pobrania dokumentów za rok', 'securedownloader' ); ?> <?php echo esc_html( $year ); ?></h2>
        <p>Wygenerowano: <?php echo esc_html( $generated_at ); ?></p>
    </header>

    <table>
        <thead>
            <tr>
                <th><?php esc_html_e( 'Nazwisko i imię', 'securedownloader' ); ?></th>
                <th>PESEL</th>
                <th><?php esc_html_e( 'Data pobrania', 'securedownloader' ); ?></th>
                <th><?php esc_html_e( 'Status', 'securedownloader' ); ?></th>
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
                        <?php echo $is_downloaded ? esc_html__( 'Pobrano', 'securedownloader' ) : esc_html__( 'Nie pobrano', 'securedownloader' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer>
        <p><?php echo esc_html__( 'Dokument wygenerowany przez Secure Downloader', 'securedownloader' ); ?></p>
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
    <title><?php echo esc_attr__( 'Raport dokumentów', 'securedownloader' ); ?></title>
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
    <div class="no-print print-btn" style="display: flex; gap: 12px; margin-bottom: 20px;">
        <button type="button" onclick="window.history.back()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
            <?php esc_html_e( 'Powrót', 'securedownloader' ); ?>
        </button>
        <button type="button" onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
            <?php esc_html_e( 'Drukuj / Zapisz jako PDF', 'securedownloader' ); ?>
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
        <h2><?php echo esc_html__( 'Raport pobrania dokumentów', 'securedownloader' ); ?><?php echo $year > 0 ? ' ' . esc_html__( 'za rok', 'securedownloader' ) . ' ' . esc_html( $year ) : ''; ?></h2>
        <p>Wygenerowano: <?php echo esc_html( $generated_at ); ?></p>
    </header>

    <table>
        <thead>
            <tr>
                <th><?php esc_html_e( 'Nazwisko i imię', 'securedownloader' ); ?></th>
                <th>PESEL</th>
                <?php if ( $year === 0 ) : ?>
                <th><?php esc_html_e( 'Rok', 'securedownloader' ); ?></th>
                <?php endif; ?>
                <th><?php esc_html_e( 'Data pobrania', 'securedownloader' ); ?></th>
                <th><?php esc_html_e( 'Status', 'securedownloader' ); ?></th>
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
                        <?php echo $is_downloaded ? esc_html__( 'Pobrano', 'securedownloader' ) : esc_html__( 'Nie pobrano', 'securedownloader' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer>
        <p><?php echo esc_html__( 'Dokument wygenerowany przez Secure Downloader', 'securedownloader' ); ?></p>
    </footer>
</body>
</html>
        <?php

        $html = ob_get_clean();

        echo $html;
        exit;
    }
}
