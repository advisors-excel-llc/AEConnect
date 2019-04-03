<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/3/19
 * Time: 4:17 PM
 */
namespace AE\ConnectBundle\Tests\DependencyInjection;

use AE\ConnectBundle\Tests\KernelTestCase;

class AEConnectionExtensionTest extends KernelTestCase
{
    public function testConnections()
    {
        $this->assertTrue(static::$container->has("ae_connect.connection.default"));
    }

    public function testConnectionProxies()
    {
        $this->assertTrue(static::$container->has("ae_connect.connection_proxy.db_test"));
        $this->assertTrue(static::$container->has("ae_connect.connection_proxy.db_oauth_test"));
    }

    public function testCacheProviders()
    {
        $this->assertTrue(static::$container->has("ae_connect.connection.default.cache.auth_provider"));
        $this->assertTrue(static::$container->has("ae_connect.connection.default.cache.replay_extension"));
        $this->assertTrue(static::$container->has("ae_connect.connection.default.cache.metadata_provider"));
        $this->assertTrue(static::$container->has("doctrine_cache.providers.ae_connect_outbound_queue"));
        $this->assertTrue(static::$container->has("doctrine_cache.providers.ae_connect_polling"));
    }

    public function testChangeEvents()
    {
        $this->assertTrue(static::$container->has("ae_connect.connection.default.change_event.Account"));
    }

    public function testPolling()
    {
        $this->assertTrue(static::$container->hasParameter('ae_connect.poll_objects'));
        $polling = static::$container->getParameter('ae_connect.poll_objects');
        $this->assertArrayHasKey('default', $polling);
        $this->assertEquals(['UserRole'], $polling['default']);
    }

    public function testTopics()
    {
        $this->assertTrue(static::$container->has("ae_connect.connection.default.topic.TestObjects"));
    }
}
