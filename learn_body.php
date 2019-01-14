<?php

require_once dirname(__FILE__) . '/HeuristicHttpCheck.class.php';

$domains = array();

 // echo 'DEBUG $domains at line ' . __LINE__ . PHP_EOL; var_dump($domains);

foreach ($domains as $domain) {
    $domainName = $domain['domainname'];
    $ssl = $domain['ssl'];

    echo PHP_EOL . "============= domena $domainName =============" . PHP_EOL;

    $lesson = new HeuristicHttpCheck($domainName,$ssl);

    $result = $lesson->learnBody();
    if(HeuristicHttpCheck::DEBUG){echo "<--| stop learnBody()" . PHP_EOL ;}
    // echo 'DEBUG $test->learnBody() at line ' . __LINE__ . PHP_EOL; var_dump($result);
    $lesson->messageOrAction($result,'body','learn');

    $lesson = null;

}
?>