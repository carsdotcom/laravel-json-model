<?php

/**
 * When attached to a Model, makes it easy to provision attributes that are JsonModels
 *
 * The Model bearing this Trait needs to define a protected property like:
 *
 * protected $jsonModelAttributes = [];
 *
 * This array is a hash map. Each entry is keyed by the name of the attribute
 * (how you'll set and get this JsonModel from its parent Model)
 *
 * and its value is an array tuple or triple:
 * 'key' => ['ClassName', 'attribute']
 * When you get $this->{$key} we actually return new ClassName($this->{'attribute'})
 *
 * or
 *
 * 'key' => ['ClassName', 'attribute', 'attribute-key']
 * When you get $this->{$key} we actually return new ClassName($this->{'attribute'}['attribute-key'])
 *
 * Note that the way other code (e.g. JsonModel::toArray) looks for this trait,
 * this trait must be added to the concrete child, it can't be inherited.
 */

declare(strict_types=1);

namespace Carsdotcom\LaravelJsonModel\Traits;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\LaravelJsonModel\Helpers\Json;
use Carsdotcom\LaravelJsonModel\Contracts\CanCascadeEvents;
use Carsdotcom\LaravelJsonModel\JsonModel;
use DomainException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use stdClass;

trait HasJsonModelAttributes
{
    /**
     * @var array
     * In-memory cache so if you come back to an attribute by key
     * You get the same object. Lets you make incremental changes.
     */
    protected $jsonModelAttributeCache = [];

