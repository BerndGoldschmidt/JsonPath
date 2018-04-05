<?php

namespace BerndGoldschmidt\JsonPath;

/* JSONPath 0.8.3 - XPath for JSON
 *
 * Copyright (c) 2007 Stefan Goessner (goessner.net)
 * Licensed under the MIT (MIT-LICENSE.txt) licence.
 *
 * Modified by Axel Anceau
 * Modified 2018 by Bernd Goldschmidt <github@berndgoldschmidt.de>
 */

/**
 * Class JsonPath
 * @package BerndGoldschmidt\JsonPath
 */
class JsonPath
{
    /**
     * Result type value
     *
     * @var string
     */
    public const RESULT_TYPE_VALUE = 'VALUE';

    /**
     * Result type path
     *
     * @var string
     */
    public const RESULT_TYPE_PATH = 'PATH';

    /**
     * All valid result types
     *
     * @var string[]
     */
    public const VALID_RESULT_TYPES = [
        self::RESULT_TYPE_VALUE,
        self::RESULT_TYPE_PATH,
    ];

    /**
     * @var array
     */
    private $obj = [];

    /**
     * @var string
     */
    private $resultType = self::RESULT_TYPE_VALUE;

    /**
     * @var array
     */
    private $result = [];

    /**
     * @var array
     */
    private $keywords = [
        '=',
        ')',
        '!',
        '<',
        '>'
    ];

    /**
     * @param array $array
     * @param string $jsonPath
     * @param array $args
     * @return array|bool
     */
    public function jsonPath(array $array, string $jsonPath, array $args = [])
    {
        $this->resultType =
            is_array($args) && array_key_exists('resultType', $args)
                ? $args['resultType']
                : self::RESULT_TYPE_VALUE
        ;

        $this->obj = $array;
        if (! empty($jsonPath)
            && ! empty($array)
            && $this->isValidResultType($this->resultType)
        ) {
            $this->trace(
                preg_replace("/^\\$;/", "", $this->normalize($jsonPath)),
                $array,
                '$'
            );

            if (count($this->result) > 0) {
                return $this->result;
            }
        }

        return false;
    }

    /**
     * @param string $expression
     * @return string
     */
    public function normalize(string $expression): string
    {
        // Replaces filters by #0 #1...
        $expression = preg_replace_callback(
            ["/[\['](\??\(.*?\))[\]']/", "/\['(.*?)'\]/"],
            [$this, 'tempFilters'],
            $expression
        );

        // ; separator between each elements
        $expression = preg_replace(
            ["/'?\.'?|\['?/", "/;;;|;;/", "/;$|'?\]|'$/"],
            [";", ";..;", ""],
            $expression
        );

        // Restore filters
        $expression = preg_replace_callback("/#([0-9]+)/", [&$this, "restoreFilters"], $expression);
        $this->result = []; // result array was temporarily used as a buffer ..

        return $expression;
    }

    /**
     * Pushes the filter into the list
     *
     * @param string $filter
     * @return string
     */
    private function tempFilters(string $filter): string
    {
        $f = $filter[1];
        $elements = explode('\'', $f);

        // Hack to make "dot" works on filters
        for ($i = 0; $i < count($elements); $i++) {
            if ($i % 2 !== 0) {
                continue;
            }

            if ($i > 0 && substr($elements[$i - 1], 0, 1) == '\\') {
                continue;
            }

            $e = explode('.', $elements[$i]);
            $str = '';
            $first = true;

            foreach ($e as $subString) {
                if ($first) {
                    $str = $subString;
                    $first = false;
                    continue;
                }

                $end = null;
                if (false !== $pos = $this->strPosArray($subString, $this->keywords)) {
                    list($subString, $end) = [
                        substr($subString, 0, $pos),
                        substr($subString, $pos, strlen($subString))
                    ];
                }

                $str .= '[' . $subString . ']';
                if (null !== $end) {
                    $str .= $end;
                }
            }

            $elements[$i] = $str;
        }

        $addedElements = array_push($this->result, implode('\'', $elements)) - 1;
        return '[#' . $addedElements . ']';
    }

    /**
     * Get a filter back
     * @param string $filter
     * @return mixed
     */
    private function restoreFilters($filter)
    {
        return $this->result[$filter[1]];
    }

    /**
     * Builds json path expression
     * @param string $path
     * @return string
     */
    private function asPath(string $path)
    {
        $expr = explode(";", $path);
        $fullPath = "$";
        for ($i = 1, $n = count($expr); $i < $n; $i++) {
            $fullPath .= preg_match('/^[0-9*]+$/', $expr[$i])
                ? ('[' . $expr[$i] . ']')
                : ("['" . $expr[$i] . "']");
        }

        return $fullPath;
    }

    /**
     * @param string $p
     * @param string|array $v
     * @return bool
     */
    private function store(string $p, $v): bool
    {
        if ($p) {
            $pathOrValue =
                $this->resultType === self::RESULT_TYPE_PATH
                    ? $this->asPath($p)
                    : $v;

            array_push($this->result, $pathOrValue);
        }

        return !!$p;
    }

