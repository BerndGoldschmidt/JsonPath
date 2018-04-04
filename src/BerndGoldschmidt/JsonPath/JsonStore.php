<?php

namespace BerndGoldschmidt\JsonPath;

/* JSONStore 0.5 - JSON structure as storage
*
* Copyright (c) 2007 Stefan Goessner (goessner.net)
* Licensed under the MIT (MIT-LICENSE.txt) licence.
*
* Modified by Axel Anceau
* Modified by Bernd Goldschmidt <github@berndgoldschmidt.de>
*/

/**
 * Class JsonStore
 *
 * @package BerndGoldschmidt\JsonPath
 */
class JsonStore
{
    /**
     * @var array
     */
    private static $emptyArray = array();

    /**
     * @var array
     */
    protected $data;

    /**
     * @var JsonPath
     */
    protected $jsonPath;

    /**
     * @param string|array|\stdClass $data
     */
    public function __construct($data)
    {
        $this->jsonPath = new JsonPath();
        $this->setData($data);
    }

    /**
     * Sets JsonStore's manipulated data
     * @param string|array|\stdClass $data
     */
    public function setData($data)
    {
        if (is_string($data)) {
            $this->fillFromString($data);
        } else if (is_object($data)) {
            $this->fillFromObject($data);
        } else if ($data instanceof \Traversable) {
            $this->fillFromTraversable($data);
        } else if (is_array($data)) {
            $this->fillFromArray($data);
        } else {
            throw new \InvalidArgumentException(sprintf('Invalid data type in JsonStore. Expected object, array or string, got %s', gettype($data)));
        }
    }

    /**
     * JsonEncoded version of the object
     * @return string
     */
    public function toString(): string
    {
        return json_encode($this->data);
    }

    /**
     * JsonEncoded, pretty-printed version of the object
     * @return string
     */
    public function prettyPrint(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT);
    }

    /**
     * Returns the given json string to object
     * @return \stdClass
     */
    public function toObject(): \stdClass
    {
        return json_decode(json_encode($this->data));
    }

    /**
     * Returns array version of the object
     * 
     * @return array
     */
    public function toArray(): array
    {
        $array = $this->data;

        if (! is_array($array)) {
            $array = [];
        }

        return $array;
    }

    /**
     * Gets elements matching the given JsonPath expression
     *
     * @param string $jsonPath JsonPath expression
     * @param bool $unique Gets unique results or not
     * @return array
     * @throws \Exception
     */
    public function get(string $jsonPath, bool $unique = false): array
    {
        if ((($exprs = $this->normalizedFirst($jsonPath)) !== false) &&
            (is_array($exprs) || $exprs instanceof \Traversable)
        ) {
            $values = array();

            foreach ($exprs as $jsonPath) {
                $o =& $this->data;
                $keys = preg_split(
                    "/([\"'])?\]\[([\"'])?/",
                    preg_replace(array("/^\\$\[[\"']?/", "/[\"']?\]$/"), "", $jsonPath)
                );

                for ($i = 0; $i < count($keys); $i++) {
                    $o =& $o[$keys[$i]];
                }

                $values[] = & $o;
            }

            if (true === $unique) {
                if (!empty($values) && is_array($values[0])) {
                    array_walk($values, function(&$value) {
                        $value = json_encode($value);
                    });

                    $values = array_unique($values);
                    array_walk($values, function(&$value) {
                        $value = json_decode($value, true);
                    });

                    return array_values($values);
                }

                return array_unique($values);
            }

            return $values;
        }

        return self::$emptyArray;
    }

    /**
     * Sets the value for all elements matching the given JsonPath expression
     * @param string $jsonPath JsonPath expression
     * @param mixed $value Value to set
     * @return bool returns true if success
     * @throws \Exception
     */
    function set(string $jsonPath, $value): bool
    {
        $get = $this->get($jsonPath);
        if ($res =& $get) {
            foreach ($res as &$r) {
                $r = $value;
            }

            return true;
        }

        return false;
    }

    /**
     * Adds one or more elements matching the given json path expression
     * @param string $parentJsonString JsonPath expression to the parent
     * @param mixed $value Value to add
     * @param string $name Key name
     * @return bool returns true if success
     * @throws \Exception
     */
    public function add(string $parentJsonString, $value, string $name = '')
    {
        $get = $this->get($parentJsonString);
        if ($parents =& $get) {

            foreach ($parents as &$parent) {
                $parent = is_array($parent) ? $parent : array();

                if ($name != "") {
                    $parent[$name] = $value;
                } else {
                    $parent[] = $value;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Removes all elements matching the given jsonpath expression
     * @param string $jsonPath JsonPath expression
     * @return bool returns true if success
     * @throws \Exception
     */
    public function remove(string $jsonPath): bool
    {
        if ((($exprs = $this->normalizedFirst($jsonPath)) !== false) &&
            (is_array($exprs) || $exprs instanceof \Traversable)
        ) {
            foreach ($exprs as &$jsonPath) {
                $o =& $this->data;
                $keys = preg_split(
                    "/([\"'])?\]\[([\"'])?/",
                    preg_replace(array("/^\\$\[[\"']?/", "/[\"']?\]$/"), "", $jsonPath)
                );
                $i = 0;
                for ($i = 0; $i < count($keys) - 1; $i++) {
                    $o =& $o[$keys[$i]];
                }

                unset($o[$keys[$i]]);
            }

            return true;
        }

        return false;
    }

    /**
     * @param string $jsonPath
     * @return array|bool
     * @throws \Exception
     */
    protected function normalizedFirst(string $jsonPath)
    {
        if (empty($jsonPath)) {
            return false;
        }

        if (preg_match("/^\$(\[([0-9*]+|'[-a-zA-Z0-9_ ]+')\])*$/", $jsonPath)) {
            return $jsonPath;
        }

        return $this->getJsonPath()->jsonPath(
            $this->data,
            $jsonPath,
            ['resultType' => JsonPath::RESULT_TYPE_PATH]
        );

    }

    /**
     * @param $data
     */
    private function fillFromString(string $data): void
    {
        $this->data = json_decode($data, true);
    }

    /**
     * @param $data
     */
    private function fillFromObject($data): void
    {
        $this->data = json_decode(json_encode($data), true);
    }

    /**
     * @param $data
     */
    private function fillFromTraversable(\Traversable $data): void
    {
        $dataArray = [];

        foreach ($data as $key => $value) {
            $dataArray[$key] = $value;
        }

        $this->data = json_decode(json_encode($dataArray), true);
    }

    /**
     * @param $data
     */
    private function fillFromArray(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return JsonPath
     */
    public function getJsonPath(): JsonPath
    {
        return $this->jsonPath;
    }

    /**
     * @param JsonPath $jsonPath
     */
    public function setJsonPath(JsonPath $jsonPath): void
    {
        $this->jsonPath = $jsonPath;
    }

}