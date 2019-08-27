<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/27/19
 * Time: 12:31 PM
 */

namespace AE\ConnectBundle\Tests\Doctrine\Subscriber;

use AE\ConnectBundle\Doctrine\Subscriber\EntitySubscriber;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;

class EntitySubscriberTest extends DatabaseTestCase
{
    public function testUpsertsRemovals()
    {
        /** @var EntitySubscriber $entitySubscriber */
        $entitySubscriber = $this->get(EntitySubscriber::class);
        $listener         = new EntitySubscriberTestListener($entitySubscriber);

        /** @var EventManager $eventManager */
        $eventManager = $this->get("doctrine.dbal.default_connection.event_manager");
        /** @var EntityManagerInterface $manager */
        $manager      = $this->doctrine->getManager();

        $eventManager->addEventListener(
            Events::prePersist,
            $listener
        );

        $eventManager->addEventListener(
            Events::postPersist,
            $listener
        );

        $eventManager->addEventListener(
            Events::preRemove,
            $listener
        );

        $eventManager->addEventListener(
            Events::postRemove,
            $listener
        );

        $account = new Account();
        $account->setName('test account');
        $account->setExtId(Uuid::uuid4());

        $manager->persist($account);
        $manager->flush();

        $this->assertEquals(
            [$account],
            array_values($listener->getUpserts())
        );

        $manager->remove($account);
        $manager->flush();

        $this->assertEquals(
            [$account],
            array_values($listener->getRemovals())
        );

        $this->assertNotEmpty($listener->getProcessing());
    }
}