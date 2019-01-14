<?php

require_once dirname(__FILE__) . '/HeuristicHttpCheck.class.php';

$domains = array();

// echo 'DEBUG $domains at line ' . __LINE__ . PHP_EOL; var_dump($domains);

foreach ($domains as $domain) {
    $domainName = $domain['domainname'];
    $ssl = $domain['ssl'];

    echo PHP_EOL . "============= domena $domainName =============" . PHP_EOL;

    $test = new HeuristicHttpCheck($domainName,$ssl);

    $result = $test->runHttpCodeTest();
    if(HeuristicHttpCheck::DEBUG){echo "<--| stop runHttpCodeTest()" . PHP_EOL ;}
    $test->messageOrAction($result,'http_code','test');

    $result = $test->runBodyTest();
    if(HeuristicHttpCheck::DEBUG){echo "<--| stop runBodyTest()" . PHP_EOL ;}
    $test->messageOrAction($result,'body','test');

    $test = null;
}

?>