<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 5:27 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound;

use AE\SalesforceRestSdk\Rest\Composite\Builder\Reference;
use JMS\Serializer\Annotation as Serializer;

class ReferencePlaceholder
{
    /**
     * @var Reference
     * @Serializer\Exclude()
     */
    private $reference;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $entityRefId;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $field;

    public function __construct(string $entityRefId, string $field)
    {
        $this->entityRefId = $entityRefId;
        $this->field       = $field;
    }

    /**
     * @return Reference|null
     */
    public function getReference(): ?Reference
    {
        return $this->reference;
    }

    /**
     * @param Reference $reference
     *
     * @return ReferencePlaceholder
     */
    public function setReference(Reference $reference): ReferencePlaceholder
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * @return string
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @param string $field
     *
     * @return ReferencePlaceholder
     */
    public function setField(string $field): ReferencePlaceholder
    {
        $this->field = $field;

        return $this;
    }

    /**
     * @return string
     */
    public function getEntityRefId(): string
    {
        return $this->entityRefId;
    }

    public function __toString()
    {
        return null !== $this->reference ? $this->reference->field($this->field) : '';
    }
}
