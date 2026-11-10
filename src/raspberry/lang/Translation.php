<?php

declare(strict_types=1);

namespace raspberry\lang;

use function array_map;

class Translation{

    private string $key;

    /** @var list<string|int|float|Translation> */
    private array $params;

    /**
     * @param list<string|int|float|Translation> $params
     */
    public function __construct(string $key, array $params = []){
        $this->key = $key;
        $this->params = $params;
    }

    public function getKey() : string{
        return $this->key;
    }

    /**
     * @return list<string|int|float|Translation>
     */
    public function getParams() : array{
        return $this->params;
    }

    /**
     * @param list<string|int|float|Translation> $params
     *
     * @return list<string>
     */
    public static function toClientParams(array $params, Language $language) : array{
        return array_map(fn(string|int|float|Translation $param) : string => match(true){
            !$param instanceof self => (string) $param,
            $param->params === [] => "%" . $param->key,
            default => $language->translateObject($param)
        }, $params);
    }
}