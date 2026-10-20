# Media Uploader — LevelEngine CMS 2.2.0

## Używanie w Newsach

1. Otwórz PA → Treści → Nowy wpis lub edycja istniejącego Newsa.
2. Kliknij „Dodaj obrazek”.
3. Wybierz zdjęcia z komputera, przeciągnij je do okna lub wybierz już wgrany obrazek.
4. Dodaj opis ALT i kliknij „Wstaw do treści”.
5. Zapisz wpis. Adres obrazka prowadzi do pliku na hostingu CMS-a.

Wgrywanie plików do biblioteki nie zapisuje automatycznie treści Newsa —
aby opublikować zmiany, trzeba kliknąć „Zapisz wpis”. Wgrane pliki zostają
w bibliotece nawet po zamknięciu edytora bez zapisywania treści.

## Miniatura

W sekcji „Obrazek główny” kliknij „Wybierz z biblioteki / wgraj”.
Wybierz obrazek i „Ustaw miniaturę”. Możesz też użyć standardowego pola pliku.
Zdjęcie przesłane tym polem podczas zapisywania Newsa trafia do biblioteki.
„Usuń miniaturę z wpisu” odłącza obrazek od Newsa, bez usuwania pliku.

## Biblioteka

PA → Media → „Wgraj zdjęcia / otwórz bibliotekę”. Możesz przesłać kilka plików,
wyszukać je po tytule lub opisie i skopiować lokalny adres.
W edytorze obrazek można wybrać bez ręcznego kopiowania adresu.

Obsługiwane: JPEG, PNG, WebP i GIF do 5 MB każdy. Pliki trafiają do
uploads/media/, a dane opisowe do istniejącej tabeli media w bazie CMS-a.
Nie ma dodatkowej usługi, konta ani zewnętrznego hostingu obrazków.
Jeśli hosting ma niższy limit PHP, ustaw upload_max_filesize co najmniej 5M
i post_max_size co najmniej 8M. Katalog uploads/media/ musi być zapisywalny.

Admin musi mieć uprawnienie „Media” (manage_media), a do edycji Newsów — „Treści”.
Domyślne rangi Head Admin, Admin i Moderator mają oba uprawnienia.
Operacje uploadu wymagają zalogowania i poprawnego tokenu formularza.
Sprawdzana jest rzeczywista zawartość obrazka, a nazwa zapisanego pliku jest losowa.
SVG, PHP i inne pliki poza obsługiwanymi obrazkami są odrzucane.

Usuwanie z biblioteki jest blokowane, jeśli obrazek jest używany w treści
Newsa, podstronie, miniaturze, obrazie OG, galerii lub załącznikach wpisu.
Zewnętrzne witryny mogą korzystać z publicznego adresu pliku; takich odwołań
CMS nie jest w stanie sprawdzić.

## Aktualizacja

Użyj paczki UPGRADE dopasowanej do wersji 2.1.0 lub 2.0.1 w /upgrade/.
Przed aktualizacją wykonaj kopię plików i bazy. Aktualizacja zachowuje pliki
uploadów, istniejącą bibliotekę, treści, ustawienia i integrację LevelBridge.
Nie wymaga nowej tabeli ani migracji danych mediów.

FULL-INSTALL jest przeznaczony do nowej instalacji.

## Weryfikacja

Sprawdzono lokalnie upload przez prawdziwe żądania multipart oraz przeglądarkę,
uprawnienia, token formularza, format i limit pliku, lokalne adresy obrazków,
wyszukiwanie, zapis Newsa z miniaturą, ochronę używanych plików i obrazy Markdown.
Testy bazy wykonano na lokalnej kopii z SQLite. Paczki nie zostały wdrożone
na produkcyjny hosting ani przetestowane na rzeczywistej MySQL użytkownika.
