# OSL – Internetowa Liga Strzelecka

**Wersja projektu: 0.6.0-dev** (wersja rozwojowa, przed pierwszym publicznym wydaniem).

Rozszerzenie do phpBB 3.3.x (`tennano/osl`), służące do prowadzenia lig strzeleckich w tematach forum. Każda liga może obejmować wiele niezależnych konkurencji, z osobnym zgłaszaniem wyników i miesięcznymi tabelami klasyfikacji.

## Instalacja i aktualizacje (XAMPP)

1. Jeśli OSL jest już zainstalowane i działa, **nie usuwaj jego danych ani tabel bazy danych**. Przed zastąpieniem plików wykonaj kopię zapasową katalogu rozszerzenia i bazy danych.
2. Zamknij wszystkie otwarte sesje edycji na forum i wykonaj kopię zapasową katalogu `ext/tennano/osl`.
3. Skopiuj katalog `tennano/osl/` z archiwum instalacyjnego do `C:\xampp\htdocs\<katalog-twojego-forum>\ext\tennano\osl\`, zachowując strukturę katalogów i w razie potrzeby nadpisując istniejące pliki. Alternatywnie rozpakuj katalog `tennano/` bezpośrednio do katalogu `ext/` forum.
4. Przy nowej instalacji otwórz Panel administracyjny phpBB (ACP), przejdź do **Dostosuj → Zarządzanie rozszerzeniami** i włącz **Online Shooting League**. Jeśli aktualizujesz już włączone rozszerzenie, a wymagane są migracje, postępuj zgodnie z procedurą aktualizacji opisaną niżej w sekcji *Uprawnienia do tworzenia lig w poszczególnych forach*.
5. Wyczyść pamięć podręczną phpBB w ACP (**Ogólne → Wyczyść pamięć podręczną**). Jeżeli stary, skompilowany szablon uniemożliwia dostęp do ACP, ręcznie usuń wygenerowane pliki pamięci podręcznej phpBB, zachowując `index.htm` i `.htaccess`.

**Uwaga:** Aktualizacja do rund opartych na miesiącach kalendarzowych zmienia granice rund. Jeżeli masz ligi testowe z wynikami zapisanymi według poprzednich granic przypadających w środku miesiąca, wykonaj kopię zapasową danych i utwórz te ligi testowe ponownie, zanim sprawdzisz nowe tabele wyników. Stare wyniki zachowują swoje pierwotne wartości `round_index` i nie są automatycznie przypisywane do innych rund. Ta aktualizacja nie usuwa żadnych tabel ani rekordów bazy danych. Opcjonalną kolumnę Grupa można włączyć podczas tworzenia nowej ligi; po zapisaniu wyników w istniejącej lidze tego ustawienia nie można już zmienić.

## Tworzenie ligi

- Podczas tworzenia nowego tematu otwórz zakładkę **Liga Strzelecka**, włącz tworzenie ligi i podaj tytuł, **miesiąc i rok rozpoczęcia** (RRRR-MM), czas trwania oraz liczbę najlepszych startów uwzględnianych w klasyfikacji. Każda runda trwa od pierwszego do ostatniego dnia miesiąca kalendarzowego, także w lutym i w latach przestępnych. Baza danych zapisuje pierwszy dzień wybranego miesiąca w istniejącym polu `start_date`; migracja schematu nie jest potrzebna.
- W sekcji **Konkurencje** zmień nazwę pierwszej konkurencji (np. `Karabin`) i skonfiguruj pola punktacji, każde z własnym dopuszczalnym zakresem. Dla tarcz papierowych włącz **Zliczanie wewnętrznych dziesiątek (10X)** dla danej serii. Liczba strzałów jest **zawsze wyliczana** jako **maksymalna liczba punktów za serię / 10**; nie ma pola do ręcznego wpisywania liczby strzałów. Aby włączyć 10X, maksimum musi więc być dodatnią wielokrotnością 10 (np. 400 punktów oznacza 40 strzałów i dopuszcza od 0 do 40 wewnętrznych dziesiątek).
- Każda seria punktowa domyślnie przyjmuje **punkty całkowite**. Zaznacz **Dziesiętne** między polem maksymalnej liczby punktów a opcją 10X, aby dopuścić wartości i limity z dokładnością do **0,1** (jedno miejsce po przecinku). Strzałki w formularzu wyników będą wtedy zwiększać i zmniejszać wynik o 0,1 zamiast o 1. Po zapisaniu wyników w konkurencji nie można już zmienić tej opcji.
- Użyj przycisku **+ Dodaj konkurencję**, aby utworzyć kolejne konkurencje (np. `Pistolet`) z niezależnymi polami punktacji.
- Zaznacz **Pokaż opcjonalną kolumnę Grupa / Klub w tabeli wyników**, aby dodać pole klubu/grupy do zgłaszania wyników oraz kolumnę Grupa przed kolumną Nick w każdej tabeli klasyfikacji. Jeśli opcja jest wyłączona, zarówno pole, jak i kolumna pozostają ukryte. Grupa nie jest dodatkową konkurencją.
- Umieść regulamin ligi w treści pierwszego posta.

## Zgłaszanie i edycja wyników

- Podczas odpowiadania w temacie ligi otwórz zakładkę **Wynik Zawodów**. Każda konkurencja ma osobne pole wyboru, **własną datę** i własne pola punktacji. Jeśli włączono kolumnę Grupa, ponad konkurencjami widoczne jest jedno opcjonalne pole tekstowe **Grupa / Klub**, wspólne dla uczestnika w całej lidze.
- Zaznacz i wypełnij tylko te konkurencje, dla których chcesz zgłosić wyniki. Niezaznaczone konkurencje nie są zapisywane. Jeśli dla serii włączono 10X, wpisz bezpośrednio po wyniku punktowym niezależną, całkowitą liczbę 10X (od 0 do automatycznie wyliczonej liczby strzałów). Nie dodaje ona dodatkowych punktów.
- Wynik w innej konkurencji możesz zgłosić w późniejszej odpowiedzi, nawet po kilku dniach.
- Każdy uczestnik może mieć tylko **jeden wynik w danej konkurencji i rundzie (miesięcznym okresie ligi)**, niezależnie od grupy i posta.
- Podana nazwa klubu/grupy jest przypisana uczestnikowi w danej lidze i automatycznie uzupełniana przy kolejnych zgłoszeniach. Jest opcjonalna (maksymalnie 100 znaków); można ją zmienić lub usunąć przy kolejnym zgłoszeniu albo podczas edycji istniejącego. Zmiany aktualizują również wcześniejsze wyniki tego uczestnika w tej samej lidze, bez dzielenia jego pozycji w klasyfikacji.
- Edycja odpowiedzi pozwala poprawić zapisane wyniki. Odznaczenie konkurencji podczas edycji usuwa **wyłącznie wynik tej konkurencji z edytowanego posta**.
- Pierwszy post wyświetla osobną tabelę klasyfikacji dla każdej konkurencji. Gdy włączono 10X, kolumna **Suma 10X** pokazuje liczbę wewnętrznych dziesiątek z najlepszych zaliczanych startów, a każda runda miesięczna ma osobne podkolumny **PTS** i **10X**. Miesiące bez wyniku mają w obu komórkach myślnik; zgłoszona runda z zerową liczbą wewnętrznych dziesiątek pokazuje `0`. O miejscu w klasyfikacji decydują najpierw punkty, a następnie 10X rozstrzyga remisy przed wcześniejszymi kryteriami (sumą wszystkich startów, średnią i najwyższym pojedynczym wynikiem). Gdy równe wyniki konkurują o ostatni zaliczany start, wybierany jest start z większą liczbą 10X. Odpowiedzi zawierające wyniki pokazują zgłoszone rezultaty wszystkich zaznaczonych konkurencji.
- Po zapisaniu wyników w konkurencji nie można zmieniać jej pól punktacji ani usunąć tej konkurencji. Nadal można poprawić jej nazwę. Po pojawieniu się wyników zablokowana jest także zmiana dat ligi i ustawienia widoczności kolumny Grupa. Uczestnicy wciąż mogą aktualizować nazwy swoich klubów/grup.
- Wynik można wysłać bez wpisywania dodatkowej treści wiadomości; komentarz tekstowy pozostaje opcjonalny.

## Struktura bazy danych

Pierwsza migracja (`migrations/v10x/m1_initial_schema.php`) tworzy trzy tabele z prefiksem tabel phpBB:

- `osl_leagues`: konfiguracja ligi i parametry rund;
- `osl_competitions`: nazwy konkurencji, kolejność wyświetlania oraz definicje pól punktacji;
- `osl_results`: wynik danej konkurencji, post, data, punktacja i runda.

Druga migracja (`migrations/v10x/m2_forum_creators.php`) dodaje:

- `osl_forum_creators`: uprawnienia do tworzenia tematów lig w poszczególnych forach.

Trzecia migracja (`migrations/v10x/m3_inner_tens.php`) rozszerza tabelę `osl_results` o `x_scores_data` (liczby 10X dla poszczególnych serii) i `total_x` (sumę 10X w danym starcie), zachowując dotychczasowe wyniki i ustawiając dla starych wpisów domyślną liczbę 10X równą zeru.

Unikalne indeksy tabeli `osl_results` wymuszają unikalność zestawów (`post_id`, `competition_id`) oraz (`league_id`, `competition_id`, `user_id`, `round_index`).

Opcjonalna funkcja grup korzysta z istniejących kolumn `osl_leagues.groups_enabled` (dla nowych lig wartość logiczna zakodowana jako JSON) i `osl_results.shooter_group`. Starsze ligi z niepustą listą wcześniej zdefiniowanych grup są interpretowane jako ligi z włączoną kolumną Grupa. Klasyfikacja nigdy nie jest dzielona według grup.

## Uprawnienia do tworzenia lig w poszczególnych forach

**Po instalacji żaden użytkownik nie może domyślnie tworzyć ligi**, w tym administratorzy i moderatorzy. Administrator może nadawać i odbierać uprawnienia w dowolnym forum; moderator może to robić wyłącznie w forach, które moderuje. Obie te role mogą również nadać uprawnienie samym sobie.

1. Otwórz forum, np. **Silhouette** (`viewforum.php?f=ID`).
2. Wybierz **Zarządzaj twórcami lig** obok nazwy forum. Odnośnik jest widoczny wyłącznie dla administratorów oraz moderatorów uprawnionych do zarządzania danym forum.
3. Wpisz nazwę istniejącego użytkownika (np. `jurek`) i kliknij **Przyznaj dostęp**. Możesz podać własną nazwę użytkownika.
4. Taki użytkownik może następnie tworzyć tematy lig OSL **wyłącznie w tym forum**, pod warunkiem że ma również standardowe uprawnienie phpBB `f_post` do zakładania tematów.
5. W każdej chwili możesz odebrać uprawnienie na tej samej stronie.

Migracja `m2_forum_creators` tworzy początkowo pustą tabelę przypisań i usuwa przestarzałe globalne uprawnienie `u_create_shooting_league`, które wcześniej pozwalało administratorom i moderatorom globalnym tworzyć ligi. W aktualnym kodzie administratorzy i moderatorzy nie mogą omijać wymogu indywidualnego przypisania uprawnienia dla forum. Istniejące ligi i wyniki nie są zmieniane.

**Aktualizacja ze starszej wersji OSL:** Wykonaj kopię zapasową bazy danych i katalogu rozszerzenia. Wyłącz OSL w ACP **bez usuwania jego danych**, zastąp pliki, a następnie ponownie włącz rozszerzenie, aby phpBB wykonał ewentualne oczekujące migracje. Na koniec wyczyść pamięć podręczną phpBB. Nie edytuj wcześniej wykonanej migracji `m1_initial_schema.php` ani nie usuwaj danych lig.

## Lista kontrolna funkcji Grupa / Klub

1. Utwórz ligę z dwiema konkurencjami i włącz opcjonalną kolumnę Grupa. Sprawdź, czy w tabeli klasyfikacji kolumna Grupa znajduje się przed kolumną Nick.
2. Zgłoś wynik bez nazwy grupy, a potem drugi wynik w innej konkurencji. Pole grupy powinno pozostać puste, a punkty uczestnika nie mogą zostać rozdzielone na kilka pozycji w klasyfikacji.
3. Wpisz `Club A` przy kolejnym zgłoszeniu, zapisz je i ponownie otwórz formularz. Formularz i tabele wszystkich konkurencji powinny teraz pokazywać `Club A`.
4. Przy kolejnym zgłoszeniu lub edycji zmień grupę na `Club B`, a następnie wyczyść jej nazwę. Wszystkie wcześniejsze wyniki uczestnika powinny odzwierciedlać ostatnio podaną wartość.
5. Utwórz osobną ligę z wyłączoną kolumną Grupa. Ani pole formularza, ani kolumna tabeli wyników nie powinny się pojawić.

## Lista kontrolna po instalacji

1. Utwórz ligę z konkurencjami karabinową i pistoletową o różnych maksymalnych wynikach.
2. Wyślij odpowiedź zawierającą tylko wynik karabinowy; nowy wynik powinien pojawić się wyłącznie w tabeli karabinu.
3. Wyślij kolejną odpowiedź z wynikiem pistoletowym za ten sam miesiąc; zgłoszenie powinno zostać zaakceptowane.
4. Spróbuj zgłosić drugi wynik karabinowy za ten sam miesiąc, nawet z inną grupą; duplikat powinien zostać odrzucony.
5. W kolejnym miesiącu zgłoś obie konkurencje w jednej odpowiedzi, używając różnych dat.
6. Edytuj post z dwoma wynikami, odznacz jedną konkurencję i sprawdź, czy drugi wynik pozostaje. Usuniętą konkurencję powinno być można ponownie zgłosić.
7. Sprawdź kolejność w klasyfikacji, sumy najlepszych rund, sumy wszystkich punktów, średnie, liczbę startów i miejsca ex aequo.
8. Przetestuj serię z 10X (w tym zero, maksymalną liczbę oraz wartość spoza zakresu), dwóch strzelców z taką samą liczbą punktów, lecz różną liczbą 10X, a następnie inną konkurencję z wyłączonym 10X. Potwierdź, że punkty nie są podwajane i że nadal można prawidłowo edytować istniejący wynik.
9. Sprawdź, czy tworzenie lig jest niedostępne dla wszystkich użytkowników, dopóki nie zostanie przyznane uprawnienie dla konkretnego forum.

## Uwagi dotyczące testów

Pliki PHP przeszły kontrolę składni; nadal konieczne są pełne testy integracyjne zaktualizowanej wersji w środowisku phpBB/XAMPP. W przypadku instalacji na nowym forum przeprowadź powyższe testy integracyjne, w tym test świeżej instalacji wszystkich trzech migracji.

## Licencja

GPL-2.0-only. Pełny tekst licencji znajduje się w pliku `license.txt`.


### Rozszerzone okno zgłaszania wyników

Organizatorzy ligi mogą podczas jej tworzenia ustawić **Dodatkowe dni** (0–7, domyślnie 0).
Przez wskazaną liczbę dni kalendarzowych po zakończeniu rundy strzelec może osobno dla każdej konkurencji wybrać opcję **Wyniki za poprzedni miesiąc**. To pole wyboru jest widoczne tylko podczas aktywnego okresu dodatkowych dni; po jego upływie znika i pojawia się ponownie podczas okresu dodatkowych dni kolejnej rundy. Zgłoszona data strzelania musi należeć do wybranej rundy. Niezależnie od momentu zgłoszenia nadal obowiązuje limit jednego wyniku na strzelca, konkurencję i rundę. Istniejące wyniki można edytować bez otwierania możliwości nowych, spóźnionych zgłoszeń. Po zapisaniu wyników ustawienie zostaje zablokowane. Migracja `m4_extension_days` zachowuje istniejące dane i ustawia dla starych lig domyślnie 0 dodatkowych dni.


### Eksport pełnych tabel wyników ligi

W pierwszym poście ligi obok jej tytułu znajdują się odnośniki do pobrania plików **PDF** i **CSV**. Każdy plik zawiera wszystkie konkurencje:

- PDF: poziome strony A3 z powtarzanymi kolumnami klasyfikacji i podziałem miesięcy na poziome sekcje; polskie znaki są obsługiwane, jeśli na serwerze dostępny jest Arial lub DejaVu Sans.
- CSV: UTF-8 z BOM, sekcje poszczególnych konkurencji oddzielone średnikami.

Eksport korzysta z tego samego mechanizmu klasyfikacji co tabele wyświetlane na forum i wymaga uprawnienia do odczytu forum, w którym znajduje się liga. Migracja bazy danych nie jest potrzebna.


## Opcjonalny zegar testowy (tylko gałąź test-clock)

Zegar testowy OSL jest domyślnie wyłączony. W ACP przejdź do **Rozszerzenia → OSL → Zegar testowy OSL**, aby włączyć lub wyłączyć symulację czasu i wybrać datę oraz godzinę testową (Europe/Warsaw). Ustawienia są zapisywane w konfiguracji phpBB tego forum, a nie w pliku konfiguracyjnym śledzonym przez Git.

Symulowany czas wpływa wyłącznie na logikę okresów zgłaszania wyników w OSL (wyświetlanie formularza, sprawdzanie poprawności zgłoszeń oraz początkową domyślną wartość roku ligi) i tylko wtedy, gdy żądanie HTTP pochodzi z adresu `127.0.0.1` lub `::1`. Wszystkie inne żądania używają rzeczywistego czasu serwera, nawet jeżeli przełącznik w ACP jest włączony. Symulator nie zmienia znaczników czasu postów phpBB, zegara systemu Windows ani działania innych rozszerzeń. Nie wpływa też na znaczniki czasu eksportów.

**Instalacja/aktualizacja:** Nowa migracja zachowująca dane rejestruje moduł ACP i ustawienia domyślnie wyłączonego zegara. W ACP wybierz **Dostosuj → Zarządzanie rozszerzeniami** i wyłącz OSL (**nie usuwaj** jego danych), skopiuj zaktualizowane pliki rozszerzenia, ponownie włącz OSL i wyczyść pamięć podręczną phpBB. Instalacja wymaga administratora z uprawnieniem `a_board`. Zachowaj tę funkcję w gałęzi Git `test-clock`, a nie w `main`.

**Ostrzeżenie:** Zgłoszenia wysyłane podczas testów nadal tworzą rzeczywiste rekordy w lokalnej bazie danych. Korzystaj z osobnego forum testowego i osobnej bazy danych. Zegar jest celowo ograniczony do bezpośrednich żądań z localhost i powinien pozostawać wyłączony na każdej instalacji publicznie dostępnej.
