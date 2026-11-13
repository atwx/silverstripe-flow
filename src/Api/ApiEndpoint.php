<?php

namespace Atwx\Flow\Api;

/**
 * A group of API operations, registered in ApiGateway.endpoints:
 *
 *   Atwx\Flow\Api\ApiGateway:
 *     endpoints:
 *       intake: Atwx\Flow\Intake\IntakeEndpoint
 */
interface ApiEndpoint
{
    /**
     * @return array<int, ApiOperation>
     */
    public function operations(): array;
}
