<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/13/19
 * Time: 10:49 AM
 */

namespace AE\ConnectBundle\AuthProvider;

use AE\SalesforceRestSdk\AuthProvider\AuthProviderInterface;
use AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException;

class NullProvider implements AuthProviderInterface
{
    /**
     * @inheritDoc
     */
    public function authorize()
    {
        throw new SessionExpiredOrInvalidException(
            "Null authentication provider in use. Update connection credentials and clear cache.",
            'INVALID_LOGIN_CREDENTIALS'
        );
    }

    /**
     * @return mixed|void
     * @throws SessionExpiredOrInvalidException
     */
    public function reauthorize()
    {
        return $this->authorize();
    }

    public function revoke()
    {
        // Nothing to revoke
    }

    public function getIdentity(): array
    {
        return [];
    }

    public function getToken(): ?string
    {
        return '';
    }

    public function getTokenType(): ?string
    {
        return 'NULL';
    }

    public function isAuthorized(): bool
    {
        return false;
    }

    public function getInstanceUrl(): ?string
    {
        return null;
    }

}
