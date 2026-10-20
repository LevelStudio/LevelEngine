# LevelEngine 1.0.0 — nowa numeracja

To obecny LevelEngine z kompletem funkcji Studio, oznaczony 1.0.0. Zmiana numeracji nie przywraca starego kodu ani starego schematu bazy. Studio pozostaje podpisane by LevelGame.pl; wydawca CMS to LevelStudio.

FULL-INSTALL służy do nowej instalacji w pustym katalogu i pustej bazie. Wymagania: PHP 8.1+, MySQL/MariaDB z InnoDB i rozszerzenia sprawdzane przez instalator. Wybierz język strony i utwórz konto HA.

## Istniejący LevelEngine 3.0.0

1. Zrób niezależną kopię plików, bazy, config.php i storage/vault.key. Najpierw wykonaj próbę na kopii strony.
2. Rozpakuj HOTFIX-FTP-for-v3.0.0 i wgraj lib/updater.php oraz upgrade/manager.php przez FTP z zachowaniem ścieżek. Nie wgrywaj tego ZIP-a w UM.
3. Otwórz /upgrade/ na sesji HA. Wgraj UPGRADE-from-v3.0.0, potwierdź zaufaną paczkę bez podpisu oraz zmianę numeracji 3.0.0 → 1.0.0. Sprawdź i wykonaj aktualizację.
4. Sprawdź stronę, Studio, publikację, media i pluginy. Numer w panelu i manifestach będzie 1.0.0.

Manager dopuszcza wyłącznie manifest renumber z wersji 3.0.0 dokładnie na 1.0.0. Zwykłe downgrade pozostają zablokowane. Nie wymaga ponownego hasła, używa sesji HA i CSRF. Paczka zachowuje config.php, vault.key, media, własne pluginy i motywy; aktualizuje rdzeń i motyw default. Własne zmiany rdzenia zachowaj osobno. Numer zapisany w starej konfiguracji może pozostać historyczny: obowiązujące ustawienia wersji i manifest są aktualizowane.

Pluginy z wymaganiem min_cms 2.x/3.x mogą wymagać dostosowania deklaracji zgodności do nowej numeracji. Nie obniżono automatycznie wymagań zewnętrznych rozszerzeń.

Archiwa nie mają podpisu kryptograficznego; SHA-256 sprawdza integralność. Zachowano istniejący klucz zaufania. Testy lokalne nie obejmują pełnej migracji i odzyskiwania na MySQL ani wizualnego testu w przeglądarce.
