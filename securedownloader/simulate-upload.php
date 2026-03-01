<?php
/**
 * Symulacja wczytania plików – parsowanie nazw przy domyślnych filtrach.
 * Uruchomienie: php simulate-upload.php [ścieżka/do/wp-load.php]
 * Bez argumentu: szuka wp-load.php w ../../../../ (zakładając skrypt w plugins/securedownloader/).
 */
if ( php_sapi_name() !== 'cli' ) {
    exit( 'Skrypt tylko w trybie CLI.' );
}

$wp_load = $argv[1] ?? null;
if ( $wp_load === null ) {
    $candidates = [
        __DIR__ . '/../../../../wp-load.php',
        __DIR__ . '/../../../wordpress/wp-load.php',
        __DIR__ . '/../../wordpress/wp-load.php',
    ];
    foreach ( $candidates as $c ) {
        if ( is_file( $c ) ) {
            $wp_load = $c;
            break;
        }
    }
}
if ( $wp_load === null || ! is_file( $wp_load ) ) {
    echo "Nie znaleziono wp-load.php. Użycie: php simulate-upload.php [ścieżka/do/wp-load.php]\n";
    exit( 1 );
}
require_once $wp_load;

$filenames = [
    'Ambrozik Ewelina - PIT-11 (29) - rok 2025.pdf',
    'Borkowska Natalia - PIT-11 (29) - rok 2025.pdf',
    'Borkowski Bartosz - PIT-11 (29) - rok 2025.pdf',
    'Informacja roczna dla Ambrozik Ewelina.pdf',
    'Informacja roczna dla Borkowski Bartosz.pdf',
    'Informacja roczna dla Zalewska Natalia.pdf',
    'Kalaciński Eryk - PIT-11 (29) - rok 2025.pdf',
    'Postek Luiza - PIT-11 (29) - rok 2025.pdf',
    'Wołoszka Weronika  - PIT-11 (29) - rok 2025.pdf',
    'Zalewska Natalia - PIT-11 (29) - rok 2025.pdf',
    'Zieliński Wiktor - PIT-11 (29) - rok 2025.pdf',
];

$filters   = pit_get_default_filename_filters();
$accountant = PIT_Accountant::get_instance();

echo "Filtry domyślne: " . count( $filters ) . "\n\n";
echo str_repeat( '-', 100 ) . "\n";

$ok = 0;
$fail = 0;
foreach ( $filenames as $i => $filename ) {
    $result = $accountant->parse_filename_by_filters( $filename, $filters );
    $num    = $i + 1;
    if ( $result === false ) {
        echo sprintf( "%2d. BRAK DOPASOWANIA: %s\n", $num, $filename );
        $fail++;
        continue;
    }
    $ok++;
    $filter_used = '-';
    foreach ( $filters as $fi => $filter_str ) {
        if ( strpos( $filter_str, '{' ) !== false ) {
            $test = $accountant->parse_filename_by_filters( $filename, [ $filter_str ] );
            if ( $test !== false ) {
                $filter_used = (string) ( $fi + 1 );
                break;
            }
        }
    }
    echo sprintf(
        "%2d. full_name=%s | tax_year=%s | pesel=%s | filtr=%s\n    %s\n",
        $num,
        $result['full_name'],
        $result['tax_year'],
        $result['pesel'] !== '' ? $result['pesel'] : '(pusty)',
        $filter_used,
        $filename
    );
}

echo str_repeat( '-', 100 ) . "\n";
echo "Podsumowanie: dopasowano {$ok}/" . count( $filenames );
if ( $fail > 0 ) {
    echo ", brak dopasowania: {$fail}";
}
echo "\n";
