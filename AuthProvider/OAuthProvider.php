<?php

namespace AE\ConnectBundle\AuthProvider;

/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 2:59 PM
 */

use AE\SalesforceRestSdk\AuthProvider\CachedOAuthProvider;
use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

class OAuthProvider extends CachedOAuthProvider
{
    public function __construct(
        CacheProvider $cache,
        string $clientId,
        string $clientSecret,
        string $url,
        ?string $username,
        ?string $password,
        string $grantType = self::GRANT_PASSWORD,
        ?string $redirectUri = null,
        ?string $code = null
    ) {
        parent::__construct(
            new DoctrineAdapter($cache),
            $clientId,
            $clientSecret,
            $url,
            $username,
            $password,
            $grantType,
            $redirectUri,
            $code
        );
    }
}
