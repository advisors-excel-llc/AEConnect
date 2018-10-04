<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 2:41 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound;

use AE\ConnectBundle\Metadata\Metadata;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use JMS\Serializer\Annotation as Serializer;

class MessagePayload
{
    /**
     * @var Metadata
     * @Serializer\Type("AE\ConnectBundle\Metadata\Metadata")
     */
    private $metadata;

    /**
     * @var CompositeSObject
     * @Serializer\Type("AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject")
     */
    private $sobject;

    /**
     * @return Metadata
     */
    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * @param Metadata $metadata
     *
     * @return MessagePayload
     */
    public function setMetadata(Metadata $metadata): MessagePayload
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @return CompositeSObject
     */
    public function getSobject(): CompositeSObject
    {
        return $this->sobject;
    }

    /**
     * @param CompositeSObject $sobject
     *
     * @return MessagePayload
     */
    public function setSobject(CompositeSObject $sobject): MessagePayload
    {
        $this->sobject = $sobject;

        return $this;
    }
}
