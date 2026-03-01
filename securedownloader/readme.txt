=== Secure Downloader ===
Contributors: (twoje imię)
Tags: pit, pit-11, zarządzanie, podatki, pdf, dokumenty
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wtyczka umożliwia menadżerom wgrywanie dokumentów, a klientom ich pobieranie po weryfikacji danych osobowych.

== Opis ==

PIT-11 Manager to wtyczka WordPress przeznaczona dla zespołów i firm, które chcą udostępnić swoim klientom możliwość pobierania formularzy PIT-11 przez stronę internetową.

= Funkcje =

* **Trzy poziomy uprawnień:** Administrator, Menadżer, Klient
* **Wgrywanie plików PDF:** Menadżerzy mogą łatwo przesyłać dokumenty PIT-11
* **Weryfikacja tożsamości:** Podatnicy pobierają dokumenty po podaniu PESEL, imienia i nazwiska
* **Bezpieczeństwo:** Ochrona przed nieautoryzowanym dostępem
* **Panel administratora:** Zarządzanie wszystkimi dokumentami z jednego miejsca

== Instalacja ==

1. Prześlij folder `securedownloader` do katalogu `/wp-content/plugins/`
2. Aktywuj wtyczkę w menu "Wtyczki" w WordPress
3. Skonfiguruj uprawnienia użytkowników (Administrator, Menadżer, Klient)
4. W ustawieniach wtyczki utwórz strony z shortcode’ami `[pit_client_page]` (klient) i `[pit_accountant_panel]` (menadżer)

== Użycie ==

= Shortcode =

* **`[pit_client_page]`** – umieść na stronie dla klientów; wyświetla formularz pobierania dokumentów (PESEL, imię, nazwisko).
* **`[pit_accountant_panel]`** – umieść na stronie dla menadżerów; wyświetla panel wgrywania i zarządzania dokumentami.

= Role i uprawnienia =

* **Administrator:** Pełny dostęp do wszystkich funkcji
* **Menadżer:** Może wgrywać dokumenty PIT-11
* **Klient:** Może pobierać swoje dokumenty po weryfikacji

== Frequently Asked Questions ==

= Czy dokumenty są bezpieczne? =

Tak, dokumenty są chronione weryfikacją danych osobowych (PESEL, imię, nazwisko). Tylko osoby znające te dane mogą pobrać plik.

= Jakie formaty plików są obsługiwane? =

Obecnie obsługiwane są tylko pliki PDF.

== Changelog ==

= 1.2.0 =
* Wersja 1.2.0

= 1.0.0 =
* Pierwsza wersja wtyczki
* Podstawowa funkcjonalność wgrywania i pobierania PIT-11
* System ról: Administrator, Menadżer, Klient
* Shortcode [pit_download] dla formularza klienta

== Upgrade Notice ==

= 1.2.0 =
Aktualizacja do wersji 1.2.0.

= 1.0.0 =
Pierwsza wersja - brak wcześniejszych wersji do aktualizacji.

== Informacje dodatkowe ==

Aby zgłosić błąd lub sugestię, skontaktuj się z autorem wtyczki.
