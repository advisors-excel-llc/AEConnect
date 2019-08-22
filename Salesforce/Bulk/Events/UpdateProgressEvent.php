<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 2:07 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk\Events;

class UpdateProgressEvent extends ProgressEvent
{
    /**
     * @var string
     */
    private $key;

    public function __construct(
        string $key,
        array $progress = [],
        array $totals = [],
        int $overallProgress = 0,
        int $overallTotal = 0
    ) {
        $this->key = $key;
        parent::__construct($progress, $totals, $overallProgress, $overallTotal);
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }
}
