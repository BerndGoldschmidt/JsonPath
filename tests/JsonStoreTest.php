<?php

namespace tests;

use BerndGoldschmidt\JsonPath\JsonStore;
use PHPUnit\Framework\TestCase;

class JsonStoreTest extends TestCase
{
    /**
     * @var string
     */
    private $json;

    /**
     * @var JsonStore
     */
    private $jsonStore;

    public function setUp()
    {
        $this->json = '{
            "store": {
                "book": [
                    {
                        "category": "reference",
                        "author": "Nigel Rees",
                        "title": "Sayings of the Century",
                        "price": 8.95
                    },
                    {
                        "category": "fiction",
                        "author": "Evelyn Waugh",
                        "title": "Sword of Honour",
                        "price": 12.99,
                        "code": "02.01"
                    },
                    {
                        "category": "fiction",
                        "author": "Herman Melville",
                        "title": "Moby Dick",
                        "isbn": "0-553-21311-3",
                        "price": 8.99
                    },
                    {
                        "category": "fiction",
                        "author": "J. R. R. Tolkien",
                        "title": "The Lord of the Rings",
                        "isbn": "0-395-19395-8",
                        "price": 22.99
                    }
                ],
                "bicycle": {
                    "color": "red",
                    "price": 19.95
                }
            }
        }';

        $this->jsonStore = new JsonStore($this->json);
    }

    public function testSetData()
    {
        $this->assertEquals($this->jsonStore->toArray(), json_decode($this->json, true));

        $new = ['a' => 'b'];
        $this->jsonStore->setData($new);

        $this->assertEquals($this->jsonStore->toArray(), $new);
        $this->assertNotEquals($this->jsonStore->toArray(), json_decode($this->json, true));
    }

    /**
     * @throws \Exception
     */
    public function testGetAllByKey()
    {
        $data = $this->jsonStore->get("$..book.*.category");
        $expected = ["reference", "fiction", "fiction", "fiction"];
        $this->assertEquals($data, $expected);

        $data = $this->jsonStore->get("$..category");
        $this->assertEquals($data, $expected);
    }

    /**
     * @throws \Exception
     */
    public function testGetAllByKeyUnique()
    {
        $data = $this->jsonStore->get("$..book.*.category", true);
        $expected = ["reference", "fiction"];
        $this->assertEquals($data, $expected);

        $data = $this->jsonStore->get("$..category", true);
        $this->assertEquals($data, $expected);
    }

    /**
     * @throws \Exception
     */
    public function testGetAllByKeyFiltered()
    {
        $this->markTestSkipped('implementation of parameter values seems buggy');
        $data = $this->jsonStore->get("$..book[(@.code=='02.01')].category");

        $expected = ["fiction"];
        $this->assertEquals($data, $expected);
    }
} 