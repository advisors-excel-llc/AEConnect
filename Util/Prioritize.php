<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 2:34 PM
 */

namespace AE\ConnectBundle\Util;

interface Prioritize
{
    public function priority($element): ?int;
    public function prioritize($element, int $priority);
}
