<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 3:18 PM
 */

namespace AE\ConnectBundle\AuthProvider;

use AE\SalesforceRestSdk\AuthProvider\SoapProvider as BaseAuthProvider;
use Doctrine\Common\Cache\CacheProvider;

class SoapProvider extends BaseAuthProvider
{
    /**
     * @var CacheProvider
     */
    private $cache;

    public function __construct(
        CacheProvider $cache,
        string $username,
        string $password,
        string $url = 'https://login.salesforce.com/'
    ) {
        parent::__construct($username, $password, $url);
        $this->cache = $cache;
    }

    public function authorize($reauth = false)
    {
        $key = "SOAP_{$this->username}";
        $oldToken = null;

        if (!$reauth && null === $this->token && $this->cache->contains($key)) {
            $this->token = $oldToken = $this->cache->fetch($key);
            $ref = new \ReflectionClass(BaseAuthProvider::class);
            $prop = $ref->getProperty('isAuthorized');
            $prop->setAccessible(true);
            $prop->setValue($this, true);
        }

        $header = parent::authorize($reauth);

        if ($this->token !== $oldToken) {
            $this->cache->save($key, $this->token);
        }

        return $header;
    }
}
