# LevelBridge 1.0.0

Plugin dla Paper 1.21.11, Java 21+, LevelEngine CMS 2.1.0.

## Instalacja

1. Wgraj LevelBridge-1.0.0.jar do plugins/ i uruchom ponownie serwer.
2. W LevelEngine otwórz PA → Serwery. Dodaj lub wybierz serwer i kliknij „Połącz z LevelBridge”.
3. Skopiuj pokazany config.yml do plugins/LevelBridge/config.yml.
4. Wykonaj /levelbridge reload. Sprawdź /levelbridge status.

Klucz jest wyświetlany w PA tylko raz. W CMS jest przechowywany jego SHA-256.
Każdy serwer ma osobny klucz. Generowanie nowego unieważnia poprzedni.
Klucza nie umieszczaj w linku, repozytorium ani na publicznej stronie.

## Działanie

Plugin wysyła co 15 sekund status, liczbę i limit graczy, wersję Minecrafta,
MOTD i wersję pluginu. Przed wysyłaniem pobiera dane na głównym wątku;
połączenie HTTPS odbywa się w osobnym wątku i nie blokuje gry.
Przekierowania HTTP nie są wykonywane. Timeout żądania wynosi 8 sekund.
Wyłączenie pluginu próbuje wysłać status offline. Przy awarii procesu lub sieci
CMS oznacza serwer jako offline po 75 sekundach bez kontaktu.

Strona odświeża kafelki co 15 sekund. Nie wysyła się nazw, UUID ani IP graczy.
Plugin nie łączy się bezpośrednio z MySQL i nie wykonuje komend z CMS-a.

## Komendy (OP lub levelbridge.admin)

- /levelbridge status — ostatnia odpowiedź CMS-a i czas ostatniego sukcesu.
- /levelbridge reload — ponowne odczytanie config.yml.
- /levelbridge sync — ręczne wysłanie statusu.

## Błędy

- HTTP 401: nieprawidłowy klucz, ID albo brak nagłówka Authorization na hostingu.
- HTTP 400: nieprawidłowy komunikat protokołu.
- HTTP 404: błędny endpoint albo brak pliku levelbridge-api.php na stronie.
- HTTP 503: problem z zapisem do bazy lub repozytorium.
- Brak kontaktu: sprawdź HTTPS, certyfikat strony i dostęp serwera Minecraft do hostingu.

Endpoint to pełny adres strony LevelEngine, np. https://twoja-strona.pl/levelbridge-api.php.
cms.levelgame.pl jest repozytorium do pobierania pluginu; jako endpoint podaj własną stronę CMS.
Do lokalnych testów można użyć http://localhost lub http://127.0.0.1 po włączeniu
allow-insecure-localhost. Na hostingu plugin wymaga HTTPS.

## Repozytorium LevelPromo

W Repository Manager wybierz „Plugin Paper”, ID levelbridge, nazwę LevelBridge,
wersję 1.0.0, minimalny CMS 2.1.0, Paper 1.21.11, Java 21 i prześlij JAR.
Publikacja zapisuje JAR w /repository/packages/paper/ i wylicza SHA-256.
Plugin pojawia się w /paper-plugins oraz na stronie głównej LevelPromo.
Przycisk w PA LevelEngine pobiera ten sam JAR z oficjalnego repo i sprawdza SHA-256.

## Budowanie

Maven 3.9+, JDK 21+: mvn clean package
Wynik: target/LevelBridge-1.0.0.jar. Używane jest publiczne Bukkit/Spigot API
implementowane przez Paper; brak zależności do instalowania na serwerze.

## Weryfikacja

Skompilowano dla API 1.21.11 i Java 21. Sprawdzono składnię PHP/JavaScript,
przyjmowanie heartbeatów przez prawdziwy endpoint PHP w lokalnej kopii testowej
z bazą SQLite, błędne i unieważnione klucze, timeout statusu, UTF-8,
publiczne pobieranie JAR, wykrywanie zmienionego pliku i publikację w Repository Manager.
Sprawdzono katalog w przeglądarce. Testy nie obejmują uruchomienia pluginu
na produkcyjnym Paper ani migracji na Twojej rzeczywistej bazie MySQL.
