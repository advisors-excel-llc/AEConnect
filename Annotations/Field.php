<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 11:58 AM
 */

namespace AE\ConnectBundle\Annotations;

use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Class Field
 *
 * @package AE\ConnectBundle\Annotations
 * @Annotation
 * @Target({"ALL"})
 */
class Field
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

    /**
     * @return bool
     */
    public function isIdentifier(): bool
    {
        return $this->identifier;
    }

    /**
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->required;
    }
}
