<?php

namespace AE\ConnectBundle\Util;

use Doctrine\Persistence\ObjectManager;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;

trait GetEmTrait
{
    /** @var EntityManager[] */
    private $ems;
    private $keys;

    // We actually have to cache EMs as we find them and check if they are still open since these suckers can close at any
    // second and it takes 7 ms for us to get an EM from the proxy registry on average, which BUSTS us if we need to
    // loop over getting the correct entity manager.
    protected function getEm(string $className, ManagerRegistry $registry, $reOpen = false): EntityManager
    {
        if (isset($this->ems[$className]) && $this->ems[$className]->isOpen()) {
            return $this->ems[$className];
        }

        if ($reOpen && isset($this->ems[$className])) {
            $this->reopen($className, $registry);
        } else {
            $this->retrieveManagerFromRegistry($className, $registry);
        }

        return $this->ems[$className];
    }

    private function reopen(string $className, ManagerRegistry $registry)
    {
        $manager = $registry->resetManager($this->keys[$className]);

        while (method_exists($manager, 'getWrappedValueHolderValue')) {
            $manager = $manager->getWrappedValueHolderValue();
        }

        if (!$manager->isOpen()) {
            $registry->reset();
            $manager = $registry->getManager($this->keys[$className]);
            while (method_exists($manager, 'getWrappedValueHolderValue')) {
                $manager = $manager->getWrappedValueHolderValue();
            }
        }

        $this->setEms($this->keys[$className], $manager);
    }

    private function retrieveManagerFromRegistry(string $className, ManagerRegistry $registry)
    {
        $manager = $registry->getManagerForClass($className);
        foreach ($registry->getManagerNames() as $key => $emName) {
            if ($this->registry->getManager($key) === $manager) {
                $this->keys[$className] = $key;
            }
        }

        while (method_exists($manager, 'getWrappedValueHolderValue')) {
            $manager = $manager->getWrappedValueHolderValue();
        }
        $this->setEms($this->keys[$className], $manager);
    }

    /**
     * Given a key to an entity manager in registry, search through the classes that we have seen before and set
     * their remembered EM's to the new manager given.
     * @param $retrievedKey
     * @param $manager
     */
    private function setEms(string $retrievedKey, ObjectManager $manager)
    {
        foreach ($this->keys as $classMapName => $key) {
            if ($retrievedKey === $key) {
                $this->ems[$classMapName] = $manager;
            }
        }
    }
}
