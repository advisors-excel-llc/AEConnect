<?php

namespace AE\ConnectBundle\Util\Exceptions;

class MemoryLimitException extends \Exception
{
    private $memory = 0;

    public function __construct($message = "", $code = 0, $memory = 0, \Throwable $previous = null)
    {
        $this->memory = $memory;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return int
     */
    public function getMemory(): int
    {
        return $this->memory;
    }

    /**
     * @param int $memory
     */
    public function setMemory(int $memory): void
    {
        $this->memory = $memory;
    }

}
