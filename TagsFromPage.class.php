<?php

class TagsFromPage extends HeuristicHttpCheck
{
    private $significantTags = array(
        'div', 'span', 'p', 'a', 'td', 'h[1-6]',
    );
    /* $pattern = "/<(div|span|p|a|td|h[1-6]) [^>]*?(class=|id=).*?>/"; */
    /* $pattern = '/<(' . implode('|',$this->significantTags) . ') [^>]*?(class=|id=).*?>/'; */
    private $bodyLength;

    private $firstHalf;
    private $secondHalf;

    protected $allPageTags;
    protected $firstHalfTags;
    protected $secondHalfTags;

    protected $firstQuantity;
    protected $secondQuantity;

    public function __construct($page_http_body)
    {
        $this->bodyLength = strlen($page_http_body);

        $this->firstHalf = substr($page_http_body,0,$this->my_intdiv($this->bodyLength,2));
        $this->secondHalf = substr($page_http_body,$this->my_intdiv($this->bodyLength,2));

        $this->allPageTags = $this->pullUniqueTagsFromString($page_http_body);
        $this->firstHalfTags = $this->pullUniqueTagsFromString($this->firstHalf);
        $this->secondHalfTags = $this->pullUniqueTagsFromString($this->secondHalf);

        // usuwamy z pierwszej połowy tagi występujące w drugiej połowie
        $this->firstHalfTags = $this->removeDuplicates($this->secondHalfTags,$this->firstHalfTags);

        $this->allQuantity = count($this->allPageTags);
        $this->firstQuantity = count($this->firstHalfTags);
        $this->secondQuantity = count($this->secondHalfTags);
        // echo 'DEBUG $this->firstHalfTags at line ' . __LINE__ . PHP_EOL; var_dump($this->firstHalfTags);
        // echo 'DEBUG $this->secondHalfTags at line ' . __LINE__ . PHP_EOL; var_dump($this->secondHalfTags);
    }

    private function pullUniqueTagsFromString($string)
    {
        $tags = array();
        $pattern = '/<(' . implode('|',$this->significantTags) . ') [^>]*?(class=|id=).*?>/';
        preg_match_all($pattern,$string,$tags);
        // echo 'DEBUG $pattern at line ' . __LINE__ . PHP_EOL; var_dump($pattern);
        // echo 'DEBUG $tags[0] at line ' . __LINE__ . PHP_EOL; var_dump($tags[0]);

        $tags = array_unique($tags[0]);
        // echo 'DEBUG $tags at line ' . __LINE__ . PHP_EOL; var_dump($tags);

        return $tags;
    } // end function pullUniqueTagsFromString

    private function my_intdiv($a, $b)
    {
        return ($a - $a % $b) / $b;
    }

} // end class TagsFromPage
?>