<?php

namespace App\AI\Responses;

class QueryItem
{
    /**
     * @var string
     */
    public string $type;

    /**
     * @var string
     */
    public string $query;

    /**
     * @var string
     */
    public string $purpose;

    public function __construct(string $type, string $query, string $purpose)
    {
        $this->type = $type;
        $this->query = $query;
        $this->purpose = $purpose;
    }
}
