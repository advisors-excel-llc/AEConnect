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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class SoapProvider extends BaseAuthProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
        $this->cache  = $cache;
        $this->logger = new NullLogger();
    }

    public function authorize($reauth = false)
    {
        $key      = "SOAP_{$this->username}";
        $oldToken = null;
        $ref      = new \ReflectionClass(BaseAuthProvider::class);

        if (!$reauth && null === $this->token && $this->cache->contains($key)) {
            $values = $this->cache->fetch($key);

            $this->token     = $oldToken = $values['token'];
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

        try {
            $header = parent::authorize($reauth);

            if ($this->token !== $oldToken) {
                $prop = $ref->getProperty('identityUrl');
                $prop->setAccessible(true);

                $this->cache->save(
                    $key,
                    [
                        'tokenType'   => $this->tokenType,
                        'token'       => $this->token,
                        'instanceUrl' => $this->getInstanceUrl(),
                        'identityUrl' => $prop->getValue($this),
                    ]
                );
            }

            return $header;
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
            $this->revoke();

            return '';
        }
    }

    public function revoke(): void
    {
        $key = "SOAP_{$this->username}";

        if ($this->cache->contains($key)) {
            $this->cache->delete($key);
        }

        parent::revoke();
    }
}
