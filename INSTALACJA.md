# Instalacja wtyczki Obsługa dokumentów księgowych

## Błąd: „Plugin file does not exist”

Ten błąd pojawia się, gdy WordPress ma zapisaną ścieżkę do wtyczki, ale pod nią nie ma już pliku (np. po przeniesieniu lub zmianie nazwy folderu).

**Szybkie rozwiązanie:** zainstaluj wtyczkę w jednym z dwóch sposobów poniżej i **aktywuj ją ponownie** w Panelu → Wtyczki.

---

## Sposób 1: Plik ZIP (najprostszy)

1. W katalogu projektu utwórz archiwum:  
   `zip -r obsluga-dokumentow-ksiegowych.zip obsluga-dokumentow-ksiegowych -x "*.git*"`
2. W Panelu WordPress: **Wtyczki** → **Dodaj nową** → **Wgraj wtyczkę** → wybierz `obsluga-dokumentow-ksiegowych.zip` → **Zainstaluj teraz** → **Aktywuj**.

---

## Sposób 2: Tylko folder wtyczki

1. Skopiuj **cały folder** `obsluga-dokumentow-ksiegowych` (wraz z zawartością) do katalogu wtyczek WordPress:
   ```
   wp-content/plugins/obsluga-dokumentow-ksiegowych/
   ```
2. Powinna być ścieżka: `wp-content/plugins/obsluga-dokumentow-ksiegowych/obsluga-dokumentow-ksiegowych.php`
3. W Panelu WordPress: **Wtyczki** → znajdź „Obsługa dokumentów księgowych” → **Aktywuj**

---

## Sposób 3: Cały repozytorium (PIT-downloader)

1. Skopiuj **cały folder** `PIT-downloader` (lub sklonuj repozytorium) do:
   ```
   wp-content/plugins/PIT-downloader/
   ```
2. Wtyczką jest plik: `wp-content/plugins/PIT-downloader/obsluga-dokumentow-ksiegowych-loader.php`
3. W Panelu WordPress: **Wtyczki** → znajdź „Obsługa dokumentów księgowych” → **Aktywuj**

Loader ładuje właściwą wtyczkę z podkatalogu `obsluga-dokumentow-ksiegowych/`.

---

## Sprawdzenie

- **Sposób 1 i 2:** W Panelu → Narzędzia powinna być pozycja „Obsługa dokumentów księgowych” (Ustawienia).
- **Sposób 3:** To samo – loader tylko przekierowuje do kodu w `obsluga-dokumentow-ksiegowych/`.

Jeśli błąd „Plugin file does not exist” nadal się pojawia, w Panelu → Wtyczki **dezaktywuj** starą wersję (jeśli jest na liście), upewnij się, że folder i plik są we właściwym miejscu według jednego ze sposobów, i **aktywuj** wtyczkę ponownie.
