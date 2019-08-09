<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/14/19
 * Time: 10:57 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Tests\KernelTestCase;
use Symfony\Bridge\Doctrine\RegistryInterface;

abstract class AbstractTransformerTest extends KernelTestCase
{
    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @var RegistryInterface
     */
    protected $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
        $this->registry          = $this->get(RegistryInterface::class);
    }

    abstract public function testOutbound();

    abstract public function testInbound();
}
