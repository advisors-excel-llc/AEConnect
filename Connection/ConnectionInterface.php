<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:00 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Streaming\ClientInterface;
use AE\SalesforceRestSdk\Rest\Client as RestClient;
use AE\SalesforceRestSdk\Bulk\Client as BulkClient;

interface ConnectionInterface
{
    public function getName(): string;
    public function getStreamingClient(): ClientInterface;
    public function getRestClient(): RestClient;
    public function getBulkClient(): BulkClient;
    public function getMetadataRegistry(): MetadataRegistry;
    public function isDefault(): bool;
    public function hydrateMetadataDescribe();
}
