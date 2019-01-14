<?php
require_once dirname(__FILE__) . '/TagsFromPage.class.php';
require_once dirname(__FILE__) . '/config.inc.php';

class HeuristicHttpCheck
{
    const DEBUG = true;
    // mniejsze niż zakładane na potrzeby testów
    const BODY_CHECK_CHECKS_COUNT = 5;
    // docelowo 20 przy założeniu, że skrypt będzie odpalany co 6 godzin przez 5 dni
    const HTTP_CODE_CHECKS_COUNT = 3;
    // docelowo 8 przy założeniu, że skrypt będzie odpalany co 30 minut przez 4 godziny

    const MIN_ALL_PAGE_TAGS_1 = 10;
    const MIN_ALL_PAGE_TAGS_2 = 5;
    const MIN_SECOND_HALF_TAGS_1 = 5;
    const MIN_SECOND_HALF_TAGS_2 = 4;

    const RESET_HTTP_CODE_CHECK = 1;
    const RESET_BODY_CHECK = 2;

    private $sandbox_domain;

    private $domain;
    private $ssl;

    private $http_code = false;
    private $http_code_checks_count = false;
    private $http_code_valid = false;

    private $body_check_data = false;
    private $body_check_checks_count = false;
    private $body_check_valid = false;

    private $body_check_tags = array();

    private $page_http_code = false;
    private $page_http_body = false;

    public function __construct($domain, $ssl = false)
    {
        $this->domain = $domain;
        $this->ssl = $ssl;
        $this->sandbox_domain = SANDBOX . '.' . DOMAIN;
    }

    /**
     * wykonuje kod nauki http_code
     * jeśli aktualny kod http jest taki jaki był wcześniej to zwiększ $http_code_checks_count o +1
     * jeśli aktualny kod http się zmienił to $http_code_checks_count = 1
     * Zwraca:
     * -> 2 jeśli jesteśmy już nauczeni domeny (http_code_valid = true) - nic nie robimy
     * -> 1 jeśli nauka poszła dobrze
     * -> 0 jeśli błąd przy zapisie saveData()
     * -> -1 jeśli page_http_code mieści się w przedziale <500;599> - nic nie robimy
     * -> -2 jeśli błąd przy odczycie loadData()
     * -> usunięto -3 jeśli przekierowanie na IP poza naczymi serwerami
     *        bo uczymy się kodu 3xx
     */
    public function learnHttpCode()
    {
        if(self::DEBUG){echo  "-->| start " . __FUNCTION__ . " for $this->domain" . PHP_EOL ;}

        if ($this->http_code_checks_count === false or $this->body_check_checks_count === false)
            if ($this->loadData() === false) return -2; // return false;
        if ($this->http_code_valid === true) return 2; // return true;
        if ($this->page_http_code === false or $this->page_http_body === false)
            $this->getPage(); // niezależnie, co zwraca, uczymy się http_code
        if ($this->valueIsInRange($this->page_http_code, 500, 599)) return -1; // return false;

        if ($this->http_code == $this->page_http_code)
            $this->http_code_checks_count++;
        else {
            $this->http_code_checks_count = 1;
            $this->http_code = $this->page_http_code;
        }

        return $this->saveData();
    } // end function learnHttpCode

