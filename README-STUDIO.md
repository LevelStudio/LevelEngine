# LevelEngine CMS 1.0.0 — Studio

LevelEngine Studio 1.0.0, wydawca LevelStudio. Kreator stron, studio wyglądu, cztery zestawy startowe i nowe narzędzia układu. Zakres weryfikacji wydania opisuje README.md.

## Pierwsze kroki

1. W panelu otwórz **LevelEngine Studio**. Możesz też wejść do Podstron i wybrać **Kreator blokowy**.
2. Dodaj bloki i wybierz blok z listy, aby zmienić jego treść. Przeciągaj bloki lub korzystaj ze strzałek. Dostępne są duplikowanie, usuwanie, cofanie i ponawianie.
3. Sprawdź podgląd na komputerze i telefonie. **Podgląd nie zapisuje strony.** Kliknij Zapisz stronę; domyślnie nowy dokument jest szkicem.
4. Po publikacji możesz dodać stronę do menu i ustawić ją jako stronę główną. Dostęp do ustawienia strony głównej wymaga także zarządzania ustawieniami.

### Bloki

Baner główny, tekst, oferta/kolumny, cennik, zespół, opinie, FAQ, galeria, aktualności, wezwanie do działania, liczby i osiągnięcia, etapy współpracy oraz partnerzy. Blok aktualności pobiera do sześciu opublikowanych wpisów; nie pokazuje szkiców ani kosza. FAQ korzysta z rozwijanych odpowiedzi. Pozostałe zestawy kart mają pola na tekst, zdjęcie, podpis i odnośnik. Maksymalnie 60 bloków na stronie i 24 elementy na sekcję.

W tym wydaniu pola tekstowe bloków są zwykłym tekstem, bez wykonywania HTML. Dotychczasowy edytor wizualny/Markdown pozostaje dostępny dla stron tekstowych. Nie ma automatycznej konwersji istniejącej strony na bloki — utwórz nową stronę, aby zachować stary układ.

Osoby z uprawnieniem do mediów mogą wybierać i wgrywać obrazy bez opuszczania kreatora. Obrazy można również wskazać adresem HTTP(S) albo lokalną ścieżką zaczynającą się od `/`. Przyciski obsługują także kotwice `#sekcja` oraz odnośniki `mailto:` i `tel:`.

### Narzędzia układu 1.0.0

Przy bloku możesz ukryć sekcję bez usuwania, wybrać małe/standardowe/duże odstępy oraz nadać kotwicę, np. oferta. Link #oferta przewija do tej sekcji. Kotwice muszą być unikalne; duplikowanie zeruje kotwicę kopii. Ukryte bloki pozostają w edytorze i kopiach, ale nie są renderowane dla gości.

W Przenoszeniu układu eksportujesz JSON lub importujesz go z innej strony. Import sprawdza serwer, zastępuje lokalny układ, wymaga zapisania i daje się cofnąć. Nie przenosi wpisów, zdjęć ani ustawień strony; odnośniki do mediów trzeba dopasować do docelowej witryny. JSON zawiera też ukryte sekcje, więc traktuj go jako prywatny dokument roboczy.

### Zestawy startowe

Firma, Blog, Portal i Społeczność. Każdy zestaw tworzy trzy strony jako szkice: Start, Oferta/O nas i Kontakt. Nie nadpisuje istniejących treści, nie zmienia wyglądu ani nie publikuje menu. Przy zajętych adresach dobiera kolejny wolny adres. Przykładowe opisy i opinie trzeba zastąpić własnymi danymi.

### Studio wyglądu

Wybierz kolory, krój pisma, szerokość i narożniki. Dostępne są warianty Midnight, Paper i Forest oraz do 20 własnych wariantów. Dopiero zaznaczenie **Stosuj wygląd na stronie** i zapis włącza wygląd dla odwiedzających. Wyłączenie przywraca dotychczasowy wygląd. Własne motywy i pluginy mogą wymagać dodatkowego dopasowania CSS.

### Zapisywanie i historia

Bloki są przechowywane w treści strony, dzięki czemu korzystają z istniejących szkiców, planowania publikacji, kosza, kopii zapasowych i historii wersji. Zapis wykrywa nieaktualną wersję dokumentu i odmawia nadpisania nowszej treści. Przywrócenie starej wersji tworzy szkic.

Kreator dodatkowo przechowuje lokalny szkic w przeglądarce. To pomoc przy odzyskiwaniu pracy, a nie zapis na serwerze. Kopia może zawierać nieopublikowaną treść: korzystaj z własnego profilu przeglądarki. Usunięcie danych przeglądarki usuwa także tę kopię.

### Język podczas instalacji

Instalator ma osobne pole **Język strony**: Polski, English, Deutsch. Wybór zapisuje się w ustawieniach i w konfiguracji jako język domyślny. Goście nie dostają przełącznika języka; parametr `?lang=` ani stare ciasteczko gościa nie zmieniają języka witryny. Administrator może później świadomie zmienić język w Ustawieniach. Język interfejsu panelu pozostaje osobnym ustawieniem użytkownika. Teksty wpisane przez autorów nie są automatycznie tłumaczone.

## Instalacja, aktualizacja i weryfikacja

Aktualna instrukcja wydania 1.0.0 znajduje się w README.md.
