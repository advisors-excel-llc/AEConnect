<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 6:07 PM
 */

namespace AE\ConnectBundle\Tests;

use AE\ConnectBundle\Driver\DbalConnectionDriver;

abstract class DatabaseTestCase extends KernelTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setLoader(static::$container->get('fidry_alice_data_fixtures.loader.doctrine'));
        $this->setDoctrine(static::$container->get('doctrine'));
        $this->setDbalConnectionDriver(static::$container->get(DbalConnectionDriver::class));
        $this->setProjectDir(static::$container->getParameter('kernel.project_dir'));
        $this->createSchemas();
    }
}
