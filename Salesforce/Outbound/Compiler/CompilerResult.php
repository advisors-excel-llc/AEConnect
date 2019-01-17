<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:29 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Compiler;

use AE\ConnectBundle\Metadata\Metadata;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use JMS\Serializer\Annotation as Serializer;

/**
 * Class CompilerResult
 *
 * @package AE\ConnectBundle\Salesforce\Outbound\Compiler
 * @Serializer\ExclusionPolicy("NONE")
 */
class CompilerResult
{
    public const INSERT = "INSERT";
    public const UPDATE = "UPDATE";
    public const DELETE = "DELETE";

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $intent;

    /**
     * @var CompositeSObject
     * @Serializer\Type("AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject")
     */
    private $sObject;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $className;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $referenceId;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $connectionName;

    public function __construct(
        string $intent,
        CompositeSObject $object,
        string $className,
        string $referenceId,
        ?string $connectionName = 'default'
    ) {
        $this->setIntent($intent);
        $this->sObject        = $object;
        $this->referenceId    = $referenceId;
        $this->className      = $className;
        $this->connectionName = $connectionName;
    }

    /**
     * @return string
     */
    public function getIntent(): string
    {
        return $this->intent;
    }

    /**
     * @param string $intent
     *
     * @return CompilerResult
     */
    public function setIntent(string $intent): CompilerResult
    {
        if (in_array($intent, [self::INSERT, self::UPDATE, self::DELETE])) {
            $this->intent = $intent;
        }

        return $this;
    }

    /**
     * @return CompositeSObject
     */
    public function getSObject(): CompositeSObject
    {
        return $this->sObject;
    }

    /**
     * @param CompositeSObject $sObject
     *
     * @return CompilerResult
     */
    public function setSObject(CompositeSObject $sObject): CompilerResult
    {
        $this->sObject = $sObject;

        return $this;
    }

    /**
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @param string $className
     *
     * @return CompilerResult
     */
    public function setClassName(string $className): CompilerResult
    {
        $this->className = $className;

        return $this;
    }

    /**
     * @return string
     */
    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    /**
     * @param string $referenceId
     *
     * @return CompilerResult
     */
    public function setReferenceId(string $referenceId): CompilerResult
    {
        $this->referenceId = $referenceId;

        return $this;
    }

    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @param string $connectionName
     *
     * @return CompilerResult
     */
    public function setConnectionName(string $connectionName): CompilerResult
    {
        $this->connectionName = $connectionName;

        return $this;
    }
}
