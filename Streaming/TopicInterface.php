<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 6:01 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Bayeux\ConsumerInterface;

interface TopicInterface
{
    public function getName(): string;
    public function setName(string $name);
    public function getQuery(): string;
    public function setQuery(string $query);
    public function getFilters(): array;
    public function setFilters(array $filters);
    public function getApiVersion(): string;
    public function setApiVersion(string $apiVersion);
    public function isNotifyForOperationCreate(): bool;
    public function setNotifyForOperationCreate(bool $notifyForOperationCreate);
    public function isNotifyForOperationUpdate(): bool;
    public function setNotifyForOperationUpdate(bool $notifyForOperationUpdate);
    public function isNotifyForOperationUndelete(): bool;
    public function setNotifyForOperationUndelete(bool $notifyForOperationUndelete);
    public function isNotifyForOperationDelete(): bool;
    public function setNotifyForOperationDelete(bool $notifyForOperationDelete);
    public function getNotifyForFields(): string;
    public function setNotifyForFields(string $notifyForFields);
    public function isAutoCreate(): bool;
    public function setAutoCreate(bool $autoCreate);
    public function addSubscriber(ConsumerInterface $consumer);
    public function getSubscribers(): array;
}