    /**
     * When __get()ing a recognized attribute in the jsonModelAttributes array, try to hydrate the expected class.
     * If there's no data, return a clean null.
     * If the $key is not in the jsonModelAttributes array this Trait is responsible for,
     * kick the ball up to the parent.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        $config = $this->isJsonModelAttribute($key);
        if (!$config) {
            return parent::__get($key);
        }
        if (array_key_exists($key, $this->jsonModelAttributeCache)) {
            return $this->jsonModelAttributeCache[$key];
        }
        [$type, $attribute, $attribute_key, $is_collection, $primary_key] = $config;
        if ($is_collection) {
            /** @var CollectionOfJsonModels $collection */
            $collection = (new $type())->newCollection();
            return $this->jsonModelAttributeCache[$key] = $collection
                ->setPrimaryKey($primary_key)
                ->link($this, $attribute, $attribute_key)
                ->fresh();
        }

        /** @var JsonModel $linked */
        $linked = new $type($this, $attribute, $attribute_key);
        if ($linked->nullWhenUsedAsAttribute()) {
            return null;
        }
        $this->jsonModelAttributeCache[$key] = $linked;
        return $linked;
    }

    /**
     * Determine if a Json Model Attribute exists, otherwise defer to parent
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        if ($this->isJsonModelAttribute($key)) {
            return $this->{$key} ? !$this->{$key}->isEmpty() : false;
        }
        return parent::__isset($key);
    }

    /**
     * When __set()ing a recognized attribute in the jsonModelAttributes array:
     *     If the value is already the correct type, use its save method (this gets us casting, observers, etc)
     *     If the value is an array, try to turn it into the correct class and then save (same reason)
     * If the $key is not in the jsonModelAttributes array this Trait is responsible for,
     * kick the ball up to the parent.
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     * @throws InvalidArgumentException
     */
    public function __set($key, $value)
    {
        $config = $this->isJsonModelAttribute($key);
        if (!$config) {
            parent::__set($key, $value);
            return;
        }

        [$type, $attribute, $attribute_key, $is_collection, $primary_key] = $config;
        if (!$is_collection) {
            //Setting it to raw array data, build up an object
            if (is_array($value)) {
                /** @var JsonModel $value */
                $value = new $type($value);
            }

            if (!($value instanceof $type)) {
                throw new InvalidArgumentException("{$key} must be a {$type} or valid array");
            }

            /** @var JsonModel $value */
            $value->link($this, $attribute, $attribute_key);
            $value->preSave();
            $value->setLinkedData();

            $this->jsonModelAttributeCache[$key] = $value;
        } else {
            /** @var CollectionOfJsonModels $collection */
            $collection = (new $type())
                ->newCollection()
                ->setPrimaryKey($primary_key)
                ->link($this, $attribute, $attribute_key);
            if (is_iterable($value)) {
                foreach ($value as $each) {
                    // This has the side effect of casting children
                    $collection->push($each);
                }
            } else {
                throw new InvalidArgumentException("{$key} must be iterable");
            }
            $collection->setLinkedData();
            $this->jsonModelAttributeCache[$key] = $collection;
        }

        return;
    }

    /**
     * Parent has been explicitly asked to remove a Json Model Attribute
     * Type declaration must be compatible with Laravel Model
     * @param string $key
     * @return void
     */
    public function __unset($key)
    {
        $config = $this->isJsonModelAttribute($key);
        if (!$config) {
            parent::__unset($key);
            return;
        }
        Arr::forget($this->jsonModelAttributeCache, $key);
        [$type, $attribute, $attribute_key, $is_collection, $primary_key] = $config;
        if ($attribute_key) {
            $wholeAttribute = $this->{$attribute};
            unset($wholeAttribute[$attribute_key]);
            $this->{$attribute} = $wholeAttribute;
        } else {
            Arr::forget($this->attributes, $attribute);
        }
    }

    /**
     * Override to normal Laravel toArray functionality.
     * We ask all our hydrated Json Model Attributes in cache to
     * serialize themselves because our local representation of them can be less fresh
     * @return array|null|stdClass
     */
    public function toArray()
    {
        $array = parent::toArray();
        if ($this->jsonModelAttributeCache) {
            // Our conception of ourself could be null or even stdClass.
            // convert to array only if absolutely necessary
            if (!is_array($array)) {
                $array = (array) $array;
            }
            foreach ($this->jsonModelAttributeCache as $key => $value) {
                // None of our models are safe to encode to empty object, because they tend to get flattened back to []
                if (json_encode($value) === '{}') {
                    Arr::forget($array, $key);
                    continue;
                }
                $array[$key] = $value;
            }

            // After removing empty properties, I have no properties, I am empty
            if (!$array) {
                return (object) [];
            }
        }

        return $array;
    }

    /**
     * Functions like eager loading a Laravel Relationship.
     * Forget all currently cached Json Model Attributes,
     * then hydrate all the models requested.
     * This is a good way to make sure the JSON you're about to emit
     * contains all the relationships your caller needs.
     * @param array $keys
     * @return HasJsonModelAttributes
     */
    public function withJsonModelAttributes(array $keys): self
    {
        $this->emptyJsonModelAttributeCache();
        foreach ($keys as $key) {
            $this->__get($key); // Prime the cache with the getter
        }
        return $this;
    }

    protected function jsonModelAttributesConfigIsValid()
    {
        if (!property_exists($this, 'jsonModelAttributes')) {
            throw new DomainException(
                static::class . ' must define a property jsonModelAttributes to use HasJsonModelAttributes',
            );
        }
        if (!is_array($this->jsonModelAttributes)) {
            throw new DomainException(static::class . ' must define an array for property jsonModelAttributes');
        }
    }

    /**
     * Given a key, if this attribute is responsible for it, return the config tuple.
     * If we're not responsible for it, return null.
     * @param $key
     * @return array|null
     * @throws DomainException if our config in jsonModelAttributes is malformed.
     */
    protected function isJsonModelAttribute($key): ?array
    {
        $this->jsonModelAttributesConfigIsValid();
        if (array_key_exists($key, $this->jsonModelAttributes)) {
            $tuple = $this->jsonModelAttributes[$key];
            if (!is_array($tuple) || count($tuple) < 2 || count($tuple) > 5) {
                throw new DomainException('Unusable jsonModelAttributes in ' . static::class . " for $key");
            }
            // Create a consistent four-item config but let definer be lazy
            if (!isset($tuple[2])) {
                $tuple[2] = null;
            }
            if (!isset($tuple[3])) {
                $tuple[3] = CollectionOfJsonModels::NOT_A;
            }
            if (!isset($tuple[4])) {
                $tuple[4] = null;
            }
            return $tuple;
        }
        return null;
    }

    /**
     * Empty the JsonModel attribute cache.
     * This is especially useful when the underlying storage mechanism changes
     */
    public function emptyJsonModelAttributeCache(): void
    {
        $this->jsonModelAttributeCache = [];
    }

    /**
     * Does this attribute have changes that haven't been written?
     * @param $key
     * @return bool
     */
    public function isJsonModelAttributeDirty($key): bool
    {
        $config = $this->isJsonModelAttribute($key);
        if (!$config) {
            throw new InvalidArgumentException("{$key} must be a Json Model Attribute");
        }

        $accordingToChild = json_encode($this->jsonModelAttributeCache[$key] ?? null);

        [$type, $attribute, $attribute_key, $is_collection, $primary_key] = $config;

        if ($attribute_key) {
            $accordingToParent = json_encode($this->original[$attribute][$attribute_key] ?? null);
        } else {
            $accordingToParent = json_encode($this->original[$attribute] ?? null);
        }
        return Json::canonicalize($accordingToChild) !== Json::canonicalize($accordingToParent);
    }

    /**
     * This is called in the constructor for JsonModels
     * When waking up a JsonModel from raw JSON, hydrate all the children immediately
     * so future serialization events can use things like magic attributes.
     */
    public function hydrateAllJsonModelAttributes(): void
    {
        $this->jsonModelAttributesConfigIsValid();
        foreach ($this->jsonModelAttributes as $key => $ignore) {
            $this->__get($key);
        }
    }

    /**
     * Iterate over every hydrated attribute in the cache and tell each
     * to call its own preSave, or cascadePreSave to its own children
     * @return bool
     */
    public function cascadePreSave(): bool
    {
        foreach ($this->jsonModelAttributeCache as $child) {
            if ($child instanceof CanCascadeEvents) {
                if ($child->preSave() === false) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Iterate over every hydrated attribute in the cache and tell each
     * to call its own postSave, or cascadePostSave to its own children
     */
    public function cascadePostSave(): void
    {
        foreach ($this->jsonModelAttributeCache as $child) {
            if ($child instanceof CanCascadeEvents) {
                $child->postSave();
            }
        }
    }

    /**
     * I am empty if ALL my descendents are empty, and I am locally empty
     * @return bool
     */
    public function isEmpty(): bool
    {
        return collect($this->jsonModelAttributeCache)->every->isEmpty() && parent::isEmpty();
    }
}
