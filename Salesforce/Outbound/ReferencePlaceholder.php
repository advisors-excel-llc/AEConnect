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
     * @var string
     * @Serializer\Type("string")
     */
    private $entityRefId;

    public function __construct(string $entityRefId)
    {
        $this->entityRefId = $entityRefId;
    }

    /**
     * @return string
     */
    public function getEntityRefId(): string
    {
        return $this->entityRefId;
    }
}
