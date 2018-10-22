<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 3:07 PM
 */

namespace AE\ConnectBundle\Metadata;

use JMS\Serializer\Annotation as Serializer;

abstract class AbstractFieldMetadata
{
    /**
     * @var string|null
     * @Serializer\Type("string")
     */
    protected $property;

    /**
     * @var string|null
     * @Serializer\Type("string")
     */
    protected $setter;

    /**
     * @var string|null
     * @Serializer\Type("string")
     */
    protected $getter;

    /**
     * @return null|string
     */
    public function getProperty(): ?string
    {
        return $this->property;
    }

    /**
     * @param null|string $property
     *
     * @return AbstractFieldMetadata
     */
    public function setProperty(?string $property): self
    {
        $this->property = $property;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSetter(): ?string
    {
        return $this->setter;
    }

    /**
     * @param null|string $setter
     *
     * @return AbstractFieldMetadata
     */
    public function setSetter(?string $setter): self
    {
        $this->setter = $setter;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getGetter(): ?string
    {
        return $this->getter;
    }

    /**
     * @param null|string $getter
     *
     * @return AbstractFieldMetadata
     */
    public function setGetter(?string $getter): self
    {
        $this->getter = $getter;

        return $this;
    }
}
