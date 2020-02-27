<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 12:05 PM
 */

namespace AE\ConnectBundle\Driver;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\RecordType;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\RecordTypeMetadata;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;

class AnnotationDriver
{
    /**
     * The AnnotationReader.
     *
     * @var Reader
     */
    protected $reader;

    /**
     * The paths where to look for mapping files.
     *
     * @var array
     */
    protected $paths = [];
    /**
     * The paths excluded from path where to look for mapping files.
     *
     * @var array
     */
    protected $excludePaths = [];
    /**
     * The file extension of mapping documents.
     *
     * @var string
     */
    protected $fileExtension = '.php';
    /**
     * Cache for AnnotationDriver#getAllClassNames().
     *
     * @var array|null
     */
    protected $classNames;

    /**
     * @var array
     */
    protected $entityAnnotationClasses
        = [
            SObjectType::class,
            Field::class,
            ExternalId::class,
            SalesforceId::class,
            RecordType::class,
            Connection::class,
        ];

    public function __construct(Reader $reader, $paths = null)
    {
        $this->reader = $reader;

        foreach ($this->entityAnnotationClasses as $class) {
            AnnotationRegistry::loadAnnotationClass($class);
        }

        if (null !== $paths) {
            $this->addPaths((array)$paths);
        }
    }


