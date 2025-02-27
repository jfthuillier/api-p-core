<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Symfony\EventListener;

use ApiPlatform\Api\UriVariablesConverterInterface;
use ApiPlatform\Exception\InvalidIdentifierException;
use ApiPlatform\Exception\InvalidUriVariableException;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Serializer\SerializerContextBuilderInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\UriVariablesResolverTrait;
use ApiPlatform\Util\CloneTrait;
use ApiPlatform\Util\OperationRequestInitiatorTrait;
use ApiPlatform\Util\RequestAttributesExtractor;
use ApiPlatform\Util\RequestParser;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Retrieves data from the applicable data provider and sets it as a request parameter called data.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ReadListener
{
    use CloneTrait;
    use OperationRequestInitiatorTrait;
    use UriVariablesResolverTrait;

    public function __construct(
        private readonly ProviderInterface $provider,
        ?ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory = null,
        private readonly ?SerializerContextBuilderInterface $serializerContextBuilder = null,
        UriVariablesConverterInterface $uriVariablesConverter = null,
    ) {
        $this->resourceMetadataCollectionFactory = $resourceMetadataCollectionFactory;
        $this->uriVariablesConverter = $uriVariablesConverter;
    }

    /**
     * Calls the data provider and sets the data attribute.
     *
     * @throws NotFoundHttpException
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $operation = $this->initializeOperation($request);

        if (!($attributes = RequestAttributesExtractor::extractAttributes($request))) {
            return;
        }

        if (!$attributes['receive'] || !$operation || !($operation->canRead() ?? true) || (!$operation->getUriVariables() && !$request->isMethodSafe())) {
            return;
        }

        $context = ['operation' => $operation];

        if (null === $filters = $request->attributes->get('_api_filters')) {
            $queryString = RequestParser::getQueryString($request);
            $filters = $queryString ? RequestParser::parseRequestParams($queryString) : null;
        }

        if ($filters) {
            $context['filters'] = $filters;
        }

        if ($this->serializerContextBuilder) {
            // Builtin data providers are able to use the serialization context to automatically add join clauses
            $context += $normalizationContext = $this->serializerContextBuilder->createFromRequest($request, true, $attributes);
            $request->attributes->set('_api_normalization_context', $normalizationContext);
        }

        $parameters = $request->attributes->all();
        $resourceClass = $operation->getClass() ?? $attributes['resource_class'];
        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $resourceClass);
            $data = $this->provider->provide($operation, $uriVariables, $context);
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid identifier value or configuration.', $e);
        }

        if (
            null === $data &&
            (
                'PUT' !== $operation->getMethod() ||
                ($operation instanceof Put && !($operation->getAllowCreate() ?? false))
            )
        ) {
            throw new NotFoundHttpException('Not Found');
        }

        $request->attributes->set('data', $data);
        $request->attributes->set('previous_data', $this->clone($data));
    }
}
