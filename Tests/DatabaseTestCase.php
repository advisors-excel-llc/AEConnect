<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 6:07 PM
 */

namespace AE\ConnectBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Fidry\AliceDataFixtures\LoaderInterface;

abstract class DatabaseTestCase extends KernelTestCase
{
    /**
     * @var LoaderInterface
     */
    protected $loader;
    /**
     * @var Registry
     */
    protected $doctrine;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->loader = static::$container->get('fidry_alice_data_fixtures.loader.doctrine');
        $this->doctrine = static::$container->get('doctrine');
        $this->createSchemas();
    }

    protected function createSchemas()
    {
        /** @var EntityManager $manager */
        $manager = $this->doctrine->getManager();
        $tool    = new SchemaTool($manager);
        $schemas = $this->loadSchemas();

        if (count($schemas) > 0) {
            $tool->updateSchema(array_map(function ($item) use ($manager) {
                return $manager->getClassMetadata($item);
            }, $schemas), true);
        }
    }

    protected function loadFixtures(array $fixtures)
    {
        $this->loader->load($fixtures);
    }

    /**
     * @return array
     */
    abstract protected function loadSchemas(): array;
}
