<?php


namespace TheCodingMachine\GraphQL\Controllers\Mappers;

use function array_keys;
use function filemtime;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use Mouf\Composer\ClassNameMapper;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;
use TheCodingMachine\ClassExplorer\Glob\GlobClassExplorer;
use TheCodingMachine\GraphQL\Controllers\AnnotationReader;
use TheCodingMachine\GraphQL\Controllers\Annotations\Factory;
use TheCodingMachine\GraphQL\Controllers\Annotations\Type;
use TheCodingMachine\GraphQL\Controllers\InputTypeGenerator;
use TheCodingMachine\GraphQL\Controllers\NamingStrategy;
use TheCodingMachine\GraphQL\Controllers\TypeGenerator;

/**
 * Scans all the classes in a given namespace of the main project (not the vendor directory).
 * Analyzes all classes and uses the @Type annotation to find the types automatically.
 *
 * Assumes that the container contains a class whose identifier is the same as the class name.
 */
final class GlobTypeMapper implements TypeMapperInterface
{
    /**
     * @var string
     */
    private $namespace;
    /**
     * @var AnnotationReader
     */
    private $annotationReader;
    /**
     * @var CacheInterface
     */
    private $cache;
    /**
     * @var int|null
     */
    private $globTtl;
    /**
     * @var array<string,string> Maps a domain class to the GraphQL type annotated class
     */
    private $mapClassToTypeArray = [];
    /**
     * @var array<string,string> Maps a GraphQL type name to the GraphQL type annotated class
     */
    private $mapNameToType = [];
    /**
     * @var array<string,string[]> Maps a domain class to the factory method that creates the input type in the form [classname, methodname]
     */
    private $mapClassToFactory = [];
    /**
     * @var array<string,string[]> Maps a GraphQL input type name to the factory method that creates the input type in the form [classname, methodname]
     */
    private $mapInputNameToFactory = [];
    /**
     * @var ContainerInterface
     */
    private $container;
    /**
     * @var TypeGenerator
     */
    private $typeGenerator;
    /**
     * @var int|null
     */
    private $mapTtl;
    /**
     * @var bool
     */
    private $fullMapComputed = false;
    /**
     * @var NamingStrategy
     */
    private $namingStrategy;
    /**
     * @var InputTypeGenerator
     */
    private $inputTypeGenerator;

    /**
     * @param string $namespace The namespace that contains the GraphQL types (they must have a `@Type` annotation)
     */
    public function __construct(string $namespace, TypeGenerator $typeGenerator, InputTypeGenerator $inputTypeGenerator, ContainerInterface $container, AnnotationReader $annotationReader, NamingStrategy $namingStrategy, CacheInterface $cache, ?int $globTtl = 2, ?int $mapTtl = null)
    {
        $this->namespace = $namespace;
        $this->typeGenerator = $typeGenerator;
        $this->container = $container;
        $this->annotationReader = $annotationReader;
        $this->namingStrategy = $namingStrategy;
        $this->cache = $cache;
        $this->globTtl = $globTtl;
        $this->mapTtl = $mapTtl;
        $this->inputTypeGenerator = $inputTypeGenerator;
    }

