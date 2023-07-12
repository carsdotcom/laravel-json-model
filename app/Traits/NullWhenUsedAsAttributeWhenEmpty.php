<?php
/**
 * When a model implements this trait, and is an attribute on a larger model (e.g. Vehicle->reservation)
 * if this model is empty or missing, it will return NULL when the getter runs.
 *
 * This makes it possible to write fluent conditionals because this object is routinely expected to be absent.
 * e.g.:
 *    if ($deal->cosigner)
 *    if ($vehicle->reservation)
 *    if ($vehicle->payments->dealer)
 *
 * The assumption is that these models will be created and saved atomically, not built up from individual props.
 * This will work:
 *     $deal->cosigner = new Person();
 *     $vehicle->reservation = ['message' => 'gimme'];
 *
 * This will NOT work if the dealer offer hasn't been started, these would throw "cannot set property 'type' of NULL"
 *     $vehicle->payments->dealer->type = 'loan';
 *     $vehicle->payments->dealer->down_payment = 123;
 */
declare(strict_types=1);

namespace Carsdotcom\LaravelJsonModel\Traits;

/**
 * Trait nullWhenUsedAsAttributeWhenEmpty
 * @package Carsdotcom\LaravelJsonModel\Traits
 */
trait NullWhenUsedAsAttributeWhenEmpty
{
    /**
     * This method controls the behavior of HasJsonModelAttributes, when you try to __get a Json Model Attribute
     * If the constructed version of this model is empty, the attribute accessor on the parent will return NULL
     * @return bool
     */
    public function nullWhenUsedAsAttribute(): bool
    {
        return $this->isEmpty();
    }

}
