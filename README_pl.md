# OSL – Internetowa Liga Strzelecka

**Wersja projektu: 0.6.0-dev** (wersja rozwojowa, przed pierwszym publicznym wydaniem).

Rozszerzenie do phpBB 3.3.x (`tennano/osl`), służące do prowadzenia lig strzeleckich w tematach forum. Każda liga może obejmować wiele niezależnych konkurencji, z osobnym zgłaszaniem wyników i miesięcznymi tabelami klasyfikacji.

## Świeża instalacja (XAMPP)

Wersja **main-candidate** jest przeznaczona wyłącznie do nowej instalacji. Jedna scalona migracja phpBB zastępuje dotychczasowy ciąg migracji rozwojowych. **Nie zastępuj plików istniejącej instalacji OSL tą wersją bez odrębnej, przetestowanej procedury przenoszenia danych.** Przed usuwaniem poprzedniej instalacji wykonaj kopię bazy danych phpBB.

1. Sprawdź, czy docelowa instalacja phpBB nie zawiera starych tabel OSL ani aktywnych wpisów jego migracji. Samo usunięcie plików rozszerzenia nie usuwa danych z bazy.
2. Skopiuj katalog `tennano/osl/` z archiwum do `C:\xampp\htdocs\<katalog-twojego-forum>\ext\tennano\osl\`, zachowując strukturę katalogów. Możesz też rozpakować `tennano/` bezpośrednio w katalogu `ext/` forum.
3. W panelu administracyjnym phpBB wybierz **Dostosuj → Zarządzanie rozszerzeniami** i włącz **Online Shooting League**.
4. Wyczyść pamięć podręczną phpBB (ACP → **Ogólne → Wyczyść pamięć podręczną**).

Migracja inicjalizacyjna tworzy od razu pełny aktualny schemat OSL. **Ta wersja nie aktualizuje automatycznie** starszych instalacji rozwojowych z zapisanymi danymi. Do weryfikacji używaj osobnej testowej bazy phpBB.

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

**Jedna scalona migracja** (`migrations/v10x/m1_initial_schema.php`) tworzy cztery tabele z prefiksem phpBB:

- `osl_leagues`: konfiguracja ligi, miesiące kalendarzowe, dodatkowe dni, widoczność grupy oraz instrukcje zgłaszania wyników wraz z ich stanem włączenia;
- `osl_competitions`: nazwy konkurencji, kolejność wyświetlania i definicje serii punktowych w JSON;
- `osl_results`: zgłoszenie wyniku, post, uczestnik, data strzelania, punkty i sumy, liczby 10X dla serii (`x_scores_data`) i suma 10X (`total_x`);
- `osl_forum_creators`: początkowo pusta tabela uprawnień do zakładania lig w poszczególnych forach.

Migracja tworzy wszystkie niezbędne indeksy, w tym unikalność `(post_id, competition_id)` oraz `(league_id, competition_id, user_id, round_index)`. **Nie** dodaje przestarzałego globalnego uprawnienia `u_create_shooting_league`.

Opcjonalna grupa korzysta z `osl_leagues.groups_enabled` (wartość logiczna w JSON) i `osl_results.shooter_group`; klasyfikacja nie jest dzielona według grupy. Ta migracja służy **nowej instalacji**, a nie aktualizacji wcześniej stosowanych migracji OSL.

## Uprawnienia do tworzenia lig w poszczególnych forach

**Po instalacji żaden użytkownik nie może domyślnie tworzyć ligi**, w tym administratorzy i moderatorzy. Administrator może nadawać i odbierać uprawnienia w dowolnym forum; moderator może to robić wyłącznie w forach, które moderuje. Obie te role mogą również nadać uprawnienie samym sobie.

1. Otwórz forum, np. **Silhouette** (`viewforum.php?f=ID`).
2. Wybierz **Zarządzaj twórcami lig** obok nazwy forum. Odnośnik jest widoczny wyłącznie dla administratorów oraz moderatorów uprawnionych do zarządzania danym forum.
3. Wpisz nazwę istniejącego użytkownika (np. `jurek`) i kliknij **Przyznaj dostęp**. Możesz podać własną nazwę użytkownika.
4. Taki użytkownik może następnie tworzyć tematy lig OSL **wyłącznie w tym forum**, pod warunkiem że ma również standardowe uprawnienie phpBB `f_post` do zakładania tematów.
5. W każdej chwili możesz odebrać uprawnienie na tej samej stronie.

Migracja inicjalizacyjna tworzy pustą tabelę uprawnień dla poszczególnych forów. Nie instaluje globalnego uprawnienia do tworzenia lig ani wyjątków dla administratorów i moderatorów.

**Aktualizacja starszych instalacji OSL nie jest obsługiwana przez tę wersję kandydacką.** Jeżeli masz wyniki do zachowania, nie nadpisuj ani nie usuwaj danych — najpierw przygotuj i przetestuj osobną migrację.

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

Przed wydaniem należy sprawdzić składnię plików PHP i przeprowadzić testy integracyjne na phpBB/XAMPP, w tym świeżą instalację oraz usuwanie danych/odinstalowanie pojedynczej migracji.

## Licencja

GPL-2.0-only. Pełny tekst licencji znajduje się w pliku `license.txt`.


### Rozszerzone okno zgłaszania wyników

Organizatorzy ligi mogą podczas jej tworzenia ustawić **Dodatkowe dni** (0–7, domyślnie 0).
Przez wskazaną liczbę dni kalendarzowych po zakończeniu rundy strzelec może osobno dla każdej konkurencji wybrać opcję **Wyniki za poprzedni miesiąc**. To pole wyboru jest widoczne tylko podczas aktywnego okresu dodatkowych dni; po jego upływie znika i pojawia się ponownie podczas okresu dodatkowych dni kolejnej rundy. Zgłoszona data strzelania musi należeć do wybranej rundy. Niezależnie od momentu zgłoszenia nadal obowiązuje limit jednego wyniku na strzelca, konkurencję i rundę. Istniejące wyniki można edytować bez otwierania możliwości nowych, spóźnionych zgłoszeń. Po zapisaniu wyników ustawienie zostaje zablokowane. Schemat inicjalizacyjny definiuje `extension_days` z wartością domyślną 0.


### Eksport pełnych tabel wyników ligi

W pierwszym poście ligi obok jej tytułu znajdują się odnośniki do pobrania plików **PDF** i **CSV**. Każdy plik zawiera wszystkie konkurencje:

- PDF: poziome strony A3 z powtarzanymi kolumnami klasyfikacji i podziałem miesięcy na poziome sekcje; polskie znaki są obsługiwane, jeśli na serwerze dostępny jest Arial lub DejaVu Sans.
- CSV: UTF-8 z BOM, sekcje poszczególnych konkurencji oddzielone średnikami.

Eksport korzysta z tego samego mechanizmu klasyfikacji co tabele wyświetlane na forum i wymaga uprawnienia do odczytu forum, w którym znajduje się liga. Migracja bazy danych nie jest potrzebna.
