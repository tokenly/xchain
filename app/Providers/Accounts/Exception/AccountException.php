<?php

namespace App\Providers\Accounts\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
* AccountException
* 
* ERR_FROM_ACCOUNT_NOT_FOUND
* ERR_INSUFFICIENT_FUNDS
* ERR_NO_BALANCE
* ERR_ACCOUNT_HAS_UNCONFIRMED_FUNDS
* ERR_ACCOUNT_HAS_SENDING_FUNDS
*/
class AccountException extends HttpException
{
    
    protected $account_error_name = null;

    public function __construct($account_error_name, $statusCode, $message = null, \Exception $previous = null, array $headers = array(), $code = 0)
    {
        $out = parent::__construct($statusCode, $message, $previous, $headers, $code);

        $this->setErrorName($account_error_name);
    }

    public function setErrorName($account_error_name) {
        $this->account_error_name = $account_error_name;
    }

    public function getErrorName() {
        return $this->account_error_name;
    }

}