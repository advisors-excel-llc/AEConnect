<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 9:47 AM.
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use Doctrine\Persistence\ManagerRegistry;
use JMS\Serializer\Exception\CircularReferenceDetectedException;

abstract class AbstractTreeBuilder
{
    /**
     * @var ManagerRegistry
     */
    protected $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function buildFlatMap(ConnectionInterface $connection): array
    {
        //return ['Contact', 'Account', 'Financial', 'FinServ__FinancialAccount__c', 'Premium__c'];
        $objects = $this->aggregate($connection);

        // Hey this is a network graph problem!  Lets make some nodes to better represent these dependencies.
        // Our 1st level nodes will be all of the objects that are not dependent on any other
        // RecordType gets to be our most base node, we know that in SF, all sObjects can have a record type.
        $baseNode = new Node('RecordType');

        // Now we are going to fill out the rest of our graph by choosing things directly dependent on every item in our nodes.
        $queue = new \SplQueue();


        foreach (array_filter($objects, function ($object) { return 0 === count($object); }) as $class => $empty) {
            if ($class === 'RecordType') {
                continue;
            }
            $node = new Node($class);
            $baseNode->addChild($node);
            $queue->enqueue($node);
        }

        while ($queue->count() > 0) {
            $node = $queue->dequeue();
            $children = array_keys(array_filter($objects, function ($object) use ($node) { return false !== array_search($node->getName(), array_keys($object)); }));
            foreach ($children as $child) {
                $childNode = $baseNode->findChild($child);
                if (!$childNode) {
                    $childNode = new Node($child);
                    $queue->enqueue($childNode);
                }
                $node->addChild($childNode);
            }
        }

        return $baseNode->toDependencyArray();
    }

    abstract protected function aggregate(ConnectionInterface $connection): array;

    abstract protected function buildDependencies(MetadataRegistry $metadataRegistry, Metadata $metadata): array;
}

class Node
{
    /** @var Node[] */
    private $parents = [];
    /** @var Node[] */
    private $children = [];
    /** @var string */
    private $name;
    /** @var bool */
    public $visited = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /** Depth first search for a child */
    public function findChild(string $name): ?Node
    {
        if ($this->name === $name) {
            return $this;
        }
        foreach ($this->children as $child) {
            $result = $child->findChild($name);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    public function getParents()
    {
        return $this->parents;
    }

    public function addParent(Node $parent)
    {
        $this->parents[$parent->getName()] = $parent;
    }

    public function addChild(Node $child)
    {
        $this->children[$child->getName()] = $child;
        $child->addParent($this);
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** a breadth first search of this data structure will yield our dependency map.  ALGORITHM:
     * 1) enqueue your base node.  This is the node from which a dependency list will form.
     * 2) while you still have items in your queue,
     *   2a) dequeue
     *   2b) enqueue all of the unvisited children of the dequeued node (This is what powers our BREADTH FIRST SEARCH)
     *   2c) VISIT the current node (See the VISIT function)
     *   2d) array_merge the result of that visit into your current map.
     *   2e) end
     * 3) return the results of your current map.
     */
    public function toDependencyArray()
    {
        $queue = new \SplQueue();
        $queue->enqueue($this);
        $map = [];
        $circularReference = [];

        while ($queue->count()) {
            $node = $queue->dequeue();
            $map = array_merge($map, $node->visit($circularReference));
            foreach ($node->getChildren() as $child) {
                if (!$child->visited) {
                    $queue->enqueue($child);
                }
            }
        }

        return $map;
    }

    /**
     * To visit a node means that we are adding this node and all of its dependencies into a $map array of strings
     * and returning that array up.  This will return an empty array if the node being visited has already been visited before.
     * ALGORITHM: (Keep in mind that this is step 2c in toDependencyArray's algorithm
     * 1) Check if each parent has been visited or not already.
     * 2)   If a parent has not been visited, visit it now.
     * 3) merge the results of the parent visit together in an array
     * 4) Once all parents have been visited, check if the current node has been visited
     * 5) If it has, array_push the name of the current node onto the array from earlier
     * 6) Mark thie current node as visited
     * 7) return the contents of the map
     */
    public function visit(array $circularReferenceDetector = []): array
    {
        $map = [];
        // We need to ensure we never let a user have configuration which results in infinite recursion.
        if (array_search($this->getName(), $circularReferenceDetector)) {
            throw new CircularReferenceDetectedException('Your AEConnect / Doctrine join configuration contains circular sObject references : '.implode(', ', $circularReferenceDetector));
        }
        array_push($circularReferenceDetector, $this->getName());

        foreach ($this->getParents() as $parent) {
            // Has this nodes parents been visited?  Visited implies this and all of the nodes parents are part of the map already
            // This must be true if this node is allowed to add itself to the map.
            if (!$parent->visited) {
                $map = array_merge($map, $parent->visit());
            }
        }

        // officially VISIT this node if it has not been visited already.
        if (!$this->visited) {
            array_push($map, $this->getName());
            $this->visited = true;
        }
        // The last entry in this will ALWAYS be $this->name
        array_pop($circularReferenceDetector);
        // and we are ready to return the map contents here, this might just be an empty array as well if we've already visited this node before.
        return $map;
    }
}
