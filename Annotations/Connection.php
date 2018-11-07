<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 1:10 PM
 */

namespace AE\ConnectBundle\Annotations;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Class Connection
 *
 * @package AE\ConnectBundle\Annotations
 * @Annotation
 * @Target({"PROPERTY", "METHOD"})
 */
class Connection
{
    /**
     * @var array
     */
    private $connections = ["default"];

    public function __construct(array $values)
    {
        if (!empty($values)) {
            $this->connections = (array)array_shift($values);
        }
    }

    /**
     * @return array
     */
    public function getConnections()
    {
        return $this->connections;
    }
}
