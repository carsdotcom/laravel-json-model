<?php

/**
 * One stop shop to hydrate a collection where every item is a JsonModel
 */

declare(strict_types=1);

namespace Carsdotcom\LaravelJsonModel;

use Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException;
use Carsdotcom\LaravelJsonModel\Exceptions\UniqueException;
use Carsdotcom\JsonSchemaValidation\SchemaValidator;
use Carsdotcom\JsonSchemaValidation\Contracts\CanValidate;
use Carsdotcom\LaravelJsonModel\Helpers\FriendlyClassName;
use Carsdotcom\LaravelJsonModel\Traits\HasLinkedData;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class CollectionOfJsonModels
 * @package Carsdotcom\LaravelJsonModel
 */
class CollectionOfJsonModels extends Collection implements CanValidate
{
    use HasLinkedData;

    /** @var string
     * When defining a HasJsonModelAttributes $jsonModelAttributes config
     * Using this constant in the fourth position marks an attribute as
     * a CollectionOfJsonModels
     */
    public const IS_A = true;
    public const NOT_A = false;

    /** @var string Class name that each element will be hydrated as during ->fresh() */
    protected $itemClass = '';

    /** @var null|string   Optional name of a primary key. If present, you can use ->find and ->push will overwrite existing */
    protected $primaryKey = null;
    /**
     * This call is mandatory for any real use of this class
     * But if you modify the constructor, you start getting failures
     * in Collection methods that are written to return `new static`
     * @param string $itemClass
     * @return CollectionOfJsonModels
     */
    public function setType(string $itemClass = null): self
    {
        if (!is_a($itemClass, JsonModel::class, true)) {
            throw new \DomainException('CollectionOfJsonModels type must be a descendent of JsonModel');
        }
        $this->itemClass = $itemClass;

        return $this;
    }

    /**
     * @param string|null $key
     * @return CollectionOfJsonModels
     */
    public function setPrimaryKey(string $key = null): self
    {
        $this->primaryKey = $key;
        return $this;
    }

    /**
     * Pull data from the link, and fill items with hydrated, linked JsonModels
     * @return self
     */
    public function fresh(): self
    {
        return $this->fill($this->getLinkedData() ?: []);
    }

    /**
     * @param iterable $items
     * @return CollectionOfJsonModels
     * @throws DomainException
     */
    public function fill(iterable $items): self
    {
        if (!$this->itemClass) {
            throw new DomainException("Can't load CollectionOfJsonModels until type has been set.");
        }

        $this->items = [];

        foreach ($items as $idx => $item) {
            if (!($item instanceof $this->itemClass)) {
                $item = new $this->itemClass($item);
            }
            $item->exists = true;
            if ($this->primaryKey) {
                $this->items[$item[$this->primaryKey]] = $item;
            } else {
                $this->items[] = $item;
            }
        }
        $this->reindexItemLinks();

        return $this;
    }

    /**
     * Link all items individually with correct numeric indices
     */
    protected function reindexItemLinks(): void
    {
        if (!$this->isLinked()) {
            return;
        }
        foreach ($this->items as $idx => $item) {
            $item->link(
                $this->upstream_model,
                $this->upstream_attribute,
                $this->upstream_key ? "{$this->upstream_key}.{$idx}" : "{$idx}",
            );
        }
    }

    /**
     * Push an item onto the end of the Collection.
     * Our implementation uses offsetSet (like Laravel 5) to get casting and unique primary keys
     * @param  mixed  $values [optional]
     * @return CollectionOfJsonModels
     */
    public function push(...$values)
    {
        foreach ($values as $value) {
            $this->offsetSet(null, $value);
        }
        return $this;
    }

