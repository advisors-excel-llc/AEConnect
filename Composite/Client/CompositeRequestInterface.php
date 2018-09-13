<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/13/18
 * Time: 5:19 PM
 */

namespace AE\ConnectBundle\Composite\Client;

use AE\ConnectBundle\Composite\Model\SObject;

interface CompositeRequestInterface
{
    public function setRecords($records);

    /**
     * @return array|SObject[]
     */
    public function getRecords(): array;
    public function setAllOrNone(bool $allOrNone);
    public function isAllOrNone(): bool;
    public function addRecord(SObject $record);
    public function removeRecord(SObject $record);
}
