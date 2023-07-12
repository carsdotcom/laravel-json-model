<?php

/**
 * If you can have children who have observers (e.g. a JsonModel with JsonModelAttributes,
 * or a CollectionOfJsonModels) this interface provides clear methods to tell your
 * children to fire their observers.
 */

namespace Carsdotcom\LaravelJsonModel\Contracts;

/**
 * Interface CanCascadeEvents
 * @package Carsdotcom\LaravelJsonModel\Contracts
 */
interface CanCascadeEvents
{
    /**
     * Tell all children of this object to execute preSave observers.
     * If *any* of them fail, return a false. Otherwise return true.
     * @return bool
     */
    public function preSave(): bool;

    /**
     * Tell all children of this object to execute postSave observers.
     */
    public function postSave(): void;
}