    /**
     * wykonuje kod nauki body_check
     * learnBody() po wykonaniu removeInvalidTags() "dolosowuje" tagi do $body_check_tags,
     *     tak aby była ich żądana ilość niepowtarzających się
     * learnBody() musi ustalić ile tagów oczekuje wg. algorytmu opisanego wcześniej.
     * learnBody() wie iloma tagami dysponuje na stronie i ile da się sensownie wylosować,
     *     więc może podjąć w tej kwestii decyzję.
     * przy każdej kolejnej nauce sprawdzamy, czy strona o małej ilości tagów
     *     zwiększyła ilość tagów z (5:10) na >10. Jeśli tak, to resetujemy naukę
     * Zwraca:
     * -> 2 jeśli jesteśmy już nauczeni domeny (body_check_valid = true) - nic nie robimy
     * -> 1 jeśli nauka poszła dobrze
     * -> 0 jeśli błąd przy zapisie saveData()
     * -> -1 jeśli page_http_code nie mieści się w przedziale <200;299> - nic nie robimy
     * -> -2 jeśli błąd przy odczycie loadData()
     * -> -3 jeśli przekierowanie na IP poza naszymi serwerami
     */
    public function learnBody()
    {
        if(self::DEBUG){echo  "-->| start " . __FUNCTION__ . " for $this->domain" . PHP_EOL ;}

        if ($this->http_code_checks_count === false or $this->body_check_checks_count === false)
            if (!$this->loadData()) return -2; // return false;
        if ($this->body_check_valid === true) return 2; // return true;
        if ($this->page_http_code === false or $this->page_http_body === false)
            if ($this->getPage() === false) return -3; // return false;
        if (!$this->valueIsInRange($this->page_http_code, 200, 299)) return -1; // return false;

        if ($this->body_check_checks_count == 0) { // pierwsza nauka

            if(self::DEBUG){echo  "   | ### pierwsza nauka" . PHP_EOL ;}

            if ($this->getTagsFromPage() !== false)
                $this->body_check_checks_count = 1;
            else {
                if(self::DEBUG){echo  " ### Brak wystarczającej ilości tagów na stronie (<" . self::MIN_ALL_PAGE_TAGS_2 . "). Resetuję dane." . PHP_EOL ;}
                return $this->saveData(self::RESET_BODY_CHECK);
            }
        }
        elseif ($this->body_check_checks_count > 0) {

            if(self::DEBUG){echo  "   | ### kolejna nauka" . PHP_EOL ;}

            $tags = new TagsFromPage($this->page_http_body);
            // unikamy powtarzania tagów
            $tags->firstHalfTags = $this->removeDuplicates($this->body_check_tags,$tags->firstHalfTags);
            $tags->secondHalfTags = $this->removeDuplicates($this->body_check_tags,$tags->secondHalfTags);
            // if (self::DEBUG) $this->mvd($varName = 'body_check_tags', __LINE__, __FUNCTION__, __CLASS__);
            // if(self::DEBUG){echo 'DEBUG count($tags->allPageTags) at line ' . __LINE__ . PHP_EOL; var_dump(count($tags->allPageTags));}

            // sprawdzamy przy każdej nauce, czy strona o małej ilości tagów,
            // zwiększyła ilość tagów z (5:10) na >10
            // jeśli tak, to resetujemy naukę
            // if(self::DEBUG){echo 'DEBUG $tags->firstQuantity at line ' . __LINE__ . PHP_EOL; var_dump($tags->firstQuantity);}
            // if(self::DEBUG){echo 'DEBUG $tags->secondQuantity at line ' . __LINE__ . PHP_EOL; var_dump($tags->secondQuantity);}
            if (count($this->body_check_tags) == 5
                and $tags->allQuantity >= 10
                and $tags->secondQuantity >= 5
            ) {
                if(self::DEBUG){echo  "   | ### Zwiększyła się ilość tagów na stronie (>=" . self::MIN_ALL_PAGE_TAGS_1 . ")." . PHP_EOL  ;}
                if(self::DEBUG){echo  "   |     Resetuję naukę." . PHP_EOL ;}
                return $this->saveData(self::RESET_BODY_CHECK);
            }

            // usuwa z body_check_tags i zwraca ile tagów zostało usuniętych
            $countRemoved = $this->removeInvalidTags();

            if ($countRemoved == 0) // nie usunięto tagów
                $this->body_check_checks_count++;
            else { // usunięto tagi, należy dobrać brakujące
                $this->body_check_checks_count = 1;
                // dobierz z secondHalf, tyle ile usunięto
                $toGet = $this->shuffleTags($countRemoved,$tags->secondHalfTags);
                if ($toGet > 0) {
                    if(self::DEBUG){echo  "   | ### W drugiej połowie strony jest za mało tagów, żeby dobrać." . PHP_EOL ;}
                    if(self::DEBUG){echo  "   |     Dobieram z pierwszej połowy." . PHP_EOL ;}
                    // dobierz z firsrHalf tyle, ile brakuje
                    $toGet = $this->shuffleTags($toGet,$tags->firstHalfTags);
                    if ($toGet > 0) {
                        if(self::DEBUG){echo  "   | ### Nie wystarczyło niepowtarzalnych tagów w obu połowach strony." . PHP_EOL ;}
                        if(self::DEBUG){echo  "   |     Resetuję naukę." . PHP_EOL ;}
                        return $this->saveData(self::RESET_BODY_CHECK);
                    }
                }
            }
        }
        else {
            if(self::DEBUG){echo  " ### Błędny body_check_checks_count. Resetujędane w bazie." . PHP_EOL ;}
            return $this->saveData(self::RESET_BODY_CHECK);
        }
        return $this->saveData();

    } // end function learnBody

