<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/7/18
 * Time: 1:00 PM
 */

namespace AE\ConnectBundle\Connection\Dbal;

interface RefreshTokenCredentialsInterface extends AuthCredentialsInterface
{
    public function getToken(): ?string;
    public function getRefreshToken(): ?string;
    public function getRedirectUri(): string;
}
