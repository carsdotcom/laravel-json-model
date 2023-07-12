<?php
/**
 * This class can take a single JsonModel (specifically a EventedJsonModel) on the attribute 'thing'
 * or a CollectionOfJsonModels (containing EventedJsonModels) on the attribute 'things'
 * This class implements every single supported handler.
 * When the handler fires, we increment a counter.
 */
declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property EventedJsonModel thing
 * @property CollectionOfJsonModels things
 */
class EventedModelWithJsonAttributes extends Model
{
    use HasJsonModelAttributes;

    protected $jsonModelAttributes = [
        'thing' => [EventedJsonModel::class, 'thing'],
        'things' => [EventedJsonModel::class, 'things', null, CollectionOfJsonModels::IS_A],
    ];

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
        static::saving(function (self $model) {
            $model->saving_fired += 1;
            return $model->saving_returns;
        });

        static::saved(function (self $model) {
            $model->saved_fired += 1;
        });

        static::creating(function (self $model) {
            $model->creating_fired += 1;
            return $model->creating_returns;
        });

        static::created(function (self $model) {
            $model->created_fired += 1;
        });

        static::deleting(function (self $model) {
            $model->deleting_fired += 1;
            return $model->deleting_returns;
        });

        static::deleting(function (self $model) {
            $model->deleted_fired += 1;
        });

        parent::boot();
    }

    // I'm not allowed to use the database, I have no table
    protected function performInsert(Builder $query)
    {
        return true;
    }

    protected function performUpdate(Builder $query)
    {
        return true;
    }
}
