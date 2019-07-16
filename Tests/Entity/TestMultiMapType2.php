<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 7/11/19
 * Time: 5:55 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\RecordType;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class TestMultiMapType1
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @RecordType("TestType2", connections={"default"})
 */
class TestMultiMapType2 extends BaseTestType
{
}
