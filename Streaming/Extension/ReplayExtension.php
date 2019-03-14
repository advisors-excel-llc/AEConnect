<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/11/19
 * Time: 5:47 PM
 */

namespace AE\ConnectBundle\Streaming\Extension;

use AE\SalesforceRestSdk\Bayeux\Extension\CachedReplayExtension;
use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

class ReplayExtension extends CachedReplayExtension
{
    public function __construct(CacheProvider $cache, int $replayId = self::REPLAY_NEWEST)
    {
        parent::__construct(new DoctrineAdapter($cache), $replayId);
    }
}
