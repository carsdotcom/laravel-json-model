<?php

namespace Carsdotcom\LaravelJsonModel\Traits;

use Carsdotcom\JsonSchemaValidation\Contracts\CanValidate;
use Carsdotcom\LaravelJsonModel\JsonModel;
use DomainException;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

trait HasLinkedData
{
    /** @var Model|null    Model on which to save this JsonModel's data */
    protected $upstream_model = null;

    /** @var string|null   Attribute on the model, where to save this JsonModel's data */
    protected $upstream_attribute = null;

    /** @var string|null   Array key on the attribute (optional, helpful with DealData) where to store this JsonModel's data */
    protected $upstream_key = null;

    /**
     * Link this JsonModel to a real model where we can persist our data into a larger whole
     * @param  Model|JsonModel          $model
     * @param  string         $attribute
     * @param  string|null    $key
     * @return self (for chaining)
     */
    public function link($model, string $attribute, string $key = null): self
    {
        $this->upstream_model = $model;
        $this->upstream_attribute = $attribute;
        $this->upstream_key = $key;
        return $this;
    }

    /**
     * Is this model instance linked to an upstream model that can be used to save and load?
     * @return bool
     */
    public function isLinked(): bool
    {
        return $this->upstream_model && $this->upstream_attribute;
    }

    /**
     * Get data over the link to the model
     * In contrast to ->fresh, this method does NOT update local attributes.
     * @return mixed
     *     Note, return type is *typically* an associative array
     * @throws DomainException if the JsonModel isn't linked
     */
    public function getLinkedData()
    {
        if (!$this->isLinked()) {
            throw new DomainException("JsonModel isn't linked");
        }

        $linked_data = $this->upstream_model[$this->upstream_attribute];
        if ($this->upstream_key) {
            $linked_data = Arr::get($linked_data, $this->upstream_key, null);
        }
        return $linked_data;
    }

    /**
     * Apply changes to the upstream model, but don't persist them to the database.
     * This is equivalent to setting an attribute, e.g. $model->thing = 5 doesn't persist immediately
     * @return void
     * @throws DomainException|Exception if the JsonModel isn't linked
     */
    public function setLinkedData(): void
    {
        if (!$this->isLinked()) {
            throw new DomainException("JsonModel isn't linked");
        }

        if (in_array(HasJsonModelAttributes::class, class_uses_recursive($this))) {
            foreach ($this->jsonModelAttributeCache as $child) {
                $child->setLinkedData();
            }
        }

        if ($this instanceof CanValidate) {
            $this->validateOrThrow();
        }

        $new_value = $this->toArray();
        if ($this->upstream_key) {
            $whole_attribute = $this->upstream_model[$this->upstream_attribute];
            // The return value of array_set() is not "old with changes" it's way, way dumber than that
            Arr::set($whole_attribute, $this->upstream_key, $new_value);
            $new_value = $whole_attribute;
        }

        $this->upstream_model[$this->upstream_attribute] = $new_value;
    }

    /**
     * @param string $class
     * @return JsonModel|Model|null
     */
    public function getAncestorOfType(string $class)
    {
        if (!$this->isLinked()) {
            return null;
        }
        if (is_a($this->upstream_model, $class)) {
            return $this->upstream_model;
        }
        if (!method_exists($this->upstream_model, 'getAncestorOfType')) {
            return null;
        }
        return $this->upstream_model->getAncestorOfType($class);
    }

    /**
     * @param string $class
     * @return bool
     */
    public function isLinkedToInstanceOf(string $class): bool
    {
        return $this->isLinked()
            && $this->upstream_model instanceof $class;
    }
}