    /**
     * Returns an array of fully qualified class names.
     *
     * @return array<string,string>
     */
    private function getMap(): array
    {
        if ($this->fullMapComputed === false) {
            $namespace = str_replace('\\', '_', $this->namespace);
            $keyClassCache = 'globTypeMapper_'.$namespace;
            $keyNameCache = 'globTypeMapper_names_'.$namespace;
            $keyInputClassCache = 'globInputTypeMapper_'.$namespace;
            $keyInputNameCache = 'globInputTypeMapper_names_'.$namespace;
            $this->mapClassToTypeArray = $this->cache->get($keyClassCache);
            $this->mapNameToType = $this->cache->get($keyNameCache);
            $this->mapClassToFactory = $this->cache->get($keyInputClassCache);
            $this->mapInputNameToFactory = $this->cache->get($keyInputNameCache);
            if ($this->mapClassToTypeArray === null || $this->mapNameToType === null || $this->mapClassToFactory === null || $this->mapInputNameToFactory) {
                $this->buildMap();
                // This is a very short lived cache. Useful to avoid overloading a server in case of heavy load.
                // Defaults to 2 seconds.
                $this->cache->set($keyClassCache, $this->mapClassToTypeArray, $this->globTtl);
                $this->cache->set($keyNameCache, $this->mapNameToType, $this->globTtl);
                $this->cache->set($keyInputClassCache, $this->mapClassToFactory, $this->globTtl);
                $this->cache->set($keyInputNameCache, $this->mapInputNameToFactory, $this->globTtl);
            }
        }
        return $this->mapClassToTypeArray;
    }

    private function buildMap(): void
    {
        $explorer = new GlobClassExplorer($this->namespace, $this->cache, $this->globTtl, ClassNameMapper::createFromComposerFile(null, null, true));
        $classes = $explorer->getClasses();
        foreach ($classes as $className) {
            if (!\class_exists($className)) {
                continue;
            }
            $refClass = new \ReflectionClass($className);
            if (!$refClass->isInstantiable()) {
                continue;
            }

            $type = $this->annotationReader->getTypeAnnotation($refClass);

            if ($type !== null) {
                if (isset($this->mapClassToTypeArray[$type->getClass()])) {
                    /*if ($this->mapClassToTypeArray[$type->getClass()] === $className) {
                        // Already mapped. Let's continue
                        continue;
                    }*/
                    throw DuplicateMappingException::createForType($type->getClass(), $this->mapClassToTypeArray[$type->getClass()], $className);
                }
                $this->storeTypeInCache($className, $type, $refClass->getFileName());
            }

            foreach ($refClass->getMethods() as $method) {
                $factory = $this->annotationReader->getFactoryAnnotation($method);
                if ($factory !== null) {
                    [$inputName, $className] = $this->inputTypeGenerator->getInputTypeNameAndClassName($method);

                    if (isset($this->mapClassToFactory[$className])) {
                        throw DuplicateMappingException::createForFactory($className, $this->mapClassToFactory[$className][0], $this->mapClassToFactory[$className][1], $refClass->getName(), $method->name);
                    }
                    $this->storeInputTypeInCache($method, $inputName, $className, $refClass->getFileName());
                }
            }

        }
        $this->fullMapComputed = true;
    }

    /**
     * Stores in cache the mapping TypeClass <=> Object class <=> GraphQL type name.
     */
    private function storeTypeInCache(string $typeClassName, Type $type, string $typeFileName): void
    {
        $objectClassName = $type->getClass();
        $this->mapClassToTypeArray[$objectClassName] = $typeClassName;
        $this->cache->set('globTypeMapperByClass_'.str_replace('\\', '_', $objectClassName), [
            'filemtime' => filemtime($typeFileName),
            'fileName' => $typeFileName,
            'typeClass' => $typeClassName
        ], $this->mapTtl);
        $typeName = $this->namingStrategy->getOutputTypeName($typeClassName, $type);
        $this->mapNameToType[$typeName] = $typeClassName;
        $this->cache->set('globTypeMapperByName_'.$typeName, [
            'filemtime' => filemtime($typeFileName),
            'fileName' => $typeFileName,
            'typeClass' => $typeClassName
        ], $this->mapTtl);
    }