    /**
     * Override the ArrayAccess method for setting (offsetSet) to:
     *     cast incoming items (especially arrays) to itemClass
     *     Check that you're not duplicating primaryKey
     *         (on implementations that require one)
     * @param $key
     * @param mixed $value
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        if (!$this->itemClass) {
            throw new \DomainException("Can't add items to CollectionOfJsonModels until type has been set.");
        }
        if (!$value instanceof $this->itemClass) {
            if (!is_array($value)) {
                $differentObject = is_object($value) ? get_class($value) : gettype($value);
                throw new \DomainException("Can't insert a {$differentObject} in a Collection of {$this->itemClass}");
            }

            $value = new $this->itemClass($value);
        }

        // If ->push (key null) and has primaryKey, make sure you're not duplicating
        if ($key === null && $this->primaryKey) {
            if (isset($this->items[$value[$this->primaryKey]])) {
                throw new UniqueException(
                    "Collection can't contain duplicate {$this->primaryKey} {$value[$this->primaryKey]}",
                );
            }
            $this->items[$value[$this->primaryKey]] = $value;
        } else {
            parent::offsetSet($key, $value);
        }
        $this->reindexItemLinks();
    }

    /**
     * Save the data over the link
     * @return bool
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
     * Does this object pass its own standard for validation?
     * @return true
     * @throws JsonSchemaValidationException   if data is invalid
     */
    public function validateOrThrow(
        string $exceptionMessage = null,
        int $failureHttpStatusCode = Response::HTTP_BAD_REQUEST,
    ): bool {
        if (!$this->itemClass) {
            throw new \DomainException("Can't validate a CollectionOfJsonModels until type has been set.");
        }
        $absoluteCollectionSchemaUri = SchemaValidator::registerRawSchema(
            json_encode([
                'type' => 'array',
                'items' => [
                    '$ref' => $this->itemClass::SCHEMA,
                ],
            ]),
        );

        return SchemaValidator::validateOrThrow(
            $this,
            $absoluteCollectionSchemaUri,
            (new FriendlyClassName())(static::class) . ' contains invalid data!',
            failureHttpStatusCode: $failureHttpStatusCode,
        );
    }

    /**
     * Convert the object into something JSON serializable.
     * When we serialize as numeric for the wire,
     * But when we're going to disk we serialize it as an object keyed by primary key
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        $items_was = $this->items;
        $this->items = array_values($this->items);
        $serializedNumeric = parent::jsonSerialize();
        $this->items = $items_was;
        return $serializedNumeric;
    }

    /**
     * Given a value, return the first element where primaryKey is that value
     * or null if not found
     * @param mixed $value
     * @return JsonModel|null
     */
    public function find($value): ?JsonModel
    {
        if (!$this->primaryKey) {
            throw new \DomainException('Cannot use method find until primary key has been set.');
        }
        return $this[$value] ?? null;
    }

    /**
     * Given a value, return the first element where primaryKey is that value
     * @throws ModelNotFoundException if no element is found
     * @param $value
     * @return JsonModel
     */
    public function findOrFail($value): JsonModel
    {
        $found = $this->find($value);
        if (!$found) {
            throw (new ModelNotFoundException())->setModel($this->itemClass ?: JsonModel::class);
        }
        return $found;
    }

    /**
     * Call preSave observers on all items
     * If *any* item returns false, exit early returning false.
     * Otherwise return true
     * @return bool
     */
    public function preSave(): bool
    {
        return $this->every->preSave();
    }

    /**
     * Call postSave observers on all items. Responses are ignored.
     */
    public function postSave(): void
    {
        $this->each->postSave();
    }

    /**
     * If you try to buildExpandedObject a CollectionOfJsonModels,
     * make sure the children support it,
     * then expand all the children with the requested attributes.
     * @param array $with
     * @return CollectionOfJsonModels
     */
    public function buildExpandedObject(array $with): self
    {
        if (!method_exists($this->itemClass, 'buildExpandedObject')) {
            throw new \DomainException(
                (new FriendlyClassName())($this) .
                ' cannot buildExpandedObject because ' .
                (new FriendlyClassName())($this->itemClass) .
                ' does not support it.',
            );
        }
        foreach ($this->items as $item) {
            $item->buildExpandedObject($with);
        }
        return $this;
    }
}
