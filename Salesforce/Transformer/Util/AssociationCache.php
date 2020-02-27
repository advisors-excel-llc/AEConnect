<?php

namespace AE\ConnectBundle\Salesforce\Transformer\Util;

use Doctrine\Common\Cache\ArrayCache;

class AssociationCache
{
    private $cache;

    public function __construct()
    {
        $this->cache = new ArrayCache();
        $this->cache->setNamespace('associations');
    }

    public function fetch($id) {
        return $this->cache->fetch($id);
    }

    public function contains($id)
    {
        return $this->cache->contains($id);
    }

    public function save($data)
    {
        foreach ($data as $class => $rows) {
            foreach ($rows as $row) {
                $this->cache->save($row['sfid'], [$class, $row['id']], 100000);
            }
        }
    }
}