    /**
     * Stores in cache the mapping between InputType name <=> Object class
     */
    private function storeInputTypeInCache(ReflectionMethod $refMethod, string $inputName, string $className, string $fileName): void
    {
        $refArray = [$refMethod->getDeclaringClass()->getName(), $refMethod->getName()];
        $this->mapClassToFactory[$className] = $refArray;
        $this->cache->set('globInputTypeMapperByClass_'.str_replace('\\', '_', $className), [
            'filemtime' => filemtime($fileName),
            'fileName' => $fileName,
            'factory' => $refArray
        ], $this->mapTtl);
        $this->mapInputNameToFactory[$inputName] = $refArray;
        $this->cache->set('globInputTypeMapperByName_'.$inputName, [
            'filemtime' => filemtime($fileName),
            'fileName' => $fileName,
            'factory' => $refArray
        ], $this->mapTtl);
    }


    private function getTypeFromCacheByObjectClass(string $className): ?string
    {
        if (isset($this->mapClassToTypeArray[$className])) {
            return $this->mapClassToTypeArray[$className];
        }

        // Let's try from the cache
        $item = $this->cache->get('globTypeMapperByClass_'.str_replace('\\', '_', $className));
        if ($item !== null) {
            [
                'filemtime' => $filemtime,
                'fileName' => $typeFileName,
                'typeClass' => $typeClassName
            ] = $item;

            if ($filemtime === filemtime($typeFileName)) {
                $this->mapClassToTypeArray[$className] = $typeClassName;
                return $typeClassName;
            }
        }

        // cache miss
        return null;
    }

    private function getTypeFromCacheByGraphQLTypeName(string $graphqlTypeName): ?string
    {
        if (isset($this->mapNameToType[$graphqlTypeName])) {
            return $this->mapNameToType[$graphqlTypeName];
        }

        // Let's try from the cache
        $item = $this->cache->get('globTypeMapperByName_'.$graphqlTypeName);
        if ($item !== null) {
            [
                'filemtime' => $filemtime,
                'fileName' => $typeFileName,
                'typeClass' => $typeClassName
            ] = $item;

            if ($filemtime === filemtime($typeFileName)) {
                $this->mapNameToType[$graphqlTypeName] = $typeClassName;
                return $typeClassName;
            }
        }

        // cache miss
        return null;
    }

    /**
     * @return string[]|null A pointer to the factory [$className, $methodName] or null on cache miss
     */
    private function getFactoryFromCacheByObjectClass(string $className): ?array
    {
        if (isset($this->mapClassToFactory[$className])) {
            return $this->mapClassToFactory[$className];
        }

        // Let's try from the cache
        $item = $this->cache->get('globInputTypeMapperByClass_'.str_replace('\\', '_', $className));
        if ($item !== null) {
            [
                'filemtime' => $filemtime,
                'fileName' => $typeFileName,
                'factory' => $factory
            ] = $item;

            if ($filemtime === filemtime($typeFileName)) {
                $this->mapClassToFactory[$className] = $factory;
                return $factory;
            }
        }

        // cache miss
        return null;
    }