    /**
     * @param string $className
     * @param Metadata $metadata
     * @param bool $isDefault
     *
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    public function loadMetadataForClass($className, Metadata $metadata, bool $isDefault = false)
    {
        $class             = new \ReflectionClass($className);
        $sourceAnnotations = $this->getClassAnnotations(
            $class,
            [SObjectType::class, RecordType::class, SalesforceId::class]
        );
        /** @var SObjectType|RecordType $sourceAnnotation */
        foreach ($sourceAnnotations as $sourceAnnotation) {
            if ($this->hasConnectionName($sourceAnnotation, $metadata->getConnectionName(), $isDefault)) {
                if ($sourceAnnotation instanceof RecordType) {
                    $metadata->addFieldMetadata(new RecordTypeMetadata($metadata, $sourceAnnotation->getName()));
                    continue;
                } elseif ($sourceAnnotation instanceof SObjectType) {
                    $metadata->setSObjectType($sourceAnnotation->getName());
                }

                $metadata->setClassName($className);
                $metadata->setClassAnnotation(get_class($sourceAnnotation));

                $properties = $this->getAnnotatedProperties(
                    $class,
                    $metadata->getConnectionName(),
                    $isDefault,
                    [
                        Field::class,
                        SalesforceId::class,
                        RecordType::class,
                        Connection::class,
                    ]
                );

                foreach ($properties as $name => $item) {
                    switch (get_class($item)) {
                        case RecordType::class:
                            $name = array_search($item, $properties, true);

                            if (false === $name) {
                                return false;
                            }

                            $meta = $metadata->getRecordType();
                            if (null === $meta) {
                                // If name is set, it always wins
                                $rtName = $item->getName();
                                $metadata->addFieldMetadata(
                                    ($meta = new RecordTypeMetadata($metadata, $rtName, $name))
                                );
                            }

                            $meta->setProperty($name);
                            break;
                        case Connection::class:
                            $metadata->setConnectionNameField(
                                new FieldMetadata(
                                    $metadata,
                                    $name,
                                    null
                                )
                            );
                            break;

                        case Field::class:
                            $metadata->addFieldMetadata(
                                new FieldMetadata(
                                    $metadata,
                                    $name,
                                    $item->getName(),
                                    $item->getTransformer()
                                )
                            );
                            break;

                        case SalesforceId::class:
                            $metadata->addFieldMetadata(
                                new FieldMetadata(
                                    $metadata,
                                    $name,
                                    'Id',
                                    'sfid'
                                )
                            );
                            break;
                    }
                }

                $methods = $this->getAnnotatedMethods(
                    $class,
                    $metadata->getConnectionName(),
                    $isDefault,
                    [
                        Field::class,
                        RecordType::class,
                        Connection::class,
                    ]
                );

                /**
                 * @var string $name
                 * @var RecordType|Field $method
                 */
                foreach ($methods as $name => $method) {
                    $propName = strtolower(substr($name, 3, 1)).substr($name, 4);
                    $prefix   = substr($name, 0, 3);

                    if (! in_array($prefix, ['set', 'get'])) {
                        continue;
                    }

                    if ($method instanceof RecordType) {
                        // If name is set, it always wins
                        $rtName = $method->getName();
                        $meta   = $metadata->getRecordType();
                        if (null === $meta) {
                            $metadata->addFieldMetadata(
                                ($meta = new RecordTypeMetadata($metadata, $rtName, $propName))
                            );
                        }

                        if (substr($name, 0, 3) === "get") {
                            $meta->setGetter($name);
                        } elseif (substr($name, 0, 3) === "set") {
                            $meta->setSetter($name);
                        }
                    } elseif ($method instanceof Connection) {
                        $meta = $metadata->getMetadataForProperty($propName);

                        if (null === $meta) {
                            $metadata->setConnectionNameField(
                                ($meta = new FieldMetadata($metadata, $propName))
                            );
                        }
                    } else {
                        $meta = $metadata->getMetadataForProperty($propName);

                        if (null === $meta) {
                            $field = null;
                            if (! ($method instanceof Connection)) {
                                $field = $method->getName();
                            }

                            if ($method instanceof Field) {
                                $transformer = $method->getTransformer();
                                $metadata->addFieldMetadata(
                                    ($meta = new FieldMetadata($metadata, $propName, $field, $transformer))
                                );
                            } else {
                                $metadata->addFieldMetadata(
                                    ($meta = new FieldMetadata($metadata, $propName, $field))
                                );
                            }
                        }
                    }

                    if (isset($meta)) {
                        if ($prefix === "get") {
                            $meta->setGetter($name);
                        } elseif ($prefix === "set") {
                            $meta->setSetter($name);
                        }
                    }
                }

                /** @var ExternalId[] $extIds */
                $extIds = $this->getAnnotatedProperties(
                    $class,
                    $metadata->getConnectionName(),
                    $isDefault,
                    [ExternalId::class]
                );

                if (! empty($extIds)) {
                    foreach (array_keys($extIds) as $prop) {
                        if (null !== ($meta = $metadata->getMetadataForProperty($prop))) {
                            $meta->setIsIdentifier(true);
                        }
                    }
                }
            }
        }
    }

    private function getClassAnnotations(\ReflectionClass $class, array $annotationNames): array
    {
        $annotations = $this->reader->getClassAnnotations($class);
        $found       = [];

        foreach ($annotations as $annotation) {
            if (in_array(get_class($annotation), $annotationNames)) {
                $found[] = $annotation;
            }
        }

        return $found;
    }

    /**
     * @param \ReflectionClass $class
     * @param string $connectionName
     * @param bool $isDefault
     * @param array $annotations
     *
     * @return array
     */
    private function getAnnotatedProperties(
        \ReflectionClass $class,
        string $connectionName,
        bool $isDefault,
        array $annotations
    ) {
        $properties = [];
        foreach ($class->getProperties() as $property) {
            foreach ($this->reader->getPropertyAnnotations($property) as $propAnnot) {
                if (! in_array(get_class($propAnnot), $annotations)) {
                    continue;
                }

                if ($this->usesConnections($propAnnot)) {
                    if ($this->hasConnectionName($propAnnot, $connectionName, $isDefault)) {
                        $properties[$property->getName()] = $propAnnot;
                    }
                } elseif (null !== $propAnnot) {
                    $properties[$property->getName()] = $propAnnot;
                }
            }
        }

        return $properties;
    }

    /**
     * @param \ReflectionClass $class
     * @param string $connectionName
     * @param bool $isDefault
     * @param array $annotations
     *
     * @return array
     */
    private function getAnnotatedMethods(
        \ReflectionClass $class,
        string $connectionName,
        bool $isDefault,
        array $annotations
    ) {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            foreach ($this->reader->getMethodAnnotations($method) as $annot) {
                if (! in_array(get_class($annot), $annotations)) {
                    continue;
                }

                if ($this->usesConnections($annot)) {
                    if ($this->hasConnectionName($annot, $connectionName, $isDefault)) {
                        $methods[$method->getName()] = $annot;
                    }
                } elseif (null !== $annot) {
                    $methods[$method->getName()] = $annot;
                }
            }
        }

        return $methods;
    }

    /**
     * Appends lookup paths to metadata driver.
     *
     * @param array $paths
     *
     * @return void
     */
    public function addPaths(array $paths)
    {
        $this->paths = array_unique(array_merge($this->paths, $paths));
    }

    /**
     * Retrieves the defined metadata lookup paths.
     *
     * @return array
     */
    public function getPaths()
    {
        return $this->paths;
    }

    /**
     * Append exclude lookup paths to metadata driver.
     *
     * @param array $paths
     */
    public function addExcludePaths(array $paths)
    {
        $this->excludePaths = array_unique(array_merge($this->excludePaths, $paths));
    }

    /**
     * Retrieve the defined metadata lookup exclude paths.
     *
     * @return array
     */
    public function getExcludePaths()
    {
        return $this->excludePaths;
    }

    /**
     * Retrieve the current annotation reader
     *
     * @return AnnotationReader
     */
    public function getReader()
    {
        return $this->reader;
    }

    /**
     * Gets the file extension used to look for mapping files under.
     *
     * @return string
     */
    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    /**
     * Sets the file extension used to look for mapping files under.
     *
     * @param string $fileExtension The file extension to set.
     *
     * @return void
     */
    public function setFileExtension($fileExtension)
    {
        $this->fileExtension = $fileExtension;
    }

    /**
     * Returns whether the class with the specified name is transient. Only non-transient
     * classes, that is entities and mapped superclasses, should have their metadata loaded.
     *
     * A class is non-transient if it is annotated with an annotation
     * from the {@see AnnotationDriver::entityAnnotationClasses}.
     *
     * @param string $className
     *
     * @return boolean
     * @throws \ReflectionException
     *
     */
    public function isTransient($className)
    {
        $classAnnotations = $this->reader->getClassAnnotations(new \ReflectionClass($className));
        foreach ($classAnnotations as $annot) {
            if (in_array(get_class($annot), $this->entityAnnotationClasses)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array|null
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    public function getAllClassNames()
    {
        if ($this->classNames !== null) {
            return $this->classNames;
        }
        if (! $this->paths) {
            throw new \RuntimeException('A path is required for mapping.');
        }
        $classes       = [];
        $includedFiles = [];
        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                throw new \RuntimeException('The path `'.$path.'` must be a directory.');
            }
            $iterator = new \RegexIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                ),
                '/^.+'.preg_quote($this->fileExtension).'$/i',
                \RecursiveRegexIterator::GET_MATCH
            );
            foreach ($iterator as $file) {
                $sourceFile = $file[0];
                if (! preg_match('(^phar:)i', $sourceFile)) {
                    $sourceFile = realpath($sourceFile);
                }
                foreach ($this->excludePaths as $excludePath) {
                    $exclude = str_replace('\\', '/', realpath($excludePath));
                    $current = str_replace('\\', '/', $sourceFile);
                    if (strpos($current, $exclude) !== false) {
                        continue 2;
                    }
                }
                require_once $sourceFile;
                $includedFiles[] = $sourceFile;
            }
        }
        foreach ($includedFiles as $file) {
            $className = $this->getClassName($file);
            if (! $this->isTransient($className)) {
                $classes[] = $className;
            }
        }
        $this->classNames = $classes;

        return $classes;
    }

    /**
     * @param string $file
     *
     * @return string
     */
    private function getClassName(string $file): string
    {
        $tokens    = token_get_all(file_get_contents($file));
        $namespace = $this->parseNamespace($tokens);
        $className = basename($file, '.php');

        return "$namespace\\$className";
    }

    /**
     * Extracts the namespace from tokenized file
     *
     * @param array $tokens
     *
     * @return string|null the classname or null
     */
    private function parseNamespace($tokens)
    {
        $namespace  = null;
        $tokenCount = count($tokens);
        for ($offset = 0; $offset < $tokenCount; $offset++) {
            if (! is_array($tokens[$offset])) {
                continue;
            }
            if (T_NAMESPACE === $tokens[$offset][0]) {
                $offset++; // the next token is a whitespace

                return $this->parseNamespaceString($tokens, $offset);
            }
        }

        return $namespace;
    }

    /**
     * Extracts the namespace from tokenized file
     *
     * @param array $tokens
     * @param integer $offset
     *
     * @return string
     */
    private function parseNamespaceString($tokens, $offset)
    {
        $namespace  = '';
        $tokenCount = count($tokens);
        for ($offset++; $offset < $tokenCount; $offset++) {
            // expecting T_STRING
            if (! is_array($tokens[$offset])) {
                break;
            }
            if (isset($tokens[$offset][0]) && T_STRING === $tokens[$offset][0]) {
                $namespace .= $tokens[$offset][1];
            } else {
                break;
            }
            // expecting T_NS_SEPARATOR
            $offset++;
            if (! is_array($tokens[$offset])) {
                continue;
            }
            if (isset($tokens[$offset][0]) && T_NS_SEPARATOR === $tokens[$offset][0]) {
                $namespace .= $tokens[$offset][1];
            } else {
                break;
            }
        }

        return $namespace;
    }

    private function usesConnections($object): bool
    {
        return method_exists($object, 'getConnections') || method_exists($object, 'getConnection');
    }

    private function hasConnectionName($object, string $connectionName, bool $isDefault): bool
    {
        if (method_exists($object, 'getConnections')) {
            $connections = $object->getConnections();
        } elseif (method_exists($object, 'getConnection')) {
            $connections = [$object->getConnection()];
        } else {
            return false;
        }

        return in_array('*', $connections) // Allow for wildcards
            || ($isDefault && in_array("default", $connections))
            || in_array($connectionName, $connections);
    }
}
