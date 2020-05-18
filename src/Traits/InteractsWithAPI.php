<?php

namespace MacsiDigital\API\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use MacsiDigital\API\Support\Builder;
use MacsiDigital\API\Traits\ForwardsCalls;
use MacsiDigital\API\Exceptions\KeyNotFoundException;
use MacsiDigital\API\Exceptions\InvalidActionException;
use MacsiDigital\API\Exceptions\ValidationFailedException;

trait InteractsWithAPI
{
    use ForwardsCalls;

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * Indicates if the model was inserted during the current request lifecycle.
     *
     * @var bool
     */
    public $wasRecentlyCreated = false;
    
    protected $allowedMethods = ['get', 'post', 'patch', 'put', 'delete'];

    protected $endPoint = 'user';

    protected $createMethod = 'post';

    protected $updateMethod = 'patch';

    protected $storeResource;
    protected $updateResource;

    protected $primaryKey = 'id';

    // Most API's return data in a data attribute.  However we need to override on a model basis as some like Xero return it as 'Users' or 'Invoices'
    protected $apiDataField = 'data';

    public function query()
    {
        $class = $this->client->getBuilderClass();
        return new $class($this);
    }

    public function newQuery() 
    {
        return $this->query($this);
    }

    public function getApiDataField()
    {
        return $this->apiDataField;
    }

    public function getUpdateMethod()
    {
        return $this->updateMethod;
    }

    public function getCreateMethod()
    {
        return $this->createMethod;
    }

    public function getKeyName()
    {
        return $this->primaryKey;
    }

    public function setKeyName($key)
    {
        $this->primaryKey = $key;

        return $this;
    }

    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    public function exists()
    {
        return $this->getKey() != null;
    }

    public function getEndPoint($type='get') 
    {
        if($this->canPerform($type)){
            return $this->{'get'.Str::studly($type).'EndPoint'}();
        } else {
            throw new InvalidActionException(sprintf(
                '%s action not allowed for %s', Str::studly($type), static::class
            ));
        }
    }

    public function canPerform($type) 
    {
        return in_array($type, $this->getAllowedMethods());
    }

    public function cantPerform($type) 
    {
        return ! $this->canPerform($type);
    }

    public function getAllowedMethods() 
    {
        return $this->allowedMethods;
    }

    public function getGetEndPoint() 
    {
        return $this->endPoint;
    }

    public function getPostEndPoint() 
    {
        return $this->endPoint;
    }

    public function getPatchEndPoint() 
    {
        if($this->exists()){
            return $this->endPoint.'/'.$this->getKey();
        }
        throw new KeyNotFoundException(static::class);
    }

    public function getPutEndPoint() 
    {
        if($this->exists()){
            return $this->endPoint.'/'.$this->getKey();
        }
        throw new KeyNotFoundException(static::class);
    }

    public function getDeleteEndPoint() 
    {
        if($this->exists()){
            return $this->endPoint.'/'.$this->getKey();
        }
        throw new KeyNotFoundException(static::class);
    }

