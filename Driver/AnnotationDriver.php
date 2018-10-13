<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 12:05 PM
 */

namespace AE\ConnectBundle\Driver;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Metadata\Metadata;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;

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
            SalesforceId::class
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
     *
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    public function loadMetadataForClass($className, Metadata $metadata)
    {
        $class             = new \ReflectionClass($className);
        $sourceAnnotations = $this->getClassAnnotations($class, SObjectType::class);
        /** @var SObjectType $sourceAnnotation */
        foreach ($sourceAnnotations as $sourceAnnotation) {
            if (in_array($metadata->getConnectionName(), $sourceAnnotation->getConnections())) {
                $metadata->setClassName($class->getName());
                $metadata->setSObjectType($sourceAnnotation->getName());
                $properties = $this->getAnnotatedProperties($class, $metadata->getConnectionName(), [
                    Field::class,
                    SalesforceId::class,
                ]);

                // Creates a map of properties relating source => target
                $fieldMap = array_map(
                    function ($item) {
                        if ($item instanceof Field) {
                            return $item->getName();
                        } else {
                            return 'Id';
                        }
                    },
                    $properties
                );

                $metadata->setFieldMap(new ArrayCollection($fieldMap));

                /** @var ExternalId[] $extIds */
                $extIds = $this->getAnnotatedProperties($class, $metadata->getConnectionName(), [ExternalId::class]);

                if (!empty($extIds)) {
                    foreach (array_keys($extIds) as $prop) {
                        if (array_key_exists($prop, $fieldMap)) {
                            $metadata->addIdentifier($prop);
                        }
                    }
                }
            }
        }
    }

    private function getClassAnnotations(\ReflectionClass $class, string $annotationName): array
    {
        $annotations = $this->reader->getClassAnnotations($class);
        $found       = [];

        foreach ($annotations as $annotation) {
            if (get_class($annotation) === $annotationName) {
                $found[] = $annotation;
            }
        }

        return $found;
    }

    /**
     * @param \ReflectionClass $class
     * @param string $connectionName
     * @param array $annotations
     *
     * @return array
     */
    private function getAnnotatedProperties(\ReflectionClass $class, string $connectionName, array $annotations)
    {
        $properties = [];
        foreach ($class->getProperties() as $property) {
            foreach ($annotations as $annotation) {
                $propAnnot = $this->reader->getPropertyAnnotation($property, $annotation);
                if (null !== $propAnnot) {
                    if ($propAnnot instanceof Field) {
                        if (in_array($connectionName, $propAnnot->getConnections())) {
                            $properties[$property->getName()] = $propAnnot;
                        }
                    } elseif ($propAnnot instanceof SalesforceId) {
                        if ($propAnnot->getConnection() === $connectionName) {
                            $properties[$property->getName()] = $propAnnot;
                        }
                    } else {
                        $properties[$property->getName()] = $propAnnot;
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * @param \ReflectionClass $class
     * @param string $connectionName
     * @param array $annotations
     *
     * @return array
     */
    private function getAnnotatedMethods(\ReflectionClass $class, string $connectionName, array $annotations)
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            foreach ($annotations as $annotation) {
                $annot = $this->reader->getMethodAnnotation($method, $annotation);
                if (null !== $annot) {
                    if ($annotation instanceof Field) {
                        if (in_array($connectionName, $annotation->getConnections())) {
                            $methods[$method->getName()] = $annot;
                        }
                    } else {
                        $methods[$method->getName()] = $annot;
                    }
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
     * @throws \ReflectionException
     *
     * @return boolean
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
        if (!$this->paths) {
            throw new \RuntimeException('A path is required for mapping.');
        }
        $classes       = [];
        $includedFiles = [];
        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
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
                if (!preg_match('(^phar:)i', $sourceFile)) {
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
            if (!$this->isTransient($className)) {
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
            if (!is_array($tokens[$offset])) {
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
            if (!is_array($tokens[$offset])) {
                break;
            }
            if (isset($tokens[$offset][0]) && T_STRING === $tokens[$offset][0]) {
                $namespace .= $tokens[$offset][1];
            } else {
                break;
            }
            // expecting T_NS_SEPARATOR
            $offset++;
            if (!is_array($tokens[$offset])) {
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
}
