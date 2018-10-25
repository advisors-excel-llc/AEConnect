<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 7:07 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Salesforce\Bulk\BulkDataProcessor;
use Symfony\Component\Console\Command\Command;

class BulkCommand extends Command
{
    /**
     * @var BulkDataProcessor
     */
    private $bulkDataProcessor;

    public function __construct(BulkDataProcessor $processor)
    {
        parent::__construct(null);
        $this->bulkDataProcessor = $processor;
    }
}
