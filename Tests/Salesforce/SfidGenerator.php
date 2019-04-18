<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 11:25 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

class SfidGenerator
{
    private const EIGHTEEN_DIGIT_CHARS  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const EIGHTEEN_DIGIT_VALUES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
    private const PERMITTED_CHARS       = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function generate($eighteenDigit = true)
    {
        $id = self::generateId();

        if ($eighteenDigit) {
            $id .= self::generateExtension($id);
        }

        return $id;
    }

    public static function convertFifteenToEighteen(string $id)
    {
        if (preg_match('/^[a-zA-Z0-9]{15}$/', $id) == false) {
            throw new \RuntimeException("ID provided is not a valid 15 digit Salesforce ID");
        }

        return $id.self::generateExtension($id);
    }

    private static function generateId()
    {
        $input_length  = strlen(self::PERMITTED_CHARS);
        $random_string = '';

        for ($i = 0; $i < 15; $i++) {
            if ($i > 4 && $i < 10) {
                $random_string .= '0';
                continue;
            }

            // Add some entropy
            $rand_input       = str_shuffle(self::PERMITTED_CHARS);
            $random_character = $rand_input[mt_rand(0, $input_length - 1)];
            $random_string    .= $random_character;
        }

        return $random_string;
    }

    private static function generateExtension(string $id)
    {
        return self::generateExtensionValue(substr($id, 0, 5))
            .self::generateExtensionValue(substr($id, 5, 5))
            .self::generateExtensionValue(substr($id, 10, 5));
    }

    private static function generateExtensionValue(string $part)
    {
        $length = strlen($part);
        $value  = 0;
        for ($i = $length - 1; $i >= 0; $i--) {
            $pos   = strpos(self::EIGHTEEN_DIGIT_CHARS, $part[$i]);
            $value += $pos ? pow(2, $i) : 0;
        }

        return self::EIGHTEEN_DIGIT_VALUES[$value];
    }
}
