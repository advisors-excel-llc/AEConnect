<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 4:00 PM
 */

namespace AE\ConnectBundle\Tests\AuthProvider;

use AE\ConnectBundle\AuthProvider\OAuthProvider;
use AE\ConnectBundle\AuthProvider\SoapProvider;
use AE\ConnectBundle\Tests\KernelTestCase;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;

class SoapProviderTest extends KernelTestCase
{
    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var SoapProvider
     */
    private $authProvider;

    protected function setUp()
    {
        parent::setUp();
        $this->cache        = $this->get("ae_connect.connection.default.cache.auth_provider");
        $authProvider = $this->get("ae_connect.connection.default.auth_provider");

        if ($authProvider instanceof SoapProvider) {
            $this->authProvider = $authProvider;
        } else {
            $ref = new \ReflectionClass(OAuthProvider::class);
            $client = $ref->getProperty('httpClient');
            $client->setAccessible(true);
            $username = $ref->getProperty('username');
            $username->setAccessible(true);
            $password = $ref->getProperty('password');
            $password->setAccessible(true);

            /** @var Client $httpClient */
            $httpClient = $client->getValue($authProvider);

            $this->authProvider = new SoapProvider(
                $this->cache,
                $username->getValue($authProvider),
                $password->getValue($authProvider),
                $httpClient->getConfig('base_uri')
            );
        }
    }

    public function testAuthentication()
    {
        $ref = new \ReflectionClass(SoapProvider::class);
        $username = $ref->getProperty('username');
        $username->setAccessible(true);
        $password = $ref->getProperty('password');
        $password->setAccessible(true);
        $client = $ref->getProperty('httpClient');
        $client->setAccessible(true);

        /** @var Client $httpClient */
        $httpClient = $client->getValue($this->authProvider);

        $this->authProvider->authorize();
        $token = $this->authProvider->getToken();

        $this->assertTrue($this->cache->contains('SOAP_'.$username->getValue($this->authProvider)));
        $this->assertEquals($token, $this->cache->fetch('SOAP_'.$username->getValue($this->authProvider)));

        $authProvider = new SoapProvider(
            $this->cache,
            $username->getValue($this->authProvider),
            $password->getValue($this->authProvider),
            $httpClient->getConfig('base_uri')
        );

        $authProvider->authorize();
        $this->assertEquals($token, $authProvider->getToken());
    }
}
