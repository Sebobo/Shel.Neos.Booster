<?php

declare(strict_types=1);

namespace Shel\Neos\Booster\Fusion;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Cache\FirstLevelNodeCache;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
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
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

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
            $cache->setChildNodesByPathAndNodeTypeFilter($parentPath, '', $childNodes);
            $cache->setChildNodesByPathAndNodeTypeFilter($parentPath, '!Neos.Neos:Document,!unstructured', $childNodes);
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
            $loadedChildren[] = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
                $directChildNode->getPath(),
                '',
                $parentNode->getContext(),
                true
            );
        }

        $loadedChildren = array_merge(...$loadedChildren);

        if (count($loadedChildren) === 0) {
            return;
        }

        $referenceMap = [];

        /** @var array<NodeInterface> $allChildren */
        $allChildren = array_merge($parentNodes, $loadedChildren);
        foreach ($allChildren as $childNode) {
            $cache->setByIdentifier($childNode->getIdentifier(), $childNode);
            if (!isset($nodesByParent[$childNode->getParentPath()])) {
                $nodesByParent[$childNode->getParentPath()] = [];
            }
            $nodesByParent[$childNode->getParentPath()][] = $childNode;

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
}
