<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Klasa obsługi bazy danych wtyczki PIT-11 Manager.
 * Odpowiada za tworzenie tabel oraz zapytania do bazy.
 */
class PIT_Database {

	/** @var PIT_Database|null Instancja singletona */
	private static ?PIT_Database $instance = null;

	/** @var string Nazwa tabeli plików PIT */
	public static string $table_files;

	/** @var string Nazwa tabeli pobrań */
	public static string $table_downloads;

	/**
	 * Konstruktor – ustawia nazwy tabel.
	 */
	private function __construct() {
		global $wpdb;
		self::$table_files      = $wpdb->prefix . 'pit_files';
		self::$table_downloads  = $wpdb->prefix . 'pit_downloads';
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
	 * Tworzy tabele w bazie danych. Wywoływane przy aktywacji.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_files    = self::$table_files;
		$table_downloads = self::$table_downloads;

		self::maybe_migrate_to_full_name();
		self::maybe_migrate_pesel_nullable();

		$sql_files = "CREATE TABLE IF NOT EXISTS {$table_files} (
			id            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			full_name     VARCHAR(200)     NOT NULL,
			pesel         VARCHAR(11)      NULL DEFAULT '',
			tax_year      YEAR             NOT NULL,
			file_path     VARCHAR(500)     NOT NULL,
			file_url      VARCHAR(500)     NOT NULL,
			uploaded_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			INDEX idx_pesel_year (pesel, tax_year),
			INDEX idx_tax_year (tax_year)
		) {$charset_collate};";