    /**
     * Update the model in the database.
     *
     * @param  array  $attributes
     * @param  array  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (!$this->exists()) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }

    /**
     * Save the model and all of its relationships.
     *
     * @return bool
     */
    public function push()
    {
        if (! $this->save()) {
            return false;
        }

        // To sync all of the relationships to the database, we will simply spin through
        // the relationships and save each model via this "push" method, which allows
        // us to recurse into all of these nested relations for the model instance.
        foreach ($this->relations as $models) {
            $models = $models instanceof Collection
                        ? $models->all() : [$models];

            foreach (array_filter($models) as $model) {
                if (! $model->push()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update the model in the database.
     *
     * @param  array  $attributes
     * @param  array  $options
     * @return bool
     */
    public function create(array $attributes = [], array $options = [])
    {
        if ($this->exists()) {
            return false;
        }
        
        return $this->fill($attributes)->save($options);
    }

    /**
     * Save the model to the database.
     *
     * @param  array  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $query = $this->newQuery();

        $this->beforeSave($options);
        
        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists()) {
            if($this->isDirty()){
                $resource = $this->performUpdate($query);
            } else {
                $resource = $this;
            }
        }
        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $resource = $this->performInsert($query);
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if (!$resource->hasApiError()) {
            $this->afterSave($options);
        }
        
        return $resource;
    }

    public function hasApiError() 
    {
        return isset($this->status_code);
    }

    /**
     * Perform any actions that are necessary after the model is saved.
     *
     * @param  array  $options
     * @return void
     */
    protected function finishSave(array $options)
    {
        $this->syncOriginal();
    }

    /**
     * Perform a model update operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            // Need to think over this as only need to pass Dirty Attributes
            $resource = (new $this->updateResource)->fill($this->getDirty());
            
            $validator = $resource->validate();

            if ($validator->fails()) {
                throw new ValidationFailedException($validator->errors());
            }

            $resource = $query->{$this->getUpdateMethod()}($resource->getAttributes());

            $this->syncChanges();

            return $resource;
        } else {
            return $this;
        }
    }

    /**
     * Perform a model insert operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        $resource = (new $this->storeResource)->fill($this->package()->toArray());
        
        $validator = $resource->validate();

        if ($validator->fails()) {
            throw new ValidationFailedException($validator->errors());
        }

        $resource = $query->{$this->getCreateMethod()}($resource->getAttributes());

        $resource->exists = true;

        $resource->wasRecentlyCreated = true;

        return $resource;
    }

    public function beforeSave()
    {
        
    }

    public function afterSave()
    {
        
    }

    /**
     * Destroy the models for the given IDs.
     *
     * @param  \Illuminate\Support\Collection|array|int  $ids
     * @return int
     */
    public static function destroy($ids)
    {
        // We'll initialize a count here so we will return the total number of deletes
        // for the operation. The developers can then check this number as a boolean
        // type value or get this total count of records deleted for logging, etc.
        $count = 0;

        if ($ids instanceof Collection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        // We will actually pull the models from the database table and call delete on
        // each of them individually so that their events get fired properly with a
        // correct set of attributes in case the developers wants to check these.
        $key = ($instance = new static)->getKeyName();

        foreach ($instance->whereIn($key, $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete the model from the database.
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    public function delete()
    {
        $query = $this->newQuery();

        if (is_null($this->getKeyName())) {
            throw new Exception('No primary key defined on model.');
        }

        if (! $this->exists()) {
            return;
        }
        $this->beforeDeleting();

        $response = $query->delete();

        if($response){
            $this->afterDeleting();
        }
        return $response;
    }

    public function beforeDeleting()
    {
        
    }

    public function afterDeleting()
    {
        
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
        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param  array  $models
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function newCollection(array $models = [])
    {
        return new Collection($models);
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * Convert the model instance to JSON.
     *
     * @param  int  $options
     * @return string
     *
     * @throws \Illuminate\Database\Eloquent\JsonEncodingException
     */
    public function toJson($options = 0)
    {
        $json = json_encode($this->jsonSerialize(), $options);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw JsonEncodingException::forModel($this, json_last_error_msg());
        }

        return $json;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function fresh() 
    {
        if (! $this->exists) {
            return;
        }

        return $this->newQuery()->find($this->getKey());
    }

    /**
     * Reload the current model instance with fresh attributes from the database.
     *
     * @return $this
     */
    public function refresh()
    {
        if (! $this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            $this->fresh()->attributes
        );

        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * @param  array|null  $except
     * @return static
     */
    public function replicate(array $except = null)
    {
        $defaults = [
            $this->getKeyName()
        ];

        $attributes = Arr::except(
            $this->attributes, $except ? array_unique(array_merge($except, $defaults)) : $defaults
        );

        return tap(new static, function ($instance) use ($attributes) {
            $instance->setRawAttributes($attributes);

            $instance->setRelations($this->relations);
        });
    }

    /**
     * Determine if two models have the same ID and belong to the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return bool
     */
    public function is($model)
    {
        return ! is_null($model) &&
               get_class($this) === get_class($model) &&
               $this->getKey() === $model->getKey();
    }

    /**
     * Determine if two models are not the same.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return bool
     */
    public function isNot($model)
    {
        return ! $this->is($model);
    }

}