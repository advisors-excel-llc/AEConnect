<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 6:05 PM
 */

namespace AE\ConnectBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as TestCase;

class KernelTestCase extends TestCase
{
    protected function setUp(): void
    {
        static::bootKernel();
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
    }

    protected function get($serviceId)
    {
        return static::$container->get($serviceId);
    }

    protected function getProjectDir(): string
    {
        return static::$container->getParameter('kernel.project_dir');
    }
}
