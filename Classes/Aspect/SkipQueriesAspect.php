<?php
declare(strict_types=1);

namespace Shel\Neos\Booster\Aspect;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Cache\FirstLevelNodeCache;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Utility\ObjectAccess;

/**
 * @Flow\Aspect
 */
class SkipQueriesAspect
{

    /**
     * @Flow\Pointcut("setting(Shel.Neos.Booster.aspects.enabled)")
     */
    public function aspectsActive(): void
    {
    }

    /**
     * Performance optimization to skip queries against an empty list of nodes.
     * @Flow\Around("method(Neos\ContentRepository\Domain\Repository\NodeDataRepository->filterNodeDataByBestMatchInContext()) && Shel\Neos\Booster\Aspect\SkipQueriesAspect->aspectsActive")
     * @deprecated This optimization will also be applied to Neos 5.3 and can be removed after an update.
     */
    public function applyNodeDataRepositoryAugmentation(JoinPointInterface $joinPoint): array
    {
        $nodeDataObjects = $joinPoint->getMethodArgument('nodeDataObjects');

        if (!$nodeDataObjects) {
            return [];
        }

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * Performance optimization to skip simple existence of childnodes queries when children have been queried already
     * @Flow\Around("method(Neos\ContentRepository\Domain\Model\Node->getNumberOfChildNodes()) && Shel\Neos\Booster\Aspect\SkipQueriesAspect->aspectsActive")
     * @deprecated This optimization will also be applied to Neos 5.3 and can be removed after an update.
     */
    public function applyNodeAugmentation(JoinPointInterface $joinPoint): int
    {
        /** @var Node $proxy */
        $proxy = $joinPoint->getProxy();
        $context = ObjectAccess::getProperty($proxy, 'context');
        $nodeTypeFilter = $joinPoint->getMethodArgument('nodeTypeFilter');

        $nodes = $context->getFirstLevelNodeCache()->getChildNodesByPathAndNodeTypeFilter(
            $proxy->getPath(),
            $nodeTypeFilter
        );
        if ($nodes !== false) {
            return count($nodes);
        }
        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * Performance optimization to skip repeated queries to non existing nodes
     * @deprecated This optimization will also be applied to Neos 5.3 and can be removed after an update.
     *
     * @return NodeInterface|boolean
     * @Flow\Around("method(Neos\ContentRepository\Domain\Service\Cache\FirstLevelNodeCache->getByIdentifier()) && Shel\Neos\Booster\Aspect\SkipQueriesAspect->aspectsActive")
     */
    public function applyNodeCacheByIdentifierAugmentation(JoinPointInterface $joinPoint)
    {
        /** @var FirstLevelNodeCache $proxy */
        $proxy = $joinPoint->getProxy();
        $nodesByIdentifier = ObjectAccess::getProperty($proxy, 'nodesByIdentifier', true);
        $identifier = $joinPoint->getMethodArgument('identifier');

        if (array_key_exists($identifier, $nodesByIdentifier)) {
            return $nodesByIdentifier[$identifier];
        }
        return false;
    }

    /**
     * Performance optimization to skip repeated queries to non existing nodes
     * @return NodeInterface|boolean
     * @Flow\Around("method(Neos\ContentRepository\Domain\Service\Cache\FirstLevelNodeCache->getByPath()) && Shel\Neos\Booster\Aspect\SkipQueriesAspect->aspectsActive")
     * @deprecated This optimization will also be applied to Neos 5.3 and can be removed after an update.
     */
    public function applyNodeCacheByPathAugmentation(JoinPointInterface $joinPoint)
    {
        /** @var FirstLevelNodeCache $proxy */
        $proxy = $joinPoint->getProxy();
        $nodesByPath = ObjectAccess::getProperty($proxy, 'nodesByPath', true);
        $path = $joinPoint->getMethodArgument('path');

        if (array_key_exists($path, $nodesByPath)) {
            return $nodesByPath[$path];
        }
        return false;
    }
}
