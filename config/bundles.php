<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 5:23 PM
 */

return [
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class                       => ['all' => true],
    Doctrine\Bundle\DoctrineCacheBundle\DoctrineCacheBundle::class             => ['all' => true],
    JMS\SerializerBundle\JMSSerializerBundle::class                            => ['all' => true],
    Nelmio\Alice\Bridge\Symfony\NelmioAliceBundle::class                       => ['test' => true],
    Fidry\AliceDataFixtures\Bridge\Symfony\FidryAliceDataFixturesBundle::class => ['test' => true],
    AE\ConnectBundle\AEConnectBundle::class                                    => ['all' => true],
];
