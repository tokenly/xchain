<?php

/**
*  SampleTransactionsHelper
*/
class SampleTransactionsHelper
{


    public function loadSampleTransaction($filename) {
        $data = json_decode(file_get_contents(base_path().'/tests/fixtures/transactions/'.$filename), true);
        if ($data === null) { throw new Exception("file not found: $filename", 1); }
        return $data;
    }

}