<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite;

use GraphQL\Type\Definition\FieldDefinition;
use Mouf\Composer\ClassNameMapper;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Contracts\Cache\CacheInterface as CacheContractInterface;
use TheCodingMachine\ClassExplorer\Glob\GlobClassExplorer;
use function class_exists;
use function interface_exists;
use function str_replace;

/**
 * Scans all the classes in a given namespace of the main project (not the vendor directory).
 * Analyzes all classes and detects "Query" and "Mutation" annotations.
 *
 * Assumes that the container contains a class whose identifier is the same as the class name.
 */
final class GlobControllerQueryProvider implements QueryProviderInterface
{
    /** @var string */
    private $namespace;
    /** @var CacheInterface */
    private $cache;
    /** @var int|null */
    private $cacheTtl;
    /** @var array<string,string>|null */
    private $instancesList;
    /** @var ContainerInterface */
    private $container;
    /** @var AggregateControllerQueryProvider */
    private $aggregateControllerQueryProvider;
    /** @var FieldsBuilder */
    private $fieldsBuilder;
    /** @var bool */
    private $recursive;
    /** @var CacheContractInterface */
    private $cacheContract;

    /**
     * @param string             $namespace The namespace that contains the GraphQL types (they must have a `@Type` annotation)
     * @param ContainerInterface $container The container we will fetch controllers from.
     * @param bool               $recursive Whether subnamespaces of $namespace must be analyzed.
     */
    public function __construct(string $namespace, FieldsBuilder $fieldsBuilder, ContainerInterface $container, CacheInterface $cache, ?int $cacheTtl = null, bool $recursive = true)
    {
        $this->namespace     = $namespace;
        $this->container     = $container;
        $this->cache         = $cache;
        $this->cacheContract = new Psr16Adapter($this->cache, str_replace(['\\', '{', '}', '(', ')', '/', '@', ':'], '_', $namespace), $cacheTtl ?? 0);
        $this->cacheTtl      = $cacheTtl;
        $this->fieldsBuilder = $fieldsBuilder;
        $this->recursive     = $recursive;
    }

    private function getAggregateControllerQueryProvider(): AggregateControllerQueryProvider
    {
        if ($this->aggregateControllerQueryProvider === null) {
            $this->aggregateControllerQueryProvider = new AggregateControllerQueryProvider($this->getInstancesList(), $this->fieldsBuilder, $this->container);
        }

        return $this->aggregateControllerQueryProvider;
    }

    /**
     * Returns an array of fully qualified class names.
     *
     * @return string[]
     */
    private function getInstancesList(): array
    {
        if ($this->instancesList === null) {
            $this->instancesList = $this->cacheContract->get('globQueryProvider', function () {
                return $this->buildInstancesList();
            });
        }

        return $this->instancesList;
    }

    /**
     * @return string[]
     */
    private function buildInstancesList(): array
    {
        $explorer  = new GlobClassExplorer($this->namespace, $this->cache, $this->cacheTtl, ClassNameMapper::createFromComposerFile(null, null, true), $this->recursive);
        $classes   = $explorer->getClasses();
        $instances = [];
        foreach ($classes as $className) {
            if (! class_exists($className) && ! interface_exists($className)) {
                continue;
            }
            $refClass = new ReflectionClass($className);
            if (! $refClass->isInstantiable()) {
                continue;
            }
            if (! $this->container->has($className)) {
                continue;
            }

            $instances[] = $className;
        }

        return $instances;
    }

    /**
     * @return FieldDefinition[]
     */
    public function getQueries(): array
    {
        return $this->getAggregateControllerQueryProvider()->getQueries();
    }

    /**
     * @return FieldDefinition[]
     */
    public function getMutations(): array
    {
        return $this->getAggregateControllerQueryProvider()->getMutations();
    }
}
