# LevelEngine CMS 1.0.0 FINAL

Wydanie: **1.0.0**, build **2026101601**, 16.10.2026. CMS: LevelStudio. LevelEngine Studio: by LevelGame.pl.

## Co się zmieniło

- Nowy panel: kokpit, statystyki rzeczywistych wpisów i kont z 14/30 dni, kalendarz publikacji, status serwerów, aktywność zespołu, przewodnik oraz wskazówki o kopiach, 2FA i harmonogramie.
- Wyszukiwanie narzędzi, wpisów, stron i użytkowników przez **Ctrl+K**. Wyniki i skróty respektują uprawnienia.
- Wyszukiwanie, filtry statusów i paginacja list. Bezpieczne duplikowanie treści jako szkic. Kosz i historia wersji pozostają dostępne.
- Studio: podgląd komputera, tabletu i telefonu, rozszerzony podgląd, własna liczba kolumn, sekcja Kontakt, wskazówki SEO/ALT/kotwic oraz kopie lokalne i serwerowe zapisanych stron.
- Nowe, jeszcze niezapisane strony Studio mają kopię lokalną przypisaną do konkretnej karty. Edytory tekstowe również korzystają z oddzielnych kopii lokalnych, zanim utworzysz stronę na serwerze. Nie zamykaj karty, jeśli przeglądarka zgłasza brak miejsca na kopię.
- Repozytorium: poprawna walidacja katalogu, pamięć podręczna, jawny stan połączenia, odświeżanie, weryfikacja wymagań i SHA-256. Instalacja ponownie pobiera katalog online.
- Poprawione m.in. slugi z wielkimi literami, odtwarzanie odznaczonych pól, konflikty menu, unieważnianie sesji po zmianie rangi, błędy rejestracji i walidacja obrazów. Mniejsza liczba zapytań o ustawienia.
- Mniejszy tekst menu strony głównej: 12 px na komputerze, 13 px w menu telefonu. Nadpisanie działa również po załadowaniu motywów i Studio.

## Pierwsza instalacja

1. Wymagania: PHP 8.1+, MySQL/MariaDB z InnoDB, PDO MySQL, mbstring, DOM, OpenSSL, ZIP, GD i cURL. Hosting musi obsługiwać Apache `.htaccess`/mod_rewrite lub równoważne reguły Nginx.
2. Rozpakuj **FULL-INSTALL** do pustego katalogu strony i otwórz `/install/`. Podaj pustą bazę, publiczny URL i dane Head Admina. Hasło: 10–72 bajty; znaki spoza ASCII mogą zajmować więcej niż bajt.
3. Zaloguj się. Na kokpicie otwórz przewodnik. Ustaw nazwę i opis witryny, utwórz stronę w Studio, zapisz ją jako szkic i obejrzyj podgląd.
4. Opublikuj gotową stronę i dodaj do nawigacji. W Studio → Strona główna możesz wybrać opublikowaną podstronę jako stronę startową.
5. Włącz 2FA w swoim koncie. W Centrum narzędzi skonfiguruj integracje, jeśli chcesz korzystać z wiadomości e-mail i Discorda.
6. Ustaw na hostingu zadanie wykonywane **co minutę**: `php /pełna/ścieżka/do/CMS/bin/worker.php`. Worker obsługuje zaplanowane publikacje, kolejkę wiadomości i historię serwerów. Możesz uruchomić go kontrolnie w Centrum narzędzi.

## Aktualizacja istniejącej wersji 1.0.0

Numer pozostaje 1.0.0; aktualizację identyfikuje build 2026101601. Starszy Upgrade Manager odrzuca paczki z takim samym numerem, dlatego najpierw potrzebuje małej poprawki.

1. Zachowaj niezależną kopię bazy i plików, zwłaszcza `config.php`, `storage/vault.key` i `uploads/`.
2. Rozpakuj **HOTFIX-FTP** i prześlij znajdujące się w nim `lib/updater.php` oraz `upgrade/manager.php` do istniejącego CMS, zachowując ścieżki. To pliki do FTP, a nie paczka do formularza aktualizacji.
3. Jako Head Admin otwórz `/upgrade/`. Wgraj **FINAL-UPGRADE-from-v1.0.0.zip**, zaznacz potwierdzenie zaufanej paczki bez podpisu i wykonaj weryfikację, a następnie aktualizację. Manager zapisze kopię bazy i zastępowanych plików.
4. Sprawdź kokpit, Studio, menu i Marketplace. W managerze powinien pojawić się build 2026101601. Ponowna instalacja tego samego builda przez manager jest blokowana.

Aktualizacja zachowuje konfigurację, klucz sekretów, media, konta, treści, własne pluginy i motywy. Nie uruchamiaj instalatora na istniejącej bazie. Paczka jest przeznaczona dla obecnego 1.0.0 ze schematem Suite 10. Wersję 3.0.0 najpierw należy przeprowadzić przez dotychczasową ścieżkę zmiany numeracji 3.0.0 → 1.0.0.

## Skróty i codzienna praca

- **Ctrl+K** w panelu: szukaj narzędzia lub treści. Strzałki wybierają wynik, Enter go otwiera, Esc zamyka.
- **Ctrl+S** w edytorach: zapisz. W Studio **Ctrl+Z / Ctrl+Shift+Z** poza polem tekstowym obsługują historię układu.
- Przycisk kompaktowego widoku w nagłówku pozwala zmieścić więcej danych na ekranie.
- Publikacje zaplanowane wymagają workera; sam zapis daty nie uruchamia zadania na hostingu.
- Zapis kopii roboczej nie publikuje treści. Po odzyskaniu kopii sprawdź zawartość i zapisz ją świadomie. Konflikt wersji wymaga porównania z aktualną treścią.
- Panel → Kopie zapasowe tworzy kopię **bazy**. Media, konfigurację i klucz sekretów przechowuj również w niezależnej kopii plików hostingu.

## Repozytorium i zewnętrzne połączenia

Oficjalny katalog: `https://cms.levelgame.pl/repository/index.json`. Wymagane są działające DNS, cURL, HTTPS i poprawny magazyn certyfikatów CA na hostingu. Nie wyłączaj weryfikacji TLS. Stan połączenia i przyczyna błędu są wyświetlane w Marketplace.

Pamięć katalogu jest ważna przez 5 minut; po awarii można przeglądać ostatni katalog do 24 godzin. Nie instaluje się paczek wyłącznie na podstawie starego katalogu. Niektóre pozycje repozytorium mają status planowany albo wymagają wersji CMS wyższej od 1.0.0 — panel wyjaśnia to i blokuje instalację. Metadanych zewnętrznego repozytorium nie zmieniono.

Paczki tego wydania są **niepodpisane kryptograficznie**. Manifesty zawierają sumy SHA-256, które wykrywają uszkodzenie plików, lecz nie stanowią podpisu wydawcy. Zachowano istniejące mechanizmy zaufanych kluczy i wymaganie jawnego potwierdzenia paczki bez podpisu.

## Hosting Nginx

`.htaccess` działa na Apache. Na Nginx ustaw odpowiednik przekierowania do `index.php` i blokad dostępu do `config.php`, `.env`, `lib`, `storage`, `backups`, `bin`, `templates`, bezpośrednich plików pluginów oraz plików SQL, logów i kluczy. W `uploads` oraz plikach motywów nie wolno wykonywać PHP. Przykład reguł znajdziesz w `README-NGINX.md`; dopasuj ścieżkę root i socket PHP do hostingu.

Szczegółowe wyniki i zakres testów towarzyszą wydaniu w raporcie audytu.
