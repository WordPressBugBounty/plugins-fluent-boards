<?php

namespace FluentBoards\Framework\Database\Orm;

use BadMethodCallException;
use FluentBoards\Framework\Support\Str;
use FluentBoards\Framework\Database\Orm\Relations\HasMany;
use FluentBoards\Framework\Database\Orm\Relations\MorphOneOrMany;

/**
 * @template TIntermediateModel of \FluentBoards\Framework\Database\Orm\Model
 * @template TDeclaringModel of \FluentBoards\Framework\Database\Orm\Model
 */
class PendingHasThroughRelationship
{
    /**
     * The root model that the relationship exists on.
     *
     * @var TDeclaringModel
     */
    protected $rootModel;

    /**
     * The local relationship.
     *
     * @var \FluentBoards\Framework\Database\Orm\Relations\HasMany|\FluentBoards\Framework\Database\Orm\Relations\HasOne
     */
    protected $localRelationship;

    /**
     * Create a pending has-many-through or has-one-through relationship.
     *
     * @param mixed $rootModel
     * @param \FluentBoards\Framework\Database\Orm\Relations\HasMany|\FluentBoards\Framework\Database\Orm\Relations\HasOne $localRelationship
     */
    public function __construct($rootModel, $localRelationship)
    {
        $this->rootModel = $rootModel;

        $this->localRelationship = $localRelationship;
    }

    /**
     * Define the distant relationship that this model has.
     *
     * @param string|callable $callback Either the distant relationship name or a callback returning the local relation.
     * @return \FluentBoards\Framework\Database\Orm\Relations\HasManyThrough|\FluentBoards\Framework\Database\Orm\Relations\HasOneThrough
     *         The distant relationship instance.
     */
    public function has($callback)
    {
        if (is_string($callback)) {
            $callback = fn () => $this->localRelationship->getRelated()->{$callback}();
        }

        $distantRelation = $callback($this->localRelationship->getRelated());

        if ($distantRelation instanceof HasMany) {
            $returnedRelation = $this->rootModel->hasManyThrough(
                get_class($distantRelation->getRelated()),
                get_class($this->localRelationship->getRelated()),
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        } else {
            $returnedRelation = $this->rootModel->hasOneThrough(
                get_class($distantRelation->getRelated()),
                get_class($this->localRelationship->getRelated()),
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        }

        if ($this->localRelationship instanceof MorphOneOrMany) {
            $returnedRelation->where($this->localRelationship->getQualifiedMorphType(), $this->localRelationship->getMorphClass());
        }

        return $returnedRelation;
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'has')) {
            return $this->has(Str::of($method)->after('has')->lcfirst()->toString());
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()', static::class, $method
        ));
    }
}
