<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/7/18
 * Time: 12:57 PM
 */

namespace AE\ConnectBundle\Sdk\AuthProvider;

use AE\ConnectBundle\AuthProvider\OAuthProvider;

class MutableOAuthProvider extends OAuthProvider
{
    public function setToken(string $token, string $tokenType = 'Bearer')
    {
        $this->token     = $token;
        $this->tokenType = $tokenType;

        return $this;
    }

    public function setRefreshToken(string $refreshToken)
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getIdentity(): array
    {
        $identity = parent::getIdentity();

        // Could be empty because the identityUrl is null, populate it
        if (empty($identity)) {
            $this->reauthorize();
            $identity = parent::getIdentity();
        }

        return $identity;
    }
}
