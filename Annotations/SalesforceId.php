<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 2:28 PM
 */

namespace AE\ConnectBundle\Annotations;

use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Class SalesforceId
 *
 * @package AE\ConnectBundle\Annotations
 * @Annotation
 * @Target({"PROPERTY", "CLASS"})
 */
class SalesforceId
{
    /**
     * @var string
     * @Required()
     */
    private $connection = "default";

    /**
     * SalesforceId constructor.
     *
     * @param array $values The array of values for the annotation. Should contain 1 value, being the connection name
     */
    public function __construct(array $values = [])
    {
        if (!empty($values)) {
            if (array_key_exists('connection', $values)) {
                $this->connection = $values['connection'];
            } else {
                $this->connection = array_shift($values);
            }
        }
    }

    /**
     * @return string
     */
    public function getConnection(): string
    {
        return $this->connection;
    }
}
