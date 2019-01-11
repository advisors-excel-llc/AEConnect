<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 3:26 PM
 */

namespace AE\ConnectBundle\Tests\AuthProvider;

use AE\ConnectBundle\AuthProvider\OAuthProvider;
use AE\ConnectBundle\Tests\KernelTestCase;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;

class OAuthProviderTest extends KernelTestCase
{
    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var OAuthProvider
     */
    private $authProvider;

    protected function setUp()
    {
        parent::setUp();
        $this->cache        = $this->get("ae_connect.connection.default.cache.auth_provider");
        $this->authProvider = $this->get("ae_connect.connection.default.auth_provider");
    }

    public function testAuthorization()
    {
        $ref      = new \ReflectionClass(OAuthProvider::class);
        $clientId = $ref->getProperty('clientId');
        $clientId->setAccessible(true);
        $clientSecret = $ref->getProperty('clientSecret');
        $clientSecret->setAccessible(true);
        $client = $ref->getProperty('httpClient');
        $client->setAccessible(true);
        $username = $ref->getProperty('username');
        $username->setAccessible(true);
        $password = $ref->getProperty('password');
        $password->setAccessible(true);
        $grantType = $ref->getProperty('grantType');
        $grantType->setAccessible(true);
        $redirectUri = $ref->getProperty('redirectUri');
        $redirectUri->setAccessible(true);

        /** @var Client $httpClient */
        $httpClient = $client->getValue($this->authProvider);

        $this->authProvider->authorize();

        $authProvider = new OAuthProvider(
            $this->cache,
            $clientId->getValue($this->authProvider),
            $clientSecret->getValue($this->authProvider),
            $httpClient->getConfig('base_uri'),
            $username->getValue($this->authProvider),
            $password->getValue($this->authProvider),
            $grantType->getValue($this->authProvider),
            $redirectUri->getValue($this->authProvider)
        );

        $token = $this->authProvider->getToken();
        $this->assertTrue($this->cache->contains($clientId->getValue($this->authProvider)));
        $values = $this->cache->fetch($clientId->getValue($this->authProvider));

        $this->assertEquals($token, $values['token']);
        $this->assertNotNull($values['instanceUrl']);
        $this->assertNotNull($values['identityUrl']);

        $authProvider->authorize();
        $this->assertEquals($token, $authProvider->getToken());
    }
}
