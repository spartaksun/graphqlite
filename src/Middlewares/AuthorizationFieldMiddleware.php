<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Middlewares;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\OutputType;
use TheCodingMachine\GraphQLite\Annotations\Exceptions\IncompatibleAnnotationsException;
use TheCodingMachine\GraphQLite\Annotations\FailWith;
use TheCodingMachine\GraphQLite\Annotations\HideIfUnauthorized;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\QueryField;
use TheCodingMachine\GraphQLite\QueryFieldDescriptor;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use Webmozart\Assert\Assert;

/**
 * Middleware in charge of managing "Logged" and "Right" annotations.
 */
class AuthorizationFieldMiddleware implements FieldMiddlewareInterface
{
    /** @var AuthenticationServiceInterface */
    private $authenticationService;
    /** @var AuthorizationServiceInterface */
    private $authorizationService;

    public function __construct(
        AuthenticationServiceInterface $authenticationService,
        AuthorizationServiceInterface $authorizationService
    ) {
        $this->authenticationService = $authenticationService;
        $this->authorizationService = $authorizationService;
    }

    public function process(QueryFieldDescriptor $queryFieldDescriptor, FieldHandlerInterface $fieldHandler): ?FieldDefinition
    {
        $annotations = $queryFieldDescriptor->getMiddlewareAnnotations();

        /**
         * @var Logged $loggedAnnotation
         */
        $loggedAnnotation = $annotations->getAnnotationByType(Logged::class);
        /**
         * @var Right $rightAnnotation
         */
        $rightAnnotation = $annotations->getAnnotationByType(Right::class);

        /**
         * @var FailWith|null $failWith
         */
        $failWith = $annotations->getAnnotationByType(FailWith::class);

        // If the failWith value is null and the return type is non nullable, we must set it to nullable.
        $type = $queryFieldDescriptor->getType();
        if ($failWith !== null && $type instanceof NonNull && $failWith->getValue() === null) {
            $type = $type->getWrappedType();
            Assert::isInstanceOf($type, OutputType::class);
            $queryFieldDescriptor->setType($type);
        }

        if ($this->isAuthorized($loggedAnnotation, $rightAnnotation)) {
            return $fieldHandler->handle($queryFieldDescriptor);
        }

        /**
         * @var HideIfUnauthorized|null $hideIfUnauthorized
         */
        $hideIfUnauthorized = $annotations->getAnnotationByType(HideIfUnauthorized::class);

        if ($failWith !== null && $hideIfUnauthorized !== null) {
            throw IncompatibleAnnotationsException::cannotUseFailWithAndHide();
        }

        if ($failWith !== null) {
            $failWithValue = $failWith->getValue();

            return QueryField::alwaysReturn($queryFieldDescriptor, $failWithValue);
        }

        if ($hideIfUnauthorized !== null) {
            return null;
        }

        return QueryField::unauthorizedError($queryFieldDescriptor, $loggedAnnotation !== null && ! $this->authenticationService->isLogged());
    }

    /**
     * Checks the @Logged and @Right annotations.
     */
    private function isAuthorized(?Logged $loggedAnnotation, ?Right $rightAnnotation): bool
    {
        if ($loggedAnnotation !== null && ! $this->authenticationService->isLogged()) {
            return false;
        }

        return $rightAnnotation === null || $this->authorizationService->isAllowed($rightAnnotation->getName());
    }
}
