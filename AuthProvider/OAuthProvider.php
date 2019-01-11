<?php

namespace AE\ConnectBundle\AuthProvider;

/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 2:59 PM
 */

use AE\SalesforceRestSdk\AuthProvider\OAuthProvider as BaseAuthProvider;
use Doctrine\Common\Cache\CacheProvider;

class OAuthProvider extends BaseAuthProvider
{
    /**
     * @var CacheProvider
     */
    private $cache;

    public function __construct(
        CacheProvider $cache,
        string $clientId,
        string $clientSecret,
        string $url,
        ?string $username,
        ?string $password,
        string $grantType = BaseAuthProvider::GRANT_PASSWORD,
        ?string $redirectUri = null,
        ?string $code = null
    ) {
        parent::__construct($clientId, $clientSecret, $url, $username, $password, $grantType, $redirectUri, $code);
        $this->cache = $cache;
    }

    public function authorize($reauth = false): string
    {
        $oldToken = null;
        $ref      = new \ReflectionClass(BaseAuthProvider::class);

        if (!$reauth && null === $this->token && $this->cache->contains($this->clientId)) {
            $values = $this->cache->fetch($this->clientId);
            $this->token = $oldToken = $values['token'];
            $this->tokenType = $values['tokenType'];
            $this->refreshToken = $values['refreshToken'];

            $instUrl = $ref->getProperty('instanceUrl');
            $instUrl->setAccessible(true);
            $instUrl->setValue($this, $values['instanceUrl']);

            $idUrl = $ref->getProperty('identityUrl');
            $idUrl->setAccessible(true);
            $idUrl->setValue($this, $values['identityUrl']);

            $prop        = $ref->getProperty('isAuthorized');
            $prop->setAccessible(true);
            $prop->setValue($this, true);
        }

        $header = parent::authorize($reauth);

        if ($this->token !== $oldToken) {
            $prop = $ref->getProperty('identityUrl');
            $prop->setAccessible(true);

            $this->cache->save(
                $this->clientId,
                [
                    'tokenType'    => $this->tokenType,
                    'token'        => $this->token,
                    'instanceUrl'  => $this->getInstanceUrl(),
                    'refreshToken' => $this->getRefreshToken(),
                    'identityUrl'  => $prop->getValue($this),
                ]
            );
        }

        return $header;
    }
}
