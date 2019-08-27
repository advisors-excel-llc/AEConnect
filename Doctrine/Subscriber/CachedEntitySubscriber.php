<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:44 PM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\Common\Cache\CacheProvider;

class CachedEntitySubscriber extends AbstractEntitySubscriber
{
    /**
     * @var CacheProvider
     */
    protected $cache;

    public function __construct(
        SalesforceConnector $connector,
        ConnectionManagerInterface $connectionManager,
        SObjectCompiler $compiler,
        CacheProvider $cache
    ) {
        parent::__construct($connector, $connectionManager, $compiler);
        $this->cache = $cache;
    }

    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate',
            'postRemove',
            'preFlush',
            'postFlush',
            'onClear',
        ];
    }

    protected function getUpserts(): array
    {
        if ($this->cache->contains('upserts')) {
            return $this->cache->fetch('upserts');
        }

        return [];
    }

    protected function getRemovals(): array
    {
        if ($this->cache->contains('removals')) {
            return $this->cache->fetch('removals');
        }

        return [];
    }

    protected function getProcessing(): array
    {
        if ($this->cache->contains('processing')) {
            return $this->cache->fetch('processing');
        }

        return [];
    }

    protected function saveUpserts(array $upserts)
    {
        $this->cache->save('upserts', $upserts);

        return $this;
    }

    protected function saveRemovals(array $removals)
    {
        $this->cache->save('removals', $removals);
    }

    protected function saveProcessing(array $processing)
    {
        $this->cache->save('processing', $processing);

        return $this;
    }

    protected function clearUpserts()
    {
        $this->cache->delete('upserts');

        return $this;
    }

    protected function clearRemovals()
    {
        $this->cache->delete('removals');

        return $this;
    }

    protected function clearProcessing()
    {
        $this->cache->delete('processing');

        return $this;
    }
}
