<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/11/18
 * Time: 2:35 PM
 */

namespace AE\ConnectBundle\Tests\Util;

use AE\ConnectBundle\Util\ItemizedCollection;
use PHPUnit\Framework\TestCase;

class ItemizedCollectionTest extends TestCase
{
    public function testInstantiation()
    {
        $collection = new ItemizedCollection(
            [
                'item1' => 'test 1',
                'item2' => 'test 2',
            ]
        );

        $this->assertCount(2, $collection);
        $this->assertEquals('test 1', $collection->current());
        $collection->next();
        $this->assertEquals('test 2', $collection->current());
        $collection->first();
        $this->assertEquals('test 1', $collection->current());
        $collection->last();
        $this->assertEquals('test 2', $collection->current());


        foreach ($collection as $key => $value) {
            $this->assertContains($value, ['test 1', 'test 2']);
        }

        foreach ($collection->getKeys() as $key) {
            $this->assertContains($key, ['item1', 'item2']);
            $items = $collection->get($key);
            $this->assertContains($items[0], ['test 1', 'test 2']);
        }
    }

    public function testSlice()
    {
        // It is not recommended to use the same sub-keys within each itemized list
        // This test will demonstrate that you can, but also some of the pit falls of doing so
        // Such as using toArray() and foreach () which will merge the subarrays onto a single array
        // and compress the keys, allowing the latter value to win for a key
        $collection = new ItemizedCollection(
            [
                'org1' => [
                    'orange' => 'test 1',
                    'blue'   => 'test 2',
                    'green'  => 'test 3',
                    'purple' => 'test 4',
                ],
                'org2' => [
                    'orange' => 'test 5',
                    'blue'   => 'test 6',
                    'green'  => 'test 7',
                    'purple' => 'test 8',
                ],
            ]
        );

        $this->assertCount(8, $collection);
        $this->assertEquals(['org1', 'org2'], $collection->getKeys());
        // Even though there are 2 subkeys for 'orange', the first wins, another pitfall.
        $this->assertEquals('test 1', $collection->get('orange'));
        // You can avoid this pitfall by specifying the itemized key as the second parameter
        $this->assertEquals('test 5', $collection->get('orange', 'org2'));

        $split1 = $collection->slice(3, 3);
        $this->assertEquals(['purple' => 'test 4', 'orange' => 'test 5', 'blue' => 'test 6'], $split1->toArray());

        $split2 = $collection->slice(3, 3, 'org1');
        $this->assertEquals(['purple' => 'test 4'], $split2->toArray());

        $split3 = $collection->slice(-6, -3);
        $this->assertEquals(
            ['test 2', 'test 3', 'test 4', 'test 5', 'test 6'],
            $split3->getValues()
        );

        // Notice how blue is value 'test 6', this is due to key compression in the toArray() method
        $this->assertEquals(
            ['blue' => 'test 6', 'green' => 'test 3', 'purple' => 'test 4', 'orange' => 'test 5'],
            $split3->toArray()
        );

        $split4 = $collection->slice(-6, -3, 'org1');
        $this->assertEquals(['orange' => 'test 1'], $split4->toArray());

        // Splice test
        $split4 = $collection->splice(-6, -3);
        $this->assertEquals(
            ['test 2', 'test 3', 'test 4', 'test 5', 'test 6'],
            $split4->getValues()
        );

        $this->assertEquals(
            [
                'test 1',
                'test 7',
                'test 8',
            ],
            $collection->getValues()
        );
    }

    public function testForAll()
    {
        $arr = [
            'org1' => [
                'orange' => 'test 1',
                'blue'   => 'test 2',
                'green'  => 'test 3',
                'purple' => 'test 4',
            ],
            'org2' => [
                'orange' => 'test 5',
                'blue'   => 'test 6',
                'green'  => 'test 7',
                'purple' => 'test 8',
            ],
        ];

        $collection = new ItemizedCollection($arr);

        $collection->forAll(
            function ($key, $element, $item) use (&$arr) {
                $this->assertStringMatchesFormat('test %d', $element);
                unset($arr[$item][$key]);
                if (empty($arr[$item])) {
                    unset($arr[$item]);
                }
            }
        );

        $this->assertEmpty($arr);
    }

    public function testPartition()
    {
        $collection = new ItemizedCollection(
            [
                'org1' => [
                    'orange' => 'test 1',
                    'blue'   => 'test 2',
                    'green'  => 'test 3',
                    'purple' => 'test 4',
                ],
                'org2' => [
                    'orange' => 'test 5',
                    'blue'   => 'test 6',
                    'green'  => 'test 7',
                    'purple' => 'test 8',
                ],
            ]
        );

        $partitions = $collection->partition(
            function ($key, $element, $item) {
                return $item === 'org1' || $key === 'blue' || $element === 'test 8';
            }
        );

        $this->assertEquals(
            ['orange' => 'test 1', 'blue' => 'test 2', 'green' => 'test 3', 'purple' => 'test 4'],
            $partitions[0]->get('org1')
        );

        $this->assertEquals(
            ['blue' => 'test 6', 'purple' => 'test 8'],
            $partitions[0]->get('org2')
        );

        $this->assertEquals(
            ['orange' => 'test 5', 'green' => 'test 7'],
            $partitions[1]->get('org2')
        );

        $this->assertFalse($partitions[1]->containsKey('org1'));
    }

    public function testFilter()
    {
        $collection = new ItemizedCollection(
            [
                'org1' => [
                    'orange' => 'test 1',
                    'blue'   => 'test 2',
                    'green'  => 'test 3',
                    'purple' => 'test 4',
                ],
                'org2' => [
                    'orange' => 'test 5',
                    'blue'   => 'test 6',
                    'green'  => 'test 7',
                    'purple' => 'test 8',
                ],
            ]
        );

        $filtered = $collection->filter(
            function ($element, $key, $item) {
                return in_array(
                    $element,
                    [
                        'test 1',
                        'test 4',
                    ]
                )
                || ($item === 'org2' && in_array(
                    $key,
                    [
                        'orange',
                        'green',
                    ]
                ));
            },
            ARRAY_FILTER_USE_BOTH
        );

        $this->assertEquals(
            [
                'test 1',
                'test 4',
                'test 5',
                'test 7',
            ],
            $filtered->getValues()
        );

        $this->assertEquals(
            [
                'orange',
                'purple',
            ],
            $filtered->getKeys('org1')
        );

        $this->assertEquals(
            [
                'orange',
                'green',
            ],
            $filtered->getKeys('org2')
        );
    }
}
