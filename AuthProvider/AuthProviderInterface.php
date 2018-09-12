<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 3:28 PM
 */

namespace AE\ConnectBundle\AuthProvider;

interface AuthProviderInterface
{
    public function authorize();
    public function reauthorize();
    public function revoke();
}
