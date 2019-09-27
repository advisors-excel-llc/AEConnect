<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 11:55 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk\Events;

class Events
{
    const SET_PROGRESS     = SetProgressEvent::class;
    const SET_TOTALS       = SetTotalsEvent::class;
    const UPDATE_TOTAL     = UpdateTotalEvent::class;
    const UPDATE_PROGRESS  = UpdateProgressEvent::class;
    const SECTION_COMPLETE = CompleteSectionEvent::class;
    const COMPLETE         = CompleteEvent::class;
}
