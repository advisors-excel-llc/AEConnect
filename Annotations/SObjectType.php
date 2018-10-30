<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/14/18
 * Time: 12:02 PM
 */

namespace AE\ConnectBundle\Annotations;

use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Class SObjectType
 *
 * @package AE\ConnectBundle\Annotations
 * @Annotation
 * @Target({"CLASS"})
 */
class SObjectType
{
    /**
     * @var string
     * @Required()
     */
    private $name;

    /**
     * @var array
     */
    private $connections = ["default"];

    public function __construct(array $values)
    {
        if (!empty($values)) {
            if (array_key_exists("connections", $values)) {
                $connections = $values['connections'];
                if (!empty($connections)) {
                    $this->connections = is_array($connections) ? $connections : [$connections];
                }

                unset($values['connections']);
            }

            if (array_key_exists("name", $values)) {
                $this->name = $values['name'];
            } else {
                $this->name = array_shift($values);
            }
        }
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array
     */
    public function getConnections(): array
    {
        return $this->connections;
    }
}