    /**
     * uruchamiamy przy pierwszej nauce lub gdy body_check_checks_count = 0
     * metoda zapisuje do zmiennej body_check_tags poprzez funckję shuffleTags()
     * tagi ze strony ($this->page_http_body) są wyciągane, dzielone i liczone przez klasę TagsFromPage
     * przy spełnieniu jednego z warunków ilości tagów na stronie,
     * tagi są pobierane najpierw z drugiej połowy strony podając
     * do zmiennej $toGet ilość tagów pozostałych do pobrania z pierwszej połowy
     * Zwraca:
     * -> false, jeśli nie ma wystarczającej ilości tagów na stronie
     * -> true, w przeciwnym wypadku
    */
    private function getTagsFromPage()
    {
        if(self::DEBUG){echo  "   |-->| start " . __FUNCTION__ . PHP_EOL ;}

        $tags = new TagsFromPage($this->page_http_body);

        // dozwolone ilości tagów z firstHalf / secondHalf
        // 0/10, 1/9, 2/8, 3/7, 4/6, 5/5
        // lub 0/5, 1/4 lub 5 w allPageTags
        // if(self::DEBUG){echo 'DEBUG $tags->firstQuantity at line ' . __LINE__ . PHP_EOL; var_dump($tags->firstQuantity);}
        // if(self::DEBUG){echo 'DEBUG $tags->secondQuantity at line ' . __LINE__ . PHP_EOL; var_dump($tags->secondQuantity);}

        if ($tags->firstQuantity + $tags->secondQuantity >= self::MIN_ALL_PAGE_TAGS_1
            and $tags->secondQuantity >= self::MIN_SECOND_HALF_TAGS_1) {
            //losuj od 0/10 do 5/5
            if(self::DEBUG){echo 'DEBUG first condition started (losuj od 0/10 do 5/5) at line ' . __LINE__ . PHP_EOL;}
            $toGet = $this->shuffleTags(self::MIN_ALL_PAGE_TAGS_1,$tags->secondHalfTags);
            $toGet = $this->shuffleTags($toGet,$tags->firstHalfTags);
        }
        elseif ($tags->firstQuantity + $tags->secondQuantity >= self::MIN_ALL_PAGE_TAGS_2
            and $tags->secondQuantity >= self::MIN_SECOND_HALF_TAGS_2) {
            // losuj od 0/5 do 1/4
            if(self::DEBUG){echo 'DEBUG second condition started (losuj od 5/0 do 4/1) at line ' . __LINE__ . PHP_EOL;}
            $toGet = $this->shuffleTags(self::MIN_ALL_PAGE_TAGS_2,$tags->secondHalfTags);
            $toGet = $this->shuffleTags($toGet,$tags->firstHalfTags);
        }
        elseif ($tags->firstQuantity + $tags->secondQuantity >= self::MIN_ALL_PAGE_TAGS_2) {
            // losuj 5
            if(self::DEBUG){echo 'DEBUG third condition started (losuj 5) at line ' . __LINE__ . PHP_EOL;}
            $toGet = $this->shuffleTags(self::MIN_ALL_PAGE_TAGS_2,$tags->allPageTags);
        }
        else return false;

        // if (self::DEBUG) $this->mvd($varName = 'body_check_tags', __LINE__, __FUNCTION__, __CLASS__);
        if(self::DEBUG){echo  "   |<--| stop " . __FUNCTION__ . PHP_EOL ;}

        return true;
    } // end function getTagsFromPage

