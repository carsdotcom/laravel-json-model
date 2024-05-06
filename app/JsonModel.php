<?php

/**
 * Laravel Models are designed to have a primary key and a database table.
 *     Ultimately every change persists by INSERT-ing or UPDATE-ing a row in a relational database.
 *
 * JsonModel reuses a lot of attributes and contracts from Model, but the persistence approach is totally different.
 *
 * By default, it doesn't persist. Data in, data out.
 * If you want to persist, you can provide the constructor or the ->link method a triplet of:
 *     Model
 *     Attribute on model (typically a cast-JSON column)
 *     (optional) string key on attribute
 */

declare(strict_types=1);

namespace Carsdotcom\LaravelJsonModel;

use ArrayAccess;
use Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException;
use Carsdotcom\JsonSchemaValidation\Helpers\FriendlyClassName;
use Carsdotcom\JsonSchemaValidation\Traits\ValidatesWithJsonSchema;
use Carsdotcom\LaravelJsonModel\Casts\ShouldUnset;
use Carsdotcom\LaravelJsonModel\Contracts\CanCascadeEvents;
use Carsdotcom\JsonSchemaValidation\Contracts\CanValidate;
use Carsdotcom\LaravelJsonModel\Helpers\ClassUsesTrait;
use Carsdotcom\LaravelJsonModel\Helpers\Json;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;
use Carsdotcom\LaravelJsonModel\Traits\HasLinkedData;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * Class JsonModel
 * @package Carsdotcom\LaravelJsonModel
 */
abstract class JsonModel implements ArrayAccess, Jsonable, JsonSerializable, CanValidate, CanCascadeEvents
{
    use HasAttributes;
    use HasTimestamps;
    use HasEvents;
    use HasLinkedData;
    use HasRelationships;
    use HidesAttributes;
    use ValidatesWithJsonSchema;

    /** @var string|null   JsonSchema used to validate this model before saving.  URI or Json string literal */
    public const SCHEMA = null;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'updated_at';

    /** @var bool  Indicates if the model exists. */
    public $exists = false;

    /**
     * Indicates whether lazy loading should be restricted on all models.
     *
     * @var bool
     */
    protected static $modelsShouldPreventLazyLoading = false;

    /**
     * The callback that is responsible for handling lazy loading violations.
     *
     * @var callable|null
     */
    protected static $lazyLoadingViolationCallback;

    /**
     * Indicates if an exception should be thrown instead of silently discarding non-fillable attributes.
     *
     * @var bool
     */
    protected static $modelsShouldPreventSilentlyDiscardingAttributes = false;

    /**
     * The callback that is responsible for handling discarded attribute violations.
     *
     * @var callable|null
     */
    protected static $discardedAttributeViolationCallback;

    /**
     * Indicates if an exception should be thrown when trying to access a missing attribute on a retrieved model.
     *
     * @var bool
     */
    protected static $modelsShouldPreventAccessingMissingAttributes = false;

    /**
     * The callback that is responsible for handling missing attribute violations.
     *
     * @var callable|null
     */
    protected static $missingAttributeViolationCallback;

    /**
     * required by HasAttributes::getCasts but can not be true for JsonModel
     * @return bool
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /*
     * ==== All of these are cloned from Model, but seem like they should be in HasAttributes.
     */
    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        // NOTE: This isn't general purpose recursion prevention, but it does catch one case, cheaply
        if ($value === $this) {
            throw new \RuntimeException('Cannot set a recursive property.');
        }
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Allow for native unset() calls.
     *
     * @param $key
     */
    public function __unset($key)
    {
        $this->offsetUnset($key);
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return !is_null($this->getAttribute($offset));
    }

    /**
     * Get the value for a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    /**
     * Set the value for a given offset.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset], $this->relations[$offset]);
    }

    // JsonModels don't support preventAccessingMissingAttributes yet
    protected function throwMissingAttributeExceptionIfApplicable($key)
    {
        return null;
    }
    /**
     * ============= End section that "should be in HasAttributes"
     */

    /**
     * ============== Cloned from Model but should be in HasEvents
     */

