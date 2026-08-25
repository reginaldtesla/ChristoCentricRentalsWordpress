<?php
/**
 * @link       https://rentopian.com
 * @since      1.0.0
 *
 * @package    rentopian-sync
 */
class RentalException extends Exception
{
    protected $type;
    protected $statusCode;

    const TYPE_RUNTIME = 0;
    const TYPE_SYNC_GLOBAL = 1;
    const TYPE_SYNC_RUNTIME = 2;

    public function __construct($message = "", $type = self::TYPE_RUNTIME, $statusCode = 500, $code = 0, ?Throwable $previous = null) {
        $this->type = $type;
        $this->statusCode = $statusCode;
        parent::__construct($message, $code, $previous);
    }

    public function getType()
    {
        return $this->type;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }
}