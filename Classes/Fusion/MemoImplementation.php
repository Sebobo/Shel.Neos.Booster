<?php
declare(strict_types=1);
namespace Shel\Neos\Booster\Fusion;

use Neos\Fusion\FusionObjects\AbstractFusionObject;

/**
 * Memo object that returns the result of previous calls with the same "discriminator"
 * @deprecated This feature will be part of Neos 7.2
 */
class MemoImplementation extends AbstractFusionObject
{
    protected static $cache = [];

    /**
     * Return the processed value or its cached version based on the discriminator
     *
     * @return mixed
     */
    public function evaluate()
    {
        $discriminator = $this->getDiscriminator();
        if (array_key_exists($discriminator, self::$cache)) {
            return self::$cache[$discriminator];
        }

        $value = $this->getValue();
        self::$cache[$discriminator] = $value;

        return $value;
    }

    public function getDiscriminator(): string
    {
        return (string)$this->fusionValue('discriminator');
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->fusionValue('value');
    }
}
