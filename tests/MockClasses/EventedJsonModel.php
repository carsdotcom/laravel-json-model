<?php

/**
 * This class implements every single supported handler.
 * When the handler fires, we increment a counter.
 */
declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\JsonModel;

/**
 * Class EventedJsonModel
 * @package Tests\MockClasses
 */
class EventedJsonModel extends JsonModel
{
    public const SCHEMA = 'https://unit.test/loose_json_model.json';

    /** @var bool What should the saving handler return? */
    public $saving_returns = true;

    /** @var bool What should the creating handler return? */
    public $creating_returns = true;

    /** @var bool What should the deleting handler return? */
    public $deleting_returns = true;

    protected $attributes = [
        'saving_fired' => 0,
        'saved_fired' => 0,
        'creating_fired' => 0,
        'created_fired' => 0,
        'deleted_fired' => 0,
    ];

    /** Note, no one cares what saved and created and deleted return */

    /**
     * Wire up all the observers with the simple boot method (instead of a separated Observer class)
     */
    public static function boot()
    {
        static::saving(function (EventedJsonModel $model) {
            $model->saving_fired += 1;
            return $model->saving_returns;
        });

        static::saved(function (EventedJsonModel $model) {
            $model->saved_fired += 1;
        });

        static::creating(function (EventedJsonModel $model) {
            $model->creating_fired += 1;
            return $model->creating_returns;
        });

        static::created(function (EventedJsonModel $model) {
            $model->created_fired += 1;
        });

        static::deleting(function (EventedJsonModel $model) {
            $model->deleting_fired += 1;
            return $model->deleting_returns;
        });

        static::deleting(function (EventedJsonModel $model) {
            $model->deleted_fired += 1;
        });

        parent::boot();
    }
}
