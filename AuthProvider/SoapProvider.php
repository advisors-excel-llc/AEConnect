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
        $ref = new \ReflectionClass(BaseAuthProvider::class);

        if (!$reauth && null === $this->token && $this->cache->contains($key)) {
            $values = $this->cache->fetch($key);

            $this->token = $oldToken = $values['token'];
            $this->tokenType = $values['tokenType'];

            $instUrl = $ref->getProperty('instanceUrl');
            $instUrl->setAccessible(true);
            $instUrl->setValue($this, $values['instanceUrl']);

            $idUrl = $ref->getProperty('identityUrl');
            $idUrl->setAccessible(true);
            $idUrl->setValue($this, $values['identityUrl']);

            $prop = $ref->getProperty('isAuthorized');
            $prop->setAccessible(true);
            $prop->setValue($this, true);
        }

        $header = parent::authorize($reauth);

        if ($this->token !== $oldToken) {
            $prop = $ref->getProperty('identityUrl');
            $prop->setAccessible(true);

            $this->cache->save(
                $key,
                [
                    'tokenType'    => $this->tokenType,
                    'token'        => $this->token,
                    'instanceUrl'  => $this->getInstanceUrl(),
                    'identityUrl'  => $prop->getValue($this),
                ]
            );
        }

        return $header;
    }
}
