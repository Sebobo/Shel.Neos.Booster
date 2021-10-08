<?php

declare(strict_types=1);

namespace Shel\Neos\Booster\Fusion;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Cache\FirstLevelNodeCache;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

/**
 * This Fusion object queries nodes from the database and fills the given node context
 * 1st level cache with the nodes it retrieved.
 */
class PreloadNodesImplementation extends AbstractFusionObject
{

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     */
    public function evaluate(): void
    {
        foreach ($this->getNodes() as $node) {
            $this->preloadContentNodes($node);
        }
    }

    /**
     * @return array<NodeInterface>
     */
    public function getNodes(): array
    {
        return $this->fusionValue('nodes');
    }

    protected function preloadContentNodes(NodeInterface $parentNode): void
    {
        $nodesByParent = [];
        $cache = $parentNode->getContext()->getFirstLevelNodeCache();

        $this->collectChildNodesRecursively($nodesByParent, $parentNode, $cache);

        // Store children for each node
        foreach ($nodesByParent as $parentPath => $childNodes) {
            $childNodesArray = array_values($childNodes);
            $cache->setChildNodesByPathAndNodeTypeFilter($parentPath, '', $childNodesArray);
            $cache->setChildNodesByPathAndNodeTypeFilter($parentPath, '!Neos.Neos:Document,!unstructured', $childNodesArray);
        }
    }

    protected function collectChildNodesRecursively(array &$nodesByParent, NodeInterface $parentNode, FirstLevelNodeCache $cache, bool $followReferences = true): void
    {
        $parentNodes = [$parentNode];

        // On document nodes we first need to fetch the direct content children to skip document node children
        if ($parentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            // Load all content from the given document nodes content collections
            $parentNodes = $parentNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection');
        }

        // Retrieve all childnodes
        $loadedChildren = [];
        foreach ($parentNodes as $directChildNode) {
            $loadedChildren[] = $this->findNodesByParent($directChildNode);
        }

        $loadedChildren = array_merge(...$loadedChildren);

        if (count($loadedChildren) === 0) {
            return;
        }

        $referenceMap = [];

        /** @var array<NodeInterface> $allChildren */
        $allChildren = array_merge($parentNodes, $loadedChildren);
        foreach ($allChildren as $childNode) {
            if (isset($nodesByParent[$childNode->getParentPath()][$childNode->getIdentifier()])) {
                continue;
            }

            $cache->setByIdentifier($childNode->getIdentifier(), $childNode);
            if (!isset($nodesByParent[$childNode->getParentPath()])) {
                $nodesByParent[$childNode->getParentPath()] = [];
            }
            $nodesByParent[$childNode->getParentPath()][$childNode->getIdentifier()] = $childNode;

            // Set childnode list empty to prevent queries for empty content collections later
            if (!isset($nodesByParent[$childNode->getPath()])) {
                $nodesByParent[$childNode->getPath()] = [];
            }

            // Collect referenced nodes to also load their children
            if ($followReferences && $childNode->getNodeType()->isOfType('Neos.NodeTypes.ContentReferences:ContentReferences')) {
                $referencesNodes = $childNode->getProperty('references');
                if (is_array($referencesNodes)) {
                    $referenceMap[] = $referencesNodes;
                }
            }
        }

        if (count($referenceMap) > 0) {
            $referenceMap = array_merge(...$referenceMap);

            // Preload referenced nodes, but don't follow references recursively
            /** @var NodeInterface $referenceNode */
            foreach ($referenceMap as $referenceNode) {
                if ($referenceNode->getNodeType()->isOfType('Neos.Neos:ContentCollection')) {
                    $this->collectChildNodesRecursively($nodesByParent, $referenceNode, $cache, false);
                }
            }
        }
    }

