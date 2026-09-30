<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * A product's features: what a subscription to it lets its customer use.
 */
class Products extends AbstractResource
{
    /**
     * POST /v1/products/{id}/features: gives the product a feature. Customers subscribed to it gain it at once; attaching it again changes nothing.
     *
     * @param array<string, mixed> $params featureId.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function attachFeature(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/products/' . self::pathId($id, 'A product id') . '/features', [], $params, $options);
    }

    /**
     * GET /v1/products/{id}/features: the product's features.
     *
     * @return array<string, mixed>
     */
    public function listFeatures(string $id): array
    {
        return $this->requestor->request('GET', '/v1/products/' . self::pathId($id, 'A product id') . '/features');
    }

    /**
     * DELETE /v1/products/{id}/features/{featureId}: takes the feature off the product. Customers subscribed to it
     * lose it unless another of their products gives it.
     *
     * @return array<string, mixed>
     */
    public function detachFeature(string $id, string $featureId): array
    {
        return $this->requestor->request('DELETE', '/v1/products/' . self::pathId($id, 'A product id') . '/features/' . self::pathId($featureId, 'A feature id'));
    }
}