    /**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected static $dispatcher;

    /**
     * The array of booted models.
     *
     * @var array
     */
    protected static $booted = [];

    /**
     * Check if the model needs to be booted and if so, do it.
     *
     * @return void
     */
    protected function bootIfNotBooted()
    {
        if (!isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireModelEvent('booting', false);

            static::boot();

            $this->fireModelEvent('booted', false);
        }
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the model.
     *
     * @return void
     */
    protected static function bootTraits()
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {
            if (method_exists($class, $method = 'boot' . class_basename($trait))) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Clear the list of booted models so they will be re-booted.
     *
     * @return void
     */
    public static function clearBootedModels()
    {
        static::$booted = [];
    }
    /**
     * ============= End section that "should be in HasEvents"
     */

    /**
     * JsonModel constructor.
     * Takes three constructor signatures:
     * If called with an array, fill the private $attributes array. This is serializable, but not saveable.
     * If called with a model and string attribute,
     *     load that attribute (usually a JSON column) to fill this model's attributes.
     *     "Link" to the model and attribute for saving.
     * If called with a model, string attribute, and string key,
     *     load the attribute then look for that string key in that data (e.g. $deal->data['vehicle'])
     *     to fill this model's attributes
     *     "Link" to the model, attribute, and key for saving.
     * @param mixed ...$params
     */
    public function __construct(...$params)
    {
        $this->bootIfNotBooted();

        if (empty($params)) {
            $params = [[]]; // Act like one param, empty data
        }
        if (is_array($params[0])) {
            $this->syncOriginal();
            $this->fill($params[0]);
        } elseif ((is_a($params[0], Model::class) || is_a($params[0], JsonModel::class)) && is_string($params[1])) {
            $this->link(...$params);
            $this->fresh();
        } else {
            throw new \InvalidArgumentException(static::class . " couldn't understand the construct signature");
        }

        // If we have json model attributes, hydrate them
        if ((new ClassUsesTrait())($this, HasJsonModelAttributes::class)) {
            $this->hydrateAllJsonModelAttributes();
        }
    }

    /**
     * If this JsonModel is linked, download data over the link, and
     * fill internal attributes
     * @return JsonModel self (for chaining)
     * @throws \DomainException if the JsonModel isn't linked
     */
    public function fresh(): JsonModel
    {
        if ($this->hasJsonModelAttributes()) {
            $this->emptyJsonModelAttributeCache();
        }

        $linkedData = $this->getLinkedData();
        /**
         * If we somehow have an object, turn it back into an array.
         */
        if (is_object($linkedData)) {
            $linkedData = (array) $linkedData->toArray();
        }
        $this->fill($linkedData);
        // If you are being freshened from nothing, you are new,
        // 'exists' flag will cause your 'creating' and 'created' events to fire
        $this->exists = $linkedData !== null;
        $this->syncOriginal();
        return $this;
    }

    /**
     * This object has no properties
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->attributes);
    }

    /**
     * Use the HasAttributes concern to get casting, but basically just fill a private variable with data.
     * @param  array|null     $attributes
     * @return JsonModel self (for chaining)
     */
    public function fill(?array $attributes): JsonModel
    {
        if (is_array($attributes)) {
            foreach ($attributes as $key => $value) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Update attributes, and if configured for it, save.
     * @param  array   $attributes
     * @return bool was save successful?
     */
    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    /**
     * Update attributes on a JsonModel with JsonModel children safely without destroying the children's values.
     *
     * @Note: This might behave unexpectedly if $this->{$key} is currently null. E.g., the data gets set raw on the
     * parent, but the child does not get instantiated, so saving handlers and validation don’t get called.
     * @param array $attributes
     * @param bool $isRootOfChange
     * @return void
     */
    public function updateRecursive(array $attributes, bool $isRootOfChange = true)
    {
        foreach ($attributes as $key => $updatedAttribute) {
            if ($this->{$key} instanceof JsonModel) {
                $this->{$key}->updateRecursive($updatedAttribute, false);
            } else {
                $this->{$key} = $updatedAttribute;
            }
        }
        if ($isRootOfChange) {
            $this->save();
        }
    }

    /**
     * @deprecated It is always safer to use safeUpdateRecursive, so this non-recursive variant is deprecated
     * "If I am only for myself, what am I?"
     */
    public function safeUpdate(array $attributes): void
    {
        $this->safeUpdateRecursive($attributes);
    }

    /**
     * Given a set of changes that *might* contain some invalid data,
     * take the good parts and throw out the rest.
     * Assumes that $this was valid before the changes, and that each key could be an independent change,
     * so if you have validation states where two attributes have to agree, choose `update` instead.
     * @return bool was save successful?
     */
    public function safeUpdateRecursive(array $attributes, bool $isRootOfChange = true, array &$caughtExceptions = null): bool
    {
        if ($caughtExceptions === null) {
            $caughtExceptions = [];
        }
        foreach ($attributes as $key => $updatedAttribute) {
            $wasSet = isset($this->{$key});
            $previousValue = $this->{$key}; // __get will fill null even if it wasn't null

            try {
                if ($this->{$key} instanceof JsonModel) {
                    $this->{$key}->safeUpdateRecursive($updatedAttribute, false, $caughtExceptions);
                } else {
                    $this->{$key} = $updatedAttribute;
                }

                $canSave = $this->preSave();
                if (!$canSave) {
                    throw new \DomainException("A saving handler on " . (new FriendlyClassName())($this) . " returned false but provided no reason.");
                }
            } catch (\Throwable $e) {
                $caughtExceptions[] = $e;
                if ($wasSet) {
                    $this->{$key} = $previousValue;
                } else {
                    unset($this->{$key});
                }
            }
        }
        if ($isRootOfChange) {
            return $this->save();
        }
        return true;
    }

    /**
     * Save the data over the link:
     *
     * Fire the saving event. If a saving event handler returns false, return false without saving
     * Put data on the upstream model using set_linked_data (this centralizes checks for isLinked and the Schema)
     * Save the upstream model. If the model rejects the save, return false.
     * Fire the saved event.
     * @return bool   Was saving successful?
     */
    public function save(): bool
    {
        if ($this->preSave() === false) {
            return false;
        }

        $this->setLinkedData();
        $saved = $this->upstream_model->save();

        if ($saved) {
            $this->postSave();
        }

        return $saved;
    }

    /**
     * Delete local $attributes and set a NULL on the upstream model.
     *
     * Fire the deleting event. If a deleting event handler returns false, return false without deleting
     * reset local $attributes to empty array and set a NULL on the upstream model
     * (using unset_linked_data centralizes checks for isLinked and the Schema)
     * Save the upstream model. If the model rejects the save, return false.
     * Fire the deleted event.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->attributes = [];
        $this->setLinkedData();
        $deleted = $this->upstream_model->save();

        if ($deleted) {
            $this->fireModelEvent('deleted');
            $this->exists = false;
        }

        return $deleted;
    }

    /**
     * Use attribute casting to turn this object's $attributes back into an array.
     * Note, contrary to it's name this is really just a "prepare for JSON"
     * method and the return type is not strictly Array
     * @return array|null|object
     */
    public function toArray()
    {
        // In JSON terms, no one expects a JsonModel to be an empty array `[]`
        // Instead, return an empty object that serializes to `{}`
        if ($this->isEmpty()) {
            return (object) [];
        }

        return $this->attributesToArray();
    }

    /**
     * Implement PHP's JsonSerializable contract
     */
    public function jsonSerialize(): object|array|null
    {
        return $this->toArray();
    }

    /**
     * Cast this object back to a JSON string
     * @param  int $options see http://php.net/manual/en/function.json-encode.php
     * @return string
     * @throws JsonEncodingException
     */
    public function toJson($options = 0): string
    {
        $asArray = $this->toArray();
        return [] === $asArray ? '{}' : json_encode($asArray, $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Runs any pre-save events. This is basically a visibility hack,
     * so we can run the protected model events (implemented by HasEvents)
     * from outside the model (esp from CollectionOfJsonModels and HasJsonModelAttributes)
     *
     * If the listener returned literal false, this returns false,
     * and the caller should halt the save.
     * @return bool
     */
    public function preSave(): bool
    {
        if (!$this->exists && $this->fireModelEvent('creating') === false) {
            return false;
        }
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }
        $this->validateOrThrow(); // We validate after the handlers, because the handlers could clean up data, validate never does
        return $this->cascadePreSave();
    }

    /**
     * Runs any post-save events. This is a visibility hack, see above.
     * These can't affect behavior, so there is no return value
     */
    public function postSave(): void
    {
        $this->fireModelEvent('saved');
        if (!$this->exists) {
            $this->fireModelEvent('created');
            $this->exists = true;
        }
        $this->cascadePostSave();
        $this->syncOriginal();
    }

    /**
     * This is used by Laravel Models to optionally enable a custom collection
     * e.g. when you call a factory to return multiples.
     * Can be overridden by children (e.g. Vehicle returns CollectionOfVehicles)
     * @param array $models
     * @return Collection
     */
    public function newCollection(array $models = []): Collection
    {
        return (new CollectionOfJsonModels($models))->setType(static::class);
    }

    /**
     * Returns this object flattened entirely down to PHP language primitives, (usually an associative array)
     * by JSON-encoding and then JSON-decoding
     */
    public function mugglify()
    {
        return Json::mugglify($this);
    }

    /**
     * By default, a JsonModel doesn't have children to cascade to, so this is a no-op
     * But in conjunction with the HasJsonModelAttributes, this gets overwritten with a useful implementation.
     * @return bool
     */
    public function cascadePreSave(): bool
    {
        return true;
    }

    /**
     * By default, a JsonModel doesn't have children to cascade to, so this is a no-op
     * But in conjunction with the HasJsonModelAttributes, this gets overwritten with a useful implementation.
     */
    public function cascadePostSave(): void
    {
        return;
    }

    /**
     * This method controls the behavior of HasJsonModelAttributes, when you try to __get a Json Model Attribute
     * By default, a JsonModel is never null, this is only useful when overridden by NullWhenUsedAsAttributeWhenEmpty
     * @return bool
     */
    public function nullWhenUsedAsAttribute(): bool
    {
        return false;
    }

    /**
     * When casting Carbon attributes for storage, use ISO-8601
     * This overrides a method in HasAttributes that otherwise requires knowledge of the database connection type,
     * which obviously doesn't apply
     * @return string
     */
    public function getDateFormat(): string
    {
        return 'c';
    }

    public function hasJsonModelAttributes(): bool
    {
        return (new ClassUsesTrait())(class: $this, trait: HasJsonModelAttributes::class);
    }

    /**
     * Determine if lazy loading is disabled.
     *
     * @return bool
     */
    public static function preventsLazyLoading()
    {
        return static::$modelsShouldPreventLazyLoading;
    }

    /**
     * Determine if discarding guarded attribute fills is disabled.
     *
     * @return bool
     */
    public static function preventsSilentlyDiscardingAttributes()
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }

    /**
     * Determine if accessing missing attributes is disabled.
     *
     * @return bool
     */
    public static function preventsAccessingMissingAttributes()
    {
        return static::$modelsShouldPreventAccessingMissingAttributes;
    }

    /**
     * Extends setAttribute to cooperate with casts that implement ShouldUnset.
     * If the cast wishes, we'll *remove* the attribute from the Json representation.
     * This lets us implement things like PATCH {"zip":null} to mean "remove ZIP, return to default behavior"
     */
    use HasAttributes {
        setAttribute as protected eloquentSetAttribute;
    }

    public function setAttribute($key, $value) {
        $castType = $this->hasCast($key) ? $this->getCastType($key) : null;
        if (is_a($castType, ShouldUnset::class, true) && $castType::shouldUnset($value)) {
                unset($this->attributes[$key]);
                return $this;
        }

        $this->eloquentSetAttribute($key, $value);
        return $this;
    }
}