    /**
     *
     *
     * @return array<NodeInterface>
     */
    private function findNodesByParent(NodeInterface $parentNode): array
    {
        try {
            // The method `findByPathWithoutReduce` does not return the correct node
            // for nodes which have been moved inside the queried nodetree
            // so instead of the updated node, the original unmoved node or no node is returned.
            // FIXME: This can be cleaned up again after this bug has been fixed in the core.
            # $nodeDataElements = $this->nodeDataRepository->findByPathWithoutReduce($parentNode->getPath(), $parentNode->getContext()->getWorkspace(), true, true);

            $parentPath = strtolower($parentNode->getPath());
            $workspaces = $this->collectWorkspaceAndAllBaseWorkspaces($parentNode->getContext()->getWorkspace());
            $workspacesNames = array_map(static function(Workspace $workspace) { return $workspace->getName(); }, $workspaces);

            $queryBuilder = $this->entityManager->createQueryBuilder();

            // Filter by workspace and its parents
            $queryBuilder->select('n')
                ->from(NodeData::class, 'n')
                ->where('n.workspace IN (:workspaces)')
                ->andWhere('n.movedTo IS NULL')
                ->setParameter('workspaces', $workspacesNames);

            // Filter by parentpath
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX()
                    ->add($queryBuilder->expr()->eq('n.parentPathHash', ':parentPathHash'))
                    ->add($queryBuilder->expr()->like('n.parentPath', ':parentPath'))
            )
                ->setParameter('parentPathHash', md5($parentPath))
                ->setParameter('parentPath', rtrim($parentPath, '/') . '/%');

            $query = $queryBuilder->getQuery();
            $nodeDataElements = $query->getResult();

            $nodeDataElements = $this->reduceNodeVariantsByWorkspaces($nodeDataElements, $workspacesNames);
            $nodeDataElements = $this->sortNodesByIndex($nodeDataElements);

            // Convert nodedata objects to nodeinterfaces
            $finalNodes = [];
            foreach ($nodeDataElements as $nodeData) {
                $node = $this->nodeFactory->createFromNodeData($nodeData, $parentNode->getContext());
                if ($node !== null) {
                    $finalNodes[] = $node;
                }
            }
            return $finalNodes;
        } catch (\Exception $e) {
        }
        return [];
    }

    /**
     * @return array<Workspace>
     */
    protected function collectWorkspaceAndAllBaseWorkspaces(Workspace $workspace): array
    {
        $workspaces = [];
        while ($workspace !== null) {
            $workspaces[] = $workspace;
            $workspace = $workspace->getBaseWorkspace();
        }

        return $workspaces;
    }

    /**
     * @param array<NodeData> $nodes
     * @param array<string> $workspaceNames
     * @return array<NodeData>
     */
    protected function reduceNodeVariantsByWorkspaces(array $nodes, array $workspaceNames): array
    {
        $foundNodes = [];
        $minimalPositionByIdentifier = [];

        foreach ($nodes as $node) {
            // Find the position of the workspace, a smaller value means more priority
            $workspacePosition = array_search($node->getWorkspace()->getName(), $workspaceNames);

            $uniqueNodeDataIdentity = $node->getIdentifier() . '|' . $node->getDimensionsHash();
            if (!isset($minimalPositionByIdentifier[$uniqueNodeDataIdentity]) || $workspacePosition < $minimalPositionByIdentifier[$uniqueNodeDataIdentity]) {
                $foundNodes[$uniqueNodeDataIdentity] = $node;
                $minimalPositionByIdentifier[$uniqueNodeDataIdentity] = $workspacePosition;
            }
        }

        return $foundNodes;
    }

    /**
     * @param array<NodeData> $nodes
     * @return array<NodeData>
     */
    protected function sortNodesByIndex(array $nodes): array
    {
        usort($nodes, static function ($node1, $node2) {
            if ($node1->getIndex() < $node2->getIndex()) {
                return -1;
            }
            if ($node1->getIndex() > $node2->getIndex()) {
                return 1;
            }
            return strcmp($node1->getIdentifier(), $node2->getIdentifier());
        });

        return $nodes;
    }
}