    /**
     * dodaje wylosowane tagi z tablicy $tagsArray do body_check_tags
     * Zwraca: różnicę między ilością tagów, które trzeba dodać ($toGet),
     * a ilością dodanych w danym wywołaniu funkcji
     */
    private function shuffleTags($toGet,$tagsArray)
    {
        if(self::DEBUG){echo  "       |-->| start " . __FUNCTION__ . PHP_EOL ;}
        if (self::DEBUG) $this->mvd($varName = 'toGet', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);

        shuffle($tagsArray);
        while ($element = array_pop($tagsArray) and $toGet > 0) {
            $this->body_check_tags[] = $element;
            $toGet--;
        }

        if (self::DEBUG) $this->mvd($varName = 'toGet', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
        if(self::DEBUG){echo  "       |<--| stop " . __FUNCTION__ . PHP_EOL ;}

        return $toGet;
    }

    /**
     * usuwa z $array2 tagi, które już mamy w $array1,
     * tak unikniemy powtarzania tagów
     * Zwraca: $array2 bez tagów, które się powtarzają w $array1
     */
    protected function removeDuplicates($array1,$array2)
    {
        if(self::DEBUG){echo  "       |-->| start " . __FUNCTION__ . PHP_EOL ;}

        // część wspólna tagów z $array1 i tagów z $array2
        $duplicates = array_intersect($array1,$array2);
        // usuń część wspólną z $array2
        $result = array_diff($array2,$duplicates);
        if(self::DEBUG){echo  "       |<--| stop " . __FUNCTION__ . PHP_EOL ;}
        return $result;
    }

    /**
     * sprawdzamy czy wszystkie tagi z body_check_tags występują na aktualnej stronie zapisanej w $page_http_body.
     * Usuwamy z tablicy $body_check_tags te tagi, które się nie pojawiły.
     * Zwraca:
     * -> integer - liczba tagów usuniętych,
     * -> 0 jeśli wszystkie tagi wystąpiły.
     */
    private function removeInvalidTags()
    {
        if(self::DEBUG){echo  "   |--> start " . __FUNCTION__ . PHP_EOL ;}
        $countRemoved = 0;

        // if (self::DEBUG) $this->mvd($varName = 'body_check_tags', __LINE__, __FUNCTION__, __CLASS__);
        foreach ($this->body_check_tags as $key => $tag) {
            if (strpos($this->page_http_body,$tag) === false)  {
                unset($this->body_check_tags[$key]);
                $countRemoved++;
            }
        }

        if(self::DEBUG){echo 'DEBUG $countRemoved at line ' . __LINE__ . PHP_EOL; var_dump($countRemoved);}
        if(self::DEBUG){echo  "   |<--| stop " . __FUNCTION__ . PHP_EOL ;}
        return $countRemoved;
    }

    /**
     * testuje zgodność aktualnej strony (jej kodu) z tym cośmy się już nauczyli o tej stronie
     * Zwraca:
     * -> 1 jeśli test przeszedł,
     * -> 0 jeśli test zakończył się błędem (jest problem ze stroną),
     * ->   lub błąd pobrania aktualnej wersji strony (np. całkowity brak połączenia)
     * -> -1 jeśli proces nauki nie został zakończony
     * -> -2 jeśli wystąpił błąd pobrania z bazy danych
     * -> usunięto -3 jeśli przekierowanie na IP poza naczymi serwerami
     *        bo sprawdzamy naukę kodu 3xx
     * -> -4 jeśli to jest nowy record w bazie
     */
    public function runHttpCodeTest()
    {
        if(self::DEBUG){echo  "-->| start " . __FUNCTION__ . " for $this->domain" . PHP_EOL ;}

        if ($this->http_code_checks_count === false or $this->body_check_checks_count === false)
            if (!$this->loadData()) return -2; // zapisuje do $this->http_code
        if ($this->http_code_checks_count == 0) return -4;
        if ($this->http_code_valid === false) return -1;
        $this->getPage(); // zawsze zapisuje do $this->page_http_code
        if ($this->page_http_code == $this->http_code) return 1;
        else return 0;

    } // end function runHttpCodeTest

    /**
     * testuje zgodność aktualnej strony (jej body) z tym cośmy się już nauczyli o tej stronie
     * test sprawdza czy wszystkie wymagane tagi są na aktualnej stronie, a jeśli nie ma któregokolwiek to zwraca 0
     * Zwraca:
     * -> 1 jeśli test przeszedł,
     * -> 0 jeśli któregoś z nauczonych tagów brakuje na stronie
     * ->   lub test zakończył się błędem (jest problem ze stroną),
     * ->   lub błąd pobrania aktualnej wersji strony (np. całkowity brak połączenia)
     * -> -1 jeśli proces nauki nie został zakończony
     * -> -2 jeśli wystąpił błąd pobrania z bazy danych
     * -> -3 jeśli przekierowanie na IP poza naszymi serwerami
     * -> -4 jeśli to jest nowy record w bazie
     */
    public function runBodyTest()
    {
        if(self::DEBUG){echo  "-->| start " . __FUNCTION__ . " for $this->domain" . PHP_EOL ;}

        if ($this->getPage() === false) return -3; // zapisuje do $this->page_http_body
        if ($this->http_code_checks_count === false or $this->body_check_checks_count === false)
            if (!$this->loadData()) return -2; // zapisuje do $this->body_check_tags
        if ($this->body_check_checks_count === 0) return -4;
        if ($this->body_check_valid === false) return -1;

        return $this->findTagsOnPage(); //0 lub 1

    } // end function runBodyTest

    /**
     * Zwraca:
     * -> 1 jeśli wszystkie zapamiętane/nauczone tagi są na stronie
     * -> 0 jeśli któregoś z nauczonych tagów brakuje na stronie
     */
    private function findTagsOnPage()
    {
        foreach ($this->body_check_tags as $tag)
            if (strpos($this->page_http_body,$tag) === false) return 0;
        return 1;
    }

    /**
     * pobiera z bazy danych i zapisuje w atrybutach klasy informacje o domenie;
     * jeśli w bazie danych nie ma takiej domeny to utwórz rekord i ustaw domyślne wartości, po czym ustaw atrybuty klasy
     * po pobraniu od razu zdekoduj JSONa i wstaw do body_check_tags odpowiednie tagi.
     * zawartość bazy - zakładamy, że pole 'nazwa' w tabeli 'domeny' jest 'UNIQUE'
     * Zwraca:
     * -> true  - jeśli połączenie i pobranie (zapisanie w przypadku nowej domeny) danych się udało
     * -> false - w przeciwnym wypadku
     */
    private function loadData()
    {
        if(self::DEBUG){echo  "   |-->| start " . __FUNCTION__ . PHP_EOL ;}
        if ($this->checkAndAddDomain() === false) return false;

        if ($connection = $this->dbConnect()) {
            $sql = 'SELECT dhd.http_code, dhd.http_code_checks_count, dhd.http_code_valid,
                           dhd.body_check_data, dhd.body_check_checks_count, dhd.body_check_valid
                    FROM _tests.domain_heuristic_data as dhd, public.domeny as domeny
                    WHERE dhd.domain = domeny.did and domeny.nazwa = ' . $connection->quote($this->domain);
            $result = $connection->query($sql)->fetch(PDO::FETCH_ASSOC);
            // if (self::DEBUG) $this->mvd($varName = 'result', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
            if ($result === false) return false;

            // zapisz dane z bazy do atrybutów klasy
            // (pola bazy muszą mieć takie same nazwy, jak atrybuty z zapytania)
            foreach ($result as $field_name => $field_value) {
                // if ($field_name == 'body_check_data') {
                //     if(self::DEBUG){echo 'DEBUG $field_name => $field_value at line ' . __LINE__ . PHP_EOL;}
                //     if(self::DEBUG){echo  "$field_name => $field_value" . PHP_EOL ;}
                // }
                $this->$field_name = $field_value;
            }

            if ($this->body_check_checks_count > 0)
                $this->body_check_tags = json_decode($this->body_check_data, true);
                // if (self::DEBUG) $this->mvd($varName = 'body_check_tags', __LINE__, __FUNCTION__, __CLASS__);

            if(self::DEBUG){echo  "   |<--| stop " . __FUNCTION__ . PHP_EOL ;}

            $connection = null;
            return true;
        }
        else return false;

    } // end function loadData

    /**
     * Zwraca:
     * -> false - gdy błąd połączenia
     * -> DB connection handle - gdy udane połączenie
     */
    private function dbConnect()
    {
        $dsn = 'pgsql:host=' . DBHOST . ';port=' . PORT . ';dbname=' . DBNAME . ';';
        try {
            $dbh = new PDO($dsn, DBUSER, DBPASS, [
                // Set PDO to fire PDOExceptions on errors
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            echo "Some sort of error has occurred! Here are the details:" . PHP_EOL;
            echo $e->getMessage();
            return false;
        }
        return $dbh;
    }

    /**
     * Sprawdza czy domena jest w tabeli domeny oraz powiązany rekord w tabeli dhd
     * jeśli brakuje recordu w tabeli dhd, dodaje
     * Zwraca:
     * -> false, jęsli problemy z bazą danych
     * -> true, jeśli rekordy istnieją lub zostały poprawnie dodane
     */
    private function checkAndAddDomain()
    {
        if(self::DEBUG){echo  "       |--> start " . __FUNCTION__ . PHP_EOL ;}
        if ($connection = $this->dbConnect()) {
            $sql = 'SELECT count(*)
                    FROM _tests.domain_heuristic_data as dhd, public.domeny as domeny
                    WHERE dhd.domain = domeny.did and domeny.nazwa = ' . $connection->quote($this->domain);
            $result = $connection->query($sql)->fetchColumn();
            // if(self::DEBUG){echo 'DEBUG $result at line ' . __LINE__ . PHP_EOL; var_dump($result);}
            if ($result === 1)
                return true; // dokładnie jeden wynik - nic nie robimy
            elseif ($result === 0) { // brak wyników - sprawdzamy powiązaną tabelę
                $sql = 'SELECT did FROM domeny WHERE nazwa = ' . $connection->quote($this->domain);
                $result = $connection->query($sql)->fetchColumn();
                // if(self::DEBUG){echo 'DEBUG $result at line ' . __LINE__ . PHP_EOL; var_dump($result);}

                if ($result !== false) { // id domeny pobrane poprawnie
                    // nie wyróżnione kolumny automatycznie dostają defaltowe wartości
                    $sql = 'INSERT INTO _tests.domain_heuristic_data (domain) VALUES (' . $result . ')';
                    $result = $connection->query($sql);
                    // if(self::DEBUG){echo 'DEBUG $result at line ' . __LINE__ . PHP_EOL; var_dump($result);}
                    if ($result !== false) return true; // rekord dodany poprawnie
                }
                 else { // TEN ELSE JEST W CAŁOŚCI DO WYRZUCENIA, TYLKO NA POTRZEBY TESTÓW
                     $sql = 'INSERT INTO domeny (nazwa) VALUES (' . $connection->quote($this->domain) . ')';
                     $result = $connection->query($sql);
                     // if(self::DEBUG){echo 'DEBUG $result at line ' . __LINE__ . PHP_EOL; var_dump($result);}
                     if ($result !== false) return true; // rekord dodany poprawnie
                     if(self::DEBUG){echo  " ### Dodano brakującą domenę do tabeli 'domeny', ale tylko na patrzeby testów." . PHP_EOL ;}
                 }
            }
            $connection = null;
            if(self::DEBUG){echo  "       |<-- stop " . __FUNCTION__ . PHP_EOL ;}
        }
        return false; // błąd połączenia lub zapytania, lub zdublowane rekordy

    } // end function checkAndAddDomain

    /**
    * zapisuje aktualny stan informacji do bazy danych
    * dodatkowo analizuje czy osiągnięto satysfakcjonujące wartości counterów,
    * jeśli tak to ustawia odpowiednie *_valid na true
    * Zwraca:
     * -> 1 poprawne połączenie z normalnym zapisem
     * -> 0 błąd połączenia lub zapytania do bazy danych
     * -> -10 błąd zapisu przy resetowaniu danych HTTP_CODE_CHECK
     * -> -11 poprawne resetowanie danych HTTP_CODE_CHECK
     * -> -20 błąd zapisu przy resetowaniu danych BODY_CHECK
     * -> -21 poprawne resetowanie danych BODY_CHECK
     */
    private function saveData($reset = 0)
    {
        if(self::DEBUG){echo  "   |-->| start " . __FUNCTION__ . PHP_EOL ;}
        // if (self::DEBUG) $this->mvd($varName = 'body_check_tags', __LINE__, __FUNCTION__, __CLASS__);
        // if (self::DEBUG) $this->mvd($varName = 'this', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
        // foreach ($this as $name => $value)
            // if(self::DEBUG){echo 'DEBUG $name at line ' . __LINE__ . PHP_EOL; var_dump($value);}

        if ($connection = $this->dbConnect()) {

            if ($reset == 1) { // RESET_HTTP_CODE_CHECK
                // nie ma takiej potrzeby. Umieszczone dla porządku
            }
            elseif ($reset == 2) { // RESET_BODY_CHECK
                $this->body_check_data = null;
                $this->body_check_checks_count = 0;
                $this->body_check_valid = false;
            }
            elseif (count($this->body_check_tags) > 0) {
                // resetujemy numerację tablicy, żeby json_encode nie tworzył obiektu
                $this->body_check_tags = array_values($this->body_check_tags);
                $this->body_check_data = json_encode($this->body_check_tags);
            }

            // if (self::DEBUG) $this->mvd($varName = 'reset', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
            // if (self::DEBUG) $this->mvd($varName = 'body_check_data', __LINE__, __FUNCTION__, __CLASS__);

            if ($this->http_code_checks_count  >= self::HTTP_CODE_CHECKS_COUNT)
                $this->http_code_valid = true;
            if ($this->body_check_checks_count >= self::BODY_CHECK_CHECKS_COUNT)
                $this->body_check_valid = true;

            $sql = "UPDATE _tests.domain_heuristic_data as dhd
                    SET http_code = ?,
                        http_code_checks_count = ?,
                        http_code_valid = ?,
                        body_check_data = ?,
                        body_check_checks_count = ?,
                        body_check_valid = ?
                    FROM public.domeny as domeny
                    WHERE dhd.domain = domeny.did and domeny.nazwa = ?";
            $sth = $connection->prepare($sql);
            $sth->bindValue(1, $this->http_code, PDO::PARAM_INT | PDO::PARAM_NULL);
            $sth->bindValue(2, $this->http_code_checks_count, PDO::PARAM_INT);
            $sth->bindValue(3, $this->http_code_valid, PDO::PARAM_BOOL);
            $sth->bindValue(4, $this->body_check_data, PDO::PARAM_STR | PDO::PARAM_NULL);
            $sth->bindValue(5, $this->body_check_checks_count, PDO::PARAM_INT);
            $sth->bindValue(6, $this->body_check_valid, PDO::PARAM_BOOL);
            $sth->bindValue(7, $this->domain, PDO::PARAM_STR);

            if(self::DEBUG){echo  "   |<--| stop " . __FUNCTION__ . PHP_EOL ;}
            if ($sth->execute())
                if (!$reset) return 1;
                else return -1*($reset*10+1);
            else
                if (!$reset) return 0;
                else return -1*($reset*10);
            // $reset = 0, return   1 lub   0
            // $reset = 1, return -11 lub -10
            // $reset = 2, return -21 lub -20
        }
        else return 0;

    } // end function saveData

    /**
     * pobiera stronę po HTTP/HTTPS (zależnie od wartości przekazanej w konstruktorze) z wykorzystaniem CURL
     * konfigurujemy (curl_setopt) curla w ten sposób, że podąża za max 3 przekierowaniami i akceptuje niepoprawne certyfikaty SSL.
     * zapisz dane w page_http_code i page_http_body
     * Zwraca:
     * -> true, jeśli pozostajemy w obrębie IP SANDBOX'a
     * -> false, w przeciwnym wypadku lub gdy problem z połączeniem
     */
    private function getPage()
    {
        if(self::DEBUG){echo  "   |-->| start " . __FUNCTION__ . PHP_EOL ;}
        $flag = null;
        $protocol = ($this->ssl) ? 'https://' : 'http://' ;
        $url = $protocol . $this->domain;
        // if (self::DEBUG) $this->mvd($varName = 'url', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);

        $ch = curl_init();
        $options = array();
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_FOLLOWLOCATION] = false;
        $options[CURLOPT_MAXREDIRS] = 3;
        $options[CURLOPT_HEADER] = false;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_USERAGENT] = SET_CURLOPT_USERAGENT;

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        // if (self::DEBUG) $this->mvd($varName = 'result', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
        $first_call_page_http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (self::DEBUG) $this->mvd($varName = 'first_call_page_http_code', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);

        if ($this->valueIsInRange($first_call_page_http_code, 300, 399)) {
            $options[CURLOPT_FOLLOWLOCATION] = true;
            curl_setopt_array($ch, $options);
        }

        $this->page_http_body = strtolower(curl_exec($ch));
        $ip = curl_getinfo($ch,CURLINFO_PRIMARY_IP);
        if (self::DEBUG) $this->mvd($varName = 'ip', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);

        if ($ip == gethostbyname($this->sandbox_domain)) {
            if (self::DEBUG) echo "   | ## na własnych serwerach" . PHP_EOL;

            $this->page_http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($this->valueIsInRange($this->page_http_code, 100, 599)) {
                $flag = true;
            }
            else {
                $flag = false;
            }
        }
        else {
            if (self::DEBUG) echo "   | ## na obcych serwerach" . PHP_EOL;
            $this->page_http_code = $first_call_page_http_code;
            $flag = false;
        }
        curl_close($ch);

        if(self::DEBUG){echo  "   |<--| stop " . __FUNCTION__ . PHP_EOL ;}

        return $flag;
    } // end function getPage

    private function valueIsInRange($value, $min, $max)
    {
        return ($value >= $min and $value <= $max) ? true : false ;
    }

    public function messageOrAction($result, $subject, $learnOrTest)
    {
        if(self::DEBUG){echo " ### Message Or Action ###" . PHP_EOL ;}
        if ($learnOrTest == 'test') {
            switch ($result) {
                case 1:
                    echo " ### Strona na domenie $this->domain przeszła test '$subject' pomyślnie." . PHP_EOL;
                    break;

                case 0:
                    echo " ### Jest problem ze stroną na domenie $this->domain." . PHP_EOL;
                    echo "     Nie udało się pobrać strony lub zawartość '$subject' się zmieniła." . PHP_EOL;
                    break;

                case -1:
                    echo " ### Nie zakończyliśmy jeszcze procesu uczenia się " . strtoupper($subject) . " strony dla domeny $this->domain." . PHP_EOL;
                    break;

                case -2:
                    echo " ### Jest problem z pobraniem danych z bazy dla domeny $this->domain." . PHP_EOL;
                    break;

                case -3:
                    echo " ### Domena $this->domain przekierowuje na adres IP poza naszymi serwerami." . PHP_EOL;
                    break;

                case -4:
                    echo " ### To jest nowa domena ($this->domain). Odpalamy naukę dla " . strtoupper($subject) . "." . PHP_EOL;
                    if ($subject == 'body') {
                        $result2 = $this->learnBody();
                        if(self::DEBUG){echo  "<--| stop learnBody() for $this->domain" . PHP_EOL ;}
                        $this->messageOrAction($result2,'body','learn');
                    }
                    elseif ($subject == 'http_code') {
                        $result2 = $this->learnHttpCode();
                        if(self::DEBUG){echo  "<--| stop learnHttpCode() for $this->domain" . PHP_EOL ;}
                        $this->messageOrAction($result2,'http_code','learn');
                    }
                    break;
            }
        }
        elseif ($learnOrTest == 'learn') {
            switch ($result) {
                case 2:
                    echo " ### GOTOWE! Proces uczenia się " . strtoupper($subject) . " strony dla domeny $this->domain został zakończony." . PHP_EOL;
                    break;

                case 1:
                    echo " ### OK! Nauka " . strtoupper($subject) . " strony dla domeny $this->domain poszła dobrze." . PHP_EOL;
                    break;

                case 0:
                    echo " ### Błąd przy próbie zapisu do bazy danych dla domeny $this->domain." . PHP_EOL;
                    break;

                case -1:
                    if ($subject == 'body') {
                        echo " ### http_code dla domeny $this->domain nie mieści się w przedziale <200;299>." . PHP_EOL;
                        echo "     Taka treść strony nas nie interesuje." . PHP_EOL;
                    }
                    elseif ($subject == 'http_code') {
                        echo " ### http_code dla domeny $this->domain mieści się w przedziale <500;599>." . PHP_EOL;
                        echo "     Kod błędu po stronie serwera nas nie interesuje." . PHP_EOL;
                    }
                    echo "     Nie podejmuję nauki '$subject'." . PHP_EOL;
                    break;

                case -2:
                    echo " ### Błąd przy próbie odczytu z bazy danych dla domeny $this->domain." . PHP_EOL;
                    break;

                case -3:
                    echo " ### Zawartość strony $this->domain nie znajduje się na naszych serwerach." . PHP_EOL;
                    break;

                case -20:
                    echo " ### Błąd przy próbie resetu danych dla domeny $this->domain." . PHP_EOL;
                    break;

                case -21:
                    echo " ### Poprawnie zresetowane dane w bazie danych dla domeny $this->domain." . PHP_EOL;
                    break;
            }
        }

    } // end function messageOrAction

    // my_var_dump
    // if (self::DEBUG) $this->mvd($varName = 'string', __LINE__, __FUNCTION__, __CLASS__, true, $$varName);
    private function mvd($varName, $line, $func, $class, $inner = false, $var = null)
    {
        if ($line) $line = ' at line ' . $line;
        if ($func) $func = ' in function ' . $func;
        if ($class) $class = ' of class ' . $class;
        if ($inner) {
            echo 'DEBUG $' . $varName . $line . $func . $class . PHP_EOL;
            var_dump($var);
        }
        else {
            echo 'DEBUG $this->' . $varName . $line . $func . $class . PHP_EOL;
            var_dump($this->$varName);
        }
        echo '' . PHP_EOL;
    }

} // end class HeuristicHttpCheck

?>
