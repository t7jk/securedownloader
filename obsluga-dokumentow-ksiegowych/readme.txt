=== Obsługa dokumentów księgowych ===
Contributors: (twoje imię)
Tags: pit, pit-11, księgowość, podatki, pdf, dokumenty
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wtyczka umożliwia księgowym wgrywanie dokumentów księgowych, a podatnikom ich pobieranie po weryfikacji danych osobowych.

== Opis ==

PIT-11 Manager to wtyczka WordPress przeznaczona dla biur księgowych i firm, które chcą udostępnić swoim klientom możliwość pobierania formularzy PIT-11 przez stronę internetową.

= Funkcje =

* **Trzy poziomy uprawnień:** Administrator, Księgowy, Podatnik
* **Wgrywanie plików PDF:** Księgowi mogą łatwo przesyłać dokumenty PIT-11
* **Weryfikacja tożsamości:** Podatnicy pobierają dokumenty po podaniu PESEL, imienia i nazwiska
* **Bezpieczeństwo:** Ochrona przed nieautoryzowanym dostępem
* **Panel administratora:** Zarządzanie wszystkimi dokumentami z jednego miejsca

== Instalacja ==

1. Prześlij folder `obsluga-dokumentow-ksiegowych` do katalogu `/wp-content/plugins/`
2. Aktywuj wtyczkę w menu "Wtyczki" w WordPress
3. Skonfiguruj uprawnienia użytkowników (Administrator, Księgowy, Podatnik)
4. Dodaj shortcode `[pit_download]` na wybranej stronie

== Użycie ==

= Shortcode =

Umieść shortcode `[pit_download]` na dowolnej stronie, aby wyświetlić formularz pobierania dla podatników.

= Role i uprawnienia =

* **Administrator:** Pełny dostęp do wszystkich funkcji
* **Księgowy:** Może wgrywać dokumenty PIT-11
* **Podatnik:** Może pobierać swoje dokumenty po weryfikacji

== Frequently Asked Questions ==

= Czy dokumenty są bezpieczne? =

Tak, dokumenty są chronione weryfikacją danych osobowych (PESEL, imię, nazwisko). Tylko osoby znające te dane mogą pobrać plik.

= Jakie formaty plików są obsługiwane? =

Obecnie obsługiwane są tylko pliki PDF.

== Changelog ==

= 1.0.0 =
* Pierwsza wersja wtyczki
* Podstawowa funkcjonalność wgrywania i pobierania PIT-11
* System ról: Administrator, Księgowy, Podatnik
* Shortcode [pit_download] dla formularza podatnika

== Upgrade Notice ==

= 1.0.0 =
Pierwsza wersja - brak wcześniejszych wersji do aktualizacji.

== Informacje dodatkowe ==

Aby zgłosić błąd lub sugestię, skontaktuj się z autorem wtyczki.