    /**
     * @param string $expr
     * @param array|string $val
     * @param string $path
     */
    private function trace(string $expr, $val, string $path): void
    {
        if ($expr === "") {
            $this->store($path, $val);
            return;
        }
        $x = explode(";", $expr);
        $loc = array_shift($x);
        $x = implode(";", $x);

        if (is_array($val) && array_key_exists($loc, $val)) {
            $this->trace($x, $val[$loc], $path . ";" . $loc);
        } else if ($loc == "*") {
            $this->walk($loc, $x, $val, $path, [&$this, "callbackLocatorAsterisk"]);
        } else if ($loc === "..") {
            $this->trace($x, $val, $path);
            $this->walk($loc, $x, $val, $path, [&$this, "callbackLocatorDoubleDot"]);
        } else if (preg_match("/^\(.*?\)$/", $loc)) { // [(expr)]
            $this->trace(
                $this->evalx(
                    $loc,
                    $val,
                    substr($path, strrpos($path, ";") + 1)
                ) . ";" . $x,
                $val,
                $path
            );
        } else if (preg_match("/^\?\(.*?\)$/", $loc)) { // [?(expr)]
            $this->walk($loc, $x, $val, $path, [&$this, "callbackLocationQuestionMarkPrefix"]);
        } else if (preg_match("/^(-?[0-9]*):(-?[0-9]*):?(-?[0-9]*)$/", $loc)) {
            // [start:end:step]  python slice syntax
            $this->slice($loc, $x, $val, $path);
        } else if (preg_match("/,/", $loc)) { // [name1,name2,...]
            for ($s = preg_split("/'?,'?/", $loc), $i = 0, $n = count($s); $i < $n; $i++)
                $this->trace($s[$i] . ";" . $x, $val, $path);
        }
    }

    /**
     * @param $m
     * @param $l
     * @param $x
     * @param $v
     * @param $p
     */
    private function callbackLocatorAsterisk($m, /** @noinspection PhpUnusedParameterInspection */ $l, $x, $v, $p)
    {
        $this->trace($m . ";" . $x, $v, $p);
    }

    /**
     * @param $m
     * @param $l
     * @param $x
     * @param $v
     * @param $p
     */
    private function callbackLocatorDoubleDot($m, /** @noinspection PhpUnusedParameterInspection */ $l, $x, $v, $p)
    {
        if (is_array($v[$m])) {
            $this->trace("..;" . $x, $v[$m], $p . ";" . $m);
        }
    }

    /**
     * @param $m
     * @param $l
     * @param $x
     * @param $v
     * @param $p
     */
    private function callbackLocationQuestionMarkPrefix($m, $l, $x, $v, $p)
    {
        if ($this->evalx(preg_replace("/^\?\((.*?)\)$/", "$1", $l), $v[$m])) {
            $this->trace($m . ";" . $x, $v, $p);
        }
    }

    /**
     * @param $loc
     * @param $expr
     * @param $val
     * @param $path
     * @param callable $f
     */
    private function walk($loc, $expr, $val, $path, callable $f)
    {
        foreach ($val as $m => $v) {
            call_user_func($f, $m, $loc, $expr, $val, $path);
        }
    }

    /**
     * @param $loc
     * @param $expr
     * @param $v
     * @param $path
     */
    private function slice($loc, $expr, $v, $path)
    {
        $s = explode(":", preg_replace("/^(-?[0-9]*):(-?[0-9]*):?(-?[0-9]*)$/", "$1:$2:$3", $loc));
        $length = count($v);
        $start = (int)$s[0] ? $s[0] : 0;
        $end = (int)$s[1] ? $s[1] : $length;
        $step = (int)$s[2] ? $s[2] : 1;
        $start = ($start < 0) ? max(0, $start + $length) : min($length, $start);
        $end = ($end < 0) ? max(0, $end + $length) : min($length, $end);

        for ($i = $start; $i < $end; $i += $step) {
            $this->trace($i . ";" . $expr, $v, $path);
        }
    }

    /**
     * @param string $x filter
     * @param array|string $v node
     * @param string $vname
     * @return string
     */
    private function evalx(
        $x,
        /** @noinspection PhpUnusedParameterInspection */ $v,
        /** @noinspection PhpUnusedParameterInspection */ $vname = null
    ) {
        $name = "";
        $expr = preg_replace(["/\\$/", "/@/"], ["\$this->obj", "\$v"], $x);
        $expr = preg_replace("#\[([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\]#", "['$1']", $expr);

        $res = eval("\$name = @$expr;");

        if ($res === false) {
            throw new \InvalidArgumentException('(jsonPath) SyntaxError: ' . $x . ' => ' . $expr);
        }

        return $name;
    }

    /**
     * @param array $array
     * @return \stdClass
     */
    private function toObject(array $array): \stdClass
    {
        $object = new \stdClass();

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->toObject($value);
            }

            $object->$key = $value;
        }

        return $object;
    }

    /**
     * Search one of the given needs in the array
     * @param string $haystack
     * @param array $needles
     * @return bool|string
     */
    private function strPosArray($haystack, array $needles)
    {
        $closer = 10000;
        foreach($needles as $needle) {
            if (false !== $pos = strpos($haystack, $needle)) {
                if ($pos < $closer) {
                    $closer = $pos;
                }
            }
        }

        return 10000 === $closer ? false : $closer;
    }

    /**
     * @param string $type
     * @return bool
     */
    public function isValidResultType(string $type): bool
    {
        return in_array($type, self::VALID_RESULT_TYPES);
    }
}