<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 9:38 AM
 */
namespace AE\ConnectBundle\Connection\Dbal;

interface AuthCredentialsInterface extends ConnectionEntityInterface
{
    public const SOAP = 'SOAP';
    public const OAUTH = 'OAUTH';

    /** getType should return "SOAP" or "OAUTH" or "OATH" */
    public function getType(): string;
    public function getUsername(): string;
    public function getPassword(): ?string;
    public function getClientKey(): ?string;
    public function getClientSecret(): ?string;
    public function getLoginUrl(): string;
    public function isActive(): bool;
    public function setActive(bool $active);
}