		$sql_downloads = "CREATE TABLE IF NOT EXISTS {$table_downloads} (
			id            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			file_id       INT(11) UNSIGNED NOT NULL,
			downloaded_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address    VARCHAR(45)      NOT NULL,
			PRIMARY KEY   (id),
			INDEX idx_file_id (file_id),
			FOREIGN KEY   (file_id) REFERENCES {$table_files}(id) ON DELETE CASCADE
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_files );
		dbDelta( $sql_downloads );

		update_option( 'pit_db_version', PIT_VERSION );
	}

	/**
	 * Migruje dane z dwóch kolumn (first_name, last_name) do jednej (full_name).
	 */
	private static function maybe_migrate_to_full_name(): void {
		global $wpdb;

		$table = self::$table_files;

		$column = $wpdb->get_row(
			"SHOW COLUMNS FROM {$table} LIKE 'first_name'"
		);

		if ( $column ) {
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN full_name VARCHAR(200) NOT NULL DEFAULT '' AFTER id"
			);

			$wpdb->query(
				"UPDATE {$table} SET full_name = CONCAT(last_name, ' ', first_name)"
			);

			$wpdb->query(
				"ALTER TABLE {$table} DROP COLUMN first_name"
			);

			$wpdb->query(
				"ALTER TABLE {$table} DROP COLUMN last_name"
			);
		}
	}

	/**
	 * Migruje kolumnę pesel na NULL (dla formatów bez PESEL w nazwie pliku).
	 */
	private static function maybe_migrate_pesel_nullable(): void {
		global $wpdb;

		$table = self::$table_files;

		$column = $wpdb->get_row(
			"SHOW COLUMNS FROM {$table} LIKE 'pesel'"
		);

		if ( $column && strtoupper( (string) $column->Null ) === 'NO' ) {
			$wpdb->query(
				"ALTER TABLE {$table} MODIFY COLUMN pesel VARCHAR(11) NULL DEFAULT ''"
			);
		}
	}

	/**
	 * Zwraca PESEL pierwszej osoby o danym full_name (gdy wypełniony). Dopasowanie po kluczu osoby (np. „Ambrozik Ewelina 29” = „Ambrozik Ewelina”).
	 *
	 * @param string $full_name Imię i nazwisko (dowolna forma).
	 * @return string|null      PESEL lub null.
	 */
	public function get_pesel_by_full_name( string $full_name ): ?string {
		global $wpdb;

		$key = pit_person_match_key( $full_name );
		if ( $key === '' ) {
			return null;
		}

		$rows = $wpdb->get_results(
			"SELECT full_name, pesel FROM " . self::$table_files . " WHERE pesel IS NOT NULL AND pesel != ''",
			OBJECT
		);
		foreach ( $rows as $row ) {
			if ( pit_person_match_key( (string) $row->full_name ) === $key ) {
				return is_string( $row->pesel ) && $row->pesel !== '' ? $row->pesel : null;
			}
		}
		return null;
	}

	/**
	 * Ustawia PESEL dla wszystkich rekordów danej osoby (dopasowanie po znormalizowanym full_name).
	 *
	 * @param string $full_name Imię i nazwisko (dowolna forma, zostanie znormalizowana).
	 * @param string $pesel     PESEL (11 cyfr).
	 * @return int              Liczba zaktualizowanych wierszy.
	 */
	public function update_pesel_for_person( string $full_name, string $pesel ): int {
		global $wpdb;

		$pesel = preg_replace( '/\D/', '', $pesel );
		$pesel = substr( $pesel, 0, 11 );

		if ( strlen( $pesel ) !== 11 ) {
			return 0;
		}

		$key = pit_person_match_key( $full_name );
		if ( $key === '' ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			"SELECT id, full_name FROM " . self::$table_files,
			OBJECT
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			if ( pit_person_match_key( (string) $row->full_name ) !== $key ) {
				continue;
			}
			$n = $wpdb->update(
				self::$table_files,
				[ 'pesel' => $pesel ],
				[ 'id' => (int) $row->id ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( $n !== false ) {
				$updated += (int) $n;
			}
		}

		return $updated;
	}

	/**
	 * Zwraca listę distinct full_name mających co najmniej jeden rekord z pustym PESEL.
	 *
	 * @return array<string> Tablica full_name.
	 */
	public function get_person_ids_with_empty_pesel(): array {
		global $wpdb;

		$names = $wpdb->get_col(
			"SELECT DISTINCT full_name FROM " . self::$table_files . " WHERE (pesel IS NULL OR pesel = '') ORDER BY full_name ASC"
		);

		return is_array( $names ) ? array_map( 'strval', $names ) : [];
	}

	/**
	 * Wywoływana podczas aktywacji wtyczki.
	 */
	public static function activate(): void {
		self::get_instance();
		self::create_tables();
	}

	/**
	 * Wywoływana podczas deaktywacji wtyczki.
	 */
	public static function deactivate(): void {
	}

	/**
	 * Dodaje rekord pliku do bazy.
	 *
	 * @param array $data Dane pliku: full_name, pesel, tax_year, file_path, file_url
	 * @return int|false  ID wstawionego rekordu lub false.
	 */
	public function insert_file( array $data ): int|false {
		global $wpdb;

		$full_name = pit_normalize_full_name( sanitize_text_field( $data['full_name'] ?? '' ) );
		$pesel_val = sanitize_text_field( $data['pesel'] ?? '' );

		$result = $wpdb->insert(
			self::$table_files,
			[
				'full_name'   => $full_name,
				'pesel'       => $pesel_val,
				'tax_year'    => (int) ( $data['tax_year'] ?? 0 ),
				'file_path'   => $data['file_path'] ?? '',
				'file_url'    => $data['file_url'] ?? '',
				'uploaded_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Pobiera plik po PESEL i roku podatkowym.
	 *
	 * @param string $pesel    Numer PESEL.
	 * @param int    $tax_year Rok podatkowy.
	 * @return object|null     Obiekt pliku lub null.
	 */
	public function get_file_by_pesel( string $pesel, int $tax_year ): ?object {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.*, d.downloaded_at, d.ip_address 
				 FROM %i f 
				 LEFT JOIN %i d ON f.id = d.file_id 
				 WHERE f.pesel = %s AND f.tax_year = %d 
				 ORDER BY d.downloaded_at DESC 
				 LIMIT 1",
				self::$table_files,
				self::$table_downloads,
				sanitize_text_field( $pesel ),
				$tax_year
			)
		);

		return $result;
	}

	/**
	 * Pobiera wszystkie pliki dla danego PESEL i roku (do pobrania wielu dokumentów lub ZIP).
	 *
	 * @param string $pesel    Numer PESEL.
	 * @param int    $tax_year Rok podatkowy.
	 * @return array Lista obiektów plików (posortowane wg id).
	 */
	public function get_files_by_pesel( string $pesel, int $tax_year ): array {
		global $wpdb;

		$pesel_digits = preg_replace( '/\D/', '', $pesel );
		$pesel_11     = str_pad( $pesel_digits, 11, '0', STR_PAD_LEFT );
		if ( strlen( $pesel_11 ) > 11 ) {
			$pesel_11 = substr( $pesel_11, -11 );
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.* FROM %i f 
				 WHERE LPAD(TRIM(COALESCE(f.pesel, '')), 11, '0') = %s AND f.tax_year = %d 
				 ORDER BY f.id ASC",
				self::$table_files,
				$pesel_11,
				$tax_year
			)
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Pobiera wszystkie pliki dla danego roku.
	 *
	 * @param int $tax_year Rok podatkowy.
	 * @return array        Tablica obiektów plików.
	 */
	public function get_all_files( int $tax_year ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, 
						MAX(d.downloaded_at) as last_download,
						COUNT(d.id) as download_count
				 FROM %i f 
				 LEFT JOIN %i d ON f.id = d.file_id 
				 WHERE f.tax_year = %d 
				 GROUP BY f.id 
				 ORDER BY f.full_name ASC",
				self::$table_files,
				self::$table_downloads,
				$tax_year
			)
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Pobiera wszystkie pliki posortowane.
	 *
	 * @return array Tablica obiektów plików.
	 */
	public function get_all_files_sorted(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, 
						MAX(d.downloaded_at) as last_download,
						COUNT(d.id) as download_count
				 FROM %i f 
				 LEFT JOIN %i d ON f.id = d.file_id 
				 GROUP BY f.id 
				 ORDER BY f.full_name ASC",
				self::$table_files,
				self::$table_downloads
			)
		);
	}

	/**
	 * Zapisuje informację o pobraniu.
	 *
	 * @param int $file_id ID pliku.
	 * @return bool        True przy sukcesie.
	 */
	public function mark_downloaded( int $file_id ): bool {
		global $wpdb;

		$result = $wpdb->insert(
			self::$table_downloads,
			[
				'file_id'       => $file_id,
				'downloaded_at' => current_time( 'mysql' ),
				'ip_address'    => $this->get_client_ip(),
			],
			[ '%d', '%s', '%s' ]
		);

		return (bool) $result;
	}

	/**
	 * Usuwa plik z bazy i z serwera.
	 *
	 * @param int $file_id ID pliku.
	 * @return bool        True przy sukcesie.
	 */
	public function delete_file( int $file_id ): bool {
		global $wpdb;

		$file = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d",
				self::$table_files,
				$file_id
			)
		);

		if ( ! $file ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . self::$table_downloads . '` WHERE file_id = %d',
				$file_id
			)
		);

		if ( file_exists( $file->file_path ) ) {
			unlink( $file->file_path );
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . self::$table_files . '` WHERE id = %d',
				$file_id
			)
		);

		return $deleted !== false && (int) $deleted > 0;
	}

	/**
	 * Zwraca ID plików, które mają co najmniej jedno pobranie (wpis w pit_downloads).
	 *
	 * @return array<int> Tablica ID plików.
	 */
	public function get_downloaded_file_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT DISTINCT file_id FROM " . self::$table_downloads
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Pobiera listę lat z dostępnymi plikami.
	 *
	 * @return array Tablica lat (int).
	 */
	public function get_available_years(): array {
		global $wpdb;

		$years = $wpdb->get_col(
			"SELECT DISTINCT tax_year FROM " . self::$table_files . " ORDER BY tax_year DESC"
		);

		return array_map( 'intval', $years );
	}

	/**
	 * Pobiera wszystkie dane do raportu.
	 *
	 * @param int $tax_year Rok podatkowy (0 = wszystkie).
	 * @return array        Tablica danych do raportu.
	 */
	public function get_report_data( int $tax_year = 0 ): array {
		global $wpdb;

		if ( $tax_year > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.*, d.downloaded_at, d.ip_address
					 FROM %i f
					 LEFT JOIN %i d ON f.id = d.file_id
					 WHERE f.tax_year = %d
					 ORDER BY f.full_name ASC",
					self::$table_files,
					self::$table_downloads,
					$tax_year
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, d.downloaded_at, d.ip_address
				 FROM %i f
				 LEFT JOIN %i d ON f.id = d.file_id
				 ORDER BY f.full_name ASC, f.tax_year DESC",
				self::$table_files,
				self::$table_downloads
			)
		);
	}

	/**
	 * Pobiera plik po ID.
	 *
	 * @param int $file_id ID pliku.
	 * @return object|null Obiekt pliku lub null.
	 */
	public function get_file_by_id( int $file_id ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d",
				self::$table_files,
				$file_id
			)
		);
	}

	/**
	 * Synchronizuje bazę danych z plikami na dysku.
	 * Dodaje brakujące pliki, usuwa rekordy bez plików.
	 */
	public function sync_files(): array {
		$added   = 0;
		$removed = 0;

		$db_files = $this->get_all_files_with_paths();
		$db_paths = [];
		foreach ( $db_files as $file ) {
			$db_paths[ $file->id ] = $file->file_path;
		}

		foreach ( $db_paths as $id => $path ) {
			if ( ! file_exists( $path ) ) {
				$this->delete_file( $id );
				$removed++;
			}
		}

		$upload_dir   = wp_upload_dir();
		$pit_dir      = $upload_dir['basedir'] . '/obsluga-dokumentow-ksiegowych/';
		$pit_url_base = $upload_dir['baseurl'] . '/obsluga-dokumentow-ksiegowych/';

		if ( is_dir( $pit_dir ) ) {
			$years = scandir( $pit_dir );
			foreach ( $years as $year ) {
				if ( $year === '.' || $year === '..' ) {
					continue;
				}
				$year_dir = $pit_dir . $year;
				if ( ! is_dir( $year_dir ) ) {
					continue;
				}

				$files = scandir( $year_dir );
				foreach ( $files as $filename ) {
					if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
						continue;
					}

					$file_path = $year_dir . '/' . $filename;
					if ( in_array( $file_path, $db_paths, true ) ) {
						continue;
					}

					$parsed = $this->parse_filename( $filename );
					if ( ! $parsed ) {
						continue;
					}

					$file_url = $pit_url_base . $year . '/' . $filename;

					$result = $this->insert_file( [
						'full_name' => $parsed['full_name'],
						'pesel'     => $parsed['pesel'],
						'tax_year'  => $parsed['tax_year'],
						'file_path' => $file_path,
						'file_url'  => $file_url,
					] );

					if ( $result ) {
						$added++;
					}
				}
			}
		}

		return [
			'added'   => $added,
			'removed' => $removed,
		];
	}

	/**
	 * Parsuje nazwę pliku wg formatu: PIT-11_rok_2021_Dobosz_Marzena_89120508744.pdf
	 *
	 * @param string $filename Nazwa pliku.
	 * @return array|false     Tablica z danymi lub false przy błędzie.
	 */
	private function parse_filename( string $filename ): array|false {
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
	 * Pobiera wszystkie pliki z ścieżkami.
	 *
	 * @return array Tablica obiektów plików.
	 */
	private function get_all_files_with_paths(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, file_path FROM %i",
				self::$table_files
			)
		);
	}

	/**
	 * Pobiera adres IP klienta.
	 *
	 * @return string Adres IP.
	 */
	private function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( $_SERVER['HTTP_CLIENT_IP'] );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
	}

	/**
	 * Usuwa wszystkie tabele przy odinstalowaniu.
	 */
	public static function uninstall(): void {
		global $wpdb;

		self::get_instance();

		$wpdb->query( "DROP TABLE IF EXISTS " . self::$table_downloads );
		$wpdb->query( "DROP TABLE IF EXISTS " . self::$table_files );

		delete_option( 'pit_db_version' );
		delete_option( 'pit_accountant_users' );
		delete_option( 'pit_accountant_page_url' );
		delete_option( 'pit_client_page_url' );
		delete_option( 'pit_company_name' );
		delete_option( 'pit_company_address' );
		delete_option( 'pit_company_nip' );
	}
}
