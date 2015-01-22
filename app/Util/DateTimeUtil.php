<?php

namespace App\Util;

/**
* Date and Time Utils
*/
class DateTimeUtil
{
    
    public static function ISO8601Date($timestamp=null) {
        $_t = new \DateTime('now');
        if ($timestamp !== null) {
            $_t->setTimestamp($timestamp);
        }
        $_t->setTimezone(new \DateTimeZone('UTC'));
        return $_t->format(\DateTime::ISO8601);
    }

}