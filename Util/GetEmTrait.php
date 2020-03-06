<?php

namespace AE\ConnectBundle\Util;

use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Doctrine\RegistryInterface;

trait GetEmTrait
{
    /** @var EntityManager[] */
    private $ems;

    // We actually have to cache EMs as we find them and check if they are still open since these suckers can close at any
    // second and it takes 7 ms for us to get an EM from the proxy registry on average, which BUSTS us if we need to
    // loop over getting the correct entity manager.
    protected function getEm(string $className, RegistryInterface $registry): EntityManager
    {
        if (isset($this->ems[$className]) && $this->ems[$className]->isOpen()) {
            return $this->ems[$className];
        }
        $this->ems[$className] = $registry->getEntityManagerForClass($className);

        return $this->ems[$className];
    }
}