    /**
     * @return string[]|null A pointer to the factory [$className, $methodName] or null on cache miss
     */
    private function getFactoryFromCacheByGraphQLInputTypeName(string $graphqlTypeName): ?array
    {
        if (isset($this->mapInputNameToFactory[$graphqlTypeName])) {
            return $this->mapInputNameToFactory[$graphqlTypeName];
        }

        // Let's try from the cache
        $item = $this->cache->get('globInputTypeMapperByName_'.$graphqlTypeName);
        if ($item !== null) {
            [
                'filemtime' => $filemtime,
                'fileName' => $typeFileName,
                'factory' => $factory
            ] = $item;

            if ($filemtime === filemtime($typeFileName)) {
                $this->mapInputNameToFactory[$graphqlTypeName] = $factory;
                return $factory;
            }
        }

        // cache miss
        return null;
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL type.
     *
     * @param string $className
     * @return bool
     */
    public function canMapClassToType(string $className): bool
    {
        $typeClassName = $this->getTypeFromCacheByObjectClass($className);

        if ($typeClassName === null) {
            $this->getMap();
        }

        return isset($this->mapClassToTypeArray[$className]);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL type.
     *
     * @param string $className
     * @param RecursiveTypeMapperInterface $recursiveTypeMapper
     * @return ObjectType
     * @throws CannotMapTypeException
     */
    public function mapClassToType(string $className, RecursiveTypeMapperInterface $recursiveTypeMapper): ObjectType
    {
        $typeClassName = $this->getTypeFromCacheByObjectClass($className);

        if ($typeClassName === null) {
            $this->getMap();
        }

        if (!isset($this->mapClassToTypeArray[$className])) {
            throw CannotMapTypeException::createForType($className);
        }
        return $this->typeGenerator->mapAnnotatedObject($this->container->get($this->mapClassToTypeArray[$className]), $recursiveTypeMapper);
    }

    /**
     * Returns the list of classes that have matching input GraphQL types.
     *
     * @return string[]
     */
    public function getSupportedClasses(): array
    {
        return array_keys($this->getMap());
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL input type.
     *
     * @param string $className
     * @return bool
     */
    public function canMapClassToInputType(string $className): bool
    {
        $factory = $this->getFactoryFromCacheByObjectClass($className);

        if ($factory === null) {
            $this->getMap();
        }
        return isset($this->mapClassToFactory[$className]);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL input type.
     *
     * @param string $className
     * @param RecursiveTypeMapperInterface $recursiveTypeMapper
     * @return InputObjectType
     * @throws CannotMapTypeException
     */
    public function mapClassToInputType(string $className, RecursiveTypeMapperInterface $recursiveTypeMapper): InputObjectType
    {
        $factory = $this->getFactoryFromCacheByObjectClass($className);

        if ($factory === null) {
            $this->getMap();
        }

        if (!isset($this->mapClassToFactory[$className])) {
            throw CannotMapTypeException::createForInputType($className);
        }
        return $this->inputTypeGenerator->mapFactoryMethod($this->container->get($this->mapClassToFactory[$className][0]), $this->mapClassToFactory[$className][1], $recursiveTypeMapper);
    }

    /**
     * Returns a GraphQL type by name (can be either an input or output type)
     *
     * @param string $typeName The name of the GraphQL type
     * @param RecursiveTypeMapperInterface $recursiveTypeMapper
     * @return \GraphQL\Type\Definition\Type&(InputType|OutputType)
     * @throws CannotMapTypeException
     * @throws \ReflectionException
     */
    public function mapNameToType(string $typeName, RecursiveTypeMapperInterface $recursiveTypeMapper): \GraphQL\Type\Definition\Type
    {
        $typeClassName = $this->getTypeFromCacheByGraphQLTypeName($typeName);
        if ($typeClassName === null) {
            $factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
            if ($factory === null) {
                $this->getMap();
            }
        }

        if (isset($this->mapNameToType[$typeName])) {
            return $this->typeGenerator->mapAnnotatedObject($this->container->get($this->mapNameToType[$typeName]), $recursiveTypeMapper);
        }
        if (isset($this->mapInputNameToFactory[$typeName])) {
            $factory = $this->mapInputNameToFactory[$typeName];
            return $this->inputTypeGenerator->mapFactoryMethod($this->container->get($factory[0]), $factory[1], $recursiveTypeMapper);
        }

        throw CannotMapTypeException::createForName($typeName);
    }

    /**
     * Returns true if this type mapper can map the $typeName GraphQL name to a GraphQL type.
     *
     * @param string $typeName The name of the GraphQL type
     * @return bool
     */
    public function canMapNameToType(string $typeName): bool
    {
        $typeClassName = $this->getTypeFromCacheByGraphQLTypeName($typeName);

        if ($typeClassName !== null) {
            return true;
        }

        $factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
        if ($factory !== null) {
            return true;
        }

        $this->getMap();

        return isset($this->mapNameToType[$typeName]) || isset($this->mapInputNameToFactory[$typeName]);
    }
}
