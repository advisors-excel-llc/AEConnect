<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/11/19
 * Time: 3:18 PM
 */

namespace AE\ConnectBundle\AuthProvider;

use AE\SalesforceRestSdk\AuthProvider\CachedSoapProvider;
use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

class SoapProvider extends CachedSoapProvider
{
    public function __construct(
        CacheProvider $cache,
        string $username,
        string $password,
        string $url = 'https://login.salesforce.com/'
    ) {
        parent::__construct(new DoctrineAdapter($cache), $username, $password, $url);
    }
}
