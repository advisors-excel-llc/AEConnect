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

class CompilerResult
{
    public const INSERT = "INSERT";
    public const UPDATE = "UPDATE";
    public const DELETE = "DELETE";

    /**
     * @var string
     */
    private $intent;

    /**
     * @var CompositeSObject
     */
    private $sObject;

    /**
     * @var Metadata
     */
    private $metadata;

    /**
     * @var string
     */
    private $referenceId;

    public function __construct(string $intent, CompositeSObject $object, Metadata $metadata, string $referenceId)
    {
        $this->setIntent($intent);
        $this->sObject     = $object;
        $this->metadata    = $metadata;
        $this->referenceId = $referenceId;
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
     * @return Metadata
     */
    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * @param Metadata $metadata
     *
     * @return CompilerResult
     */
    public function setMetadata(Metadata $metadata): CompilerResult
    {
        $this->metadata = $metadata;

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
}
