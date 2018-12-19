<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 11:58 AM
 */

namespace AE\ConnectBundle\Annotations;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Class RecordType
 *
 * @package AE\ConnectBundle\Annotations
 * @Annotation
 * @Target({"ALL"})
 */
class RecordType
{
    /**
     * @var string|null
     */
    private $name;

    /**
     * @var array
     */
    private $connections = ["default"];

    public function __construct(array $values)
    {
        if (array_key_exists('name', $values)) {
            $this->name = $values['name'];
        }

        if (array_key_exists('connections', $values)) {
            $this->connections = (array) $values['connections'];
        }

        if (!empty($values) && array_key_exists('value', $values)) {
            $this->name = $values['value'];
        }
    }

    /**
     * @return null|string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param null|string $name
     *
     * @return RecordType
     */
    public function setName(?string $name): RecordType
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return array
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * @param array $connections
     *
     * @return RecordType
     */
    public function setConnections(array $connections): RecordType
    {
        $this->connections = $connections;

        return $this;
    }
}
