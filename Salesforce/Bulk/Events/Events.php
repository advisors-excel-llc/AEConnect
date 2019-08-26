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
    const SET_PROGRESS    = 'ae_connect.progress.set_progress';
    const SET_TOTALS      = 'ae_connect.progress.set_totals';
    const UPDATE_PROGRESS = 'ae_connect.progress.update_progress';
    const COMPLETE        = 'ae_connect.progress.complete';
}
