<?php

require_once dirname(__FILE__) . '/HeuristicHttpCheck.class.php';

$domains = array();

$domains = array(
    array(
        'domainname' => 'felek.art.pl',
        'ssl'        => false,
    ),
    array(
        'domainname' => 'onet.pl',
        'ssl'        => true,
    ),
);

echo 'DEBUG $domains at line ' . __LINE__ . PHP_EOL; var_dump($domains);

foreach ($domains as $domain) {
    $domainName = $domain['domainname'];
    $ssl = $domain['ssl'];

    echo PHP_EOL . "============= domena $domainName =============" . PHP_EOL;

    $lesson = new HeuristicHttpCheck($domainName, $ssl);

    $result = $lesson->learnHttpCode();
    if(HeuristicHttpCheck::DEBUG){echo "<--| stop learnHttpCode()" . PHP_EOL ;}
    // echo 'DEBUG $test->learnHttpCode() at line ' . __LINE__ . PHP_EOL; var_dump($result);
    $lesson->messageOrAction($result,'http_code','learn');

    $lesson = null;

}
?>