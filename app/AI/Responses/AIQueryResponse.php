<?php

namespace App\AI\Responses;

class AIQueryResponse
{
    /**
     * @var string
     */
    public string $operation_type;

    /**
     * @var string
     */
    public string $entity_type;

    /**
     * @var \App\AI\Responses\QueryItem[]
     */
    public array $queries;

    /**
     * @var string
     */
    public string $explanation;

    public function __construct(string $operation_type, string $entity_type, array $queries, string $explanation)
    {
        $this->operation_type = $operation_type;
        $this->entity_type = $entity_type;
        $this->queries = $queries;
        $this->explanation = $explanation;
    }
}
