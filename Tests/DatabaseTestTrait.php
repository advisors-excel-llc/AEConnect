<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/16/19
 * Time: 12:17 PM
 */

namespace AE\ConnectBundle\Tests;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Fidry\AliceDataFixtures\LoaderInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Finder\Finder;

trait DatabaseTestTrait
{
    /**
     * @var LoaderInterface
     */
    protected $loader;
    /**
     * @var RegistryInterface
     */
    protected $doctrine;

    /**
     * @var DbalConnectionDriver
     */
    protected $dbalConnectionDriver;

    /**
     * @var string
     */
    protected $projectDir;

    /**
     * @return LoaderInterface
     */
    public function getLoader(): LoaderInterface
    {
        return $this->loader;
    }

    /**
     * @param LoaderInterface $loader
     *
     * @return DatabaseTestTrait
     */
    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;

        return $this;
    }

    /**
     * @return RegistryInterface
     */
    public function getDoctrine(): RegistryInterface
    {
        return $this->doctrine;
    }

    /**
     * @param RegistryInterface $doctrine
     *
     * @return DatabaseTestTrait
     */
    public function setDoctrine(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;

        return $this;
    }

    /**
     * @return DbalConnectionDriver
     */
    public function getDbalConnectionDriver(): DbalConnectionDriver
    {
        return $this->dbalConnectionDriver;
    }

    /**
     * @param DbalConnectionDriver $dbalConnectionDriver
     *
     * @return DatabaseTestTrait
     */
    public function setDbalConnectionDriver(DbalConnectionDriver $dbalConnectionDriver)
    {
        $this->dbalConnectionDriver = $dbalConnectionDriver;

        return $this;
    }

    /**
     * @return string
     */
    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * @param string $projectDir
     *
     * @return DatabaseTestTrait
     */
    public function setProjectDir(string $projectDir)
    {
        $this->projectDir = $projectDir;

        return $this;
    }

    protected function createSchemas()
    {
        /** @var EntityManager $manager */
        $manager = $this->doctrine->getManager();
        $tool    = new SchemaTool($manager);
        $schemas = $this->loadSchemas();

        if (count($schemas) > 0) {
            $tool->updateSchema(
                array_map(
                    function ($item) use ($manager) {
                        return $manager->getClassMetadata($item);
                    },
                    $schemas
                ),
                true
            );
        }
    }

    protected function loadFixtures(array $fixtures)
    {
        $this->loader->load($fixtures);
    }

    /**
     * @return array
     */
    protected function loadSchemas(): array
    {
        $schemas = [];
        $baseNS  = "AE\ConnectBundle\Tests\Entity";
        $finder  = new Finder();
        $dir     = implode(DIRECTORY_SEPARATOR, [$this->getProjectDir(), "Tests", "Entity"]);

        /** @var \SplFileInfo $file */
        foreach ($finder->in($dir)->name('*.php')->files() as $file) {
            $name      = $file->getBasename('.php');
            $schemas[] = "$baseNS\\$name";
        }

        return $schemas;
    }

    protected function loadOrgConnections()
    {
        $this->loadFixtures([
            $this->getProjectDir().'/Tests/Resources/config/login_fixtures.yml'
        ]);

        /** @var DbalConnectionDriver $dbalDriver */
        $dbalDriver = $this->get(DbalConnectionDriver::class);
        $dbalDriver->loadConnections();
    }
}