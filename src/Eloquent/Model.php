<?php

namespace lroman242\LaravelCassandra\Eloquent;

use Carbon\Carbon;
use Cassandra\Date;
use Cassandra\Rows;
use Cassandra\Time;
use Cassandra\Timestamp;
use lroman242\LaravelCassandra\CassandraTypesTrait;
use lroman242\LaravelCassandra\Collection;
use lroman242\LaravelCassandra\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model as BaseModel;

abstract class Model extends BaseModel
{
    use CassandraTypesTrait;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'cassandra';

    /**
     * Indicates if the IDs are auto-incrementing.
     * This is not possible in cassandra so we override this
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The storage format of the model's time columns.
     *
     * @var string
     */
    protected $timeFormat;

    /**
     * List of columns in primary key
     *
     * @var array
     */
    protected $primaryColumns = [];

    /**
     * @inheritdoc
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * @inheritdoc
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder($connection, null, $connection->getPostProcessor());
    }

    /**
     * @inheritdoc
     */
    public function freshTimestamp()
    {
        return new Timestamp();
    }

    /**
     * @inheritdoc
     */
    public function fromDateTime($value)
    {
        // If the value is already a Timestamp instance, we don't need to parse it.
        if ($value instanceof Timestamp) {
            return $value;
        }

        // Let Eloquent convert the value to a DateTime instance.
        if (!$value instanceof \DateTime) {
            $value = parent::asDateTime($value);
        }

        return new Timestamp($value->getTimestamp() * 1000);
    }

    /**
     * @inheritdoc
     */
    protected function asDateTime($value)
    {
        // Convert UTCDateTime instances.
        if ($value instanceof Timestamp || $value instanceof Date) {
            return Carbon::instance($value->toDateTime());
        }

        return parent::asDateTime($value);
    }

    /**
     * Get the table qualified key name.
     * Cassandra does not support the table.column annotation so
     * we override this
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param string $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        return $column;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        // Unset method
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param Rows|array $rows
     *
     * @return Collection
     *
     * @throws \Exception
     */
    public function newCassandraCollection($rows)
    {
        if (!is_array($rows) && !$rows instanceof Rows) {
            throw new \Exception('Wrong type to create collection');//TODO: customize error
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->newFromBuilder($row);
        }

        $collection = new Collection($items);

        if ($rows instanceof Rows) {
            $collection->setRowsInstance($rows);
        }

        return $collection;
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     *
     * @param string $key
     * @param mixed $current
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function originalIsEquivalent($key, $current)
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $original = $this->getOriginal($key);

        if ($current === $original) {
            return true;
        } elseif (is_null($current)) {
            return false;
        } elseif ($this->isDateAttribute($key)) {
            return $this->fromDateTime($current) ===
                $this->fromDateTime($original);
        } elseif ($this->hasCast($key)) {
            return $this->castAttribute($key, $current) ===
                $this->castAttribute($key, $original);
        } elseif ($this->isCassandraValueObject($current)) {
            return $this->valueFromCassandraObject($current) ===
                $this->valueFromCassandraObject($original);
        }

        return is_numeric($current) && is_numeric($original)
            && strcmp((string)$current, (string)$original) === 0;
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
        $value = $this->getAttribute($this->getKeyName());

        if ($this->isCassandraValueObject($value)) {
            return $this->valueFromCassandraObject($this->getAttribute($this->getKeyName()));
        }

        return $value;
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return mixed
     *
     * @throws \Exception
     */
    protected function castAttribute($key, $value)
    {
        if ($this->getCastType($key) == 'string' && $value instanceof Time) {
            return (new \DateTime('today', new \DateTimeZone("+0")))
                ->modify('+' . $value->seconds() . ' seconds')
                ->format($this->getTimeFormat());
        }

        if ($this->getCastType($key) == 'int' && $value instanceof Time) {
            return $value->seconds();
        }

        return parent::castAttribute($key, $value);
    }

    /**
     * Get the format for time stored in database.
     *
     * @return string
     */
    public function getTimeFormat()
    {
        return $this->timeFormat ?: 'H:i:s';
    }

    /**
     * Get the format for time stored in database.
     *
     * @param string $format
     *
     * @return self
     */
    public function setTimeFormat($format)
    {
        $this->timeFormat = $format;

        return $this;
    }

    /**
     * Save the model to the database.
     *
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        $query = $this->newQueryWithoutScopes();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            if ($this->isDirty()) {
                // If any of primary key columns where updated cassandra won't be able
                // to process update of existed record. That is why existed record will
                // be deleted and inserted new one
                $dirtyKeys = array_keys($this->getDirty());
                $dirtyPrimaryKeys = array_intersect($this->primaryColumns, $dirtyKeys);
                $dirtyPrimaryExists = count($dirtyPrimaryKeys) > 0;

                // Check if any of primary key columns is dirty
                if (!$dirtyPrimaryExists) {
                    $primaryColumnsExceptId = array_diff($this->primaryColumns, [$this->primaryKey]);

                    foreach ($primaryColumnsExceptId as $key) {
                        $query->where($key, $this->attributes[$key]);
                    }

                    $saved = $this->performUpdate($query);
                } else {
                    $this->fireModelEvent('updating');

                    // Disable model deleting, deleted, creating and created events
                    $ed = $this->getEventDispatcher();
                    $this->unsetEventDispatcher();

                    $oldValues = $this->original;
                    // Insert new record (duplicate)
                    $saved = $this->performInsert($query);

                    // Delete old record
                    $deleteQuery = static::query();

                    foreach ($this->primaryColumns as $key) {
                        $deleteQuery->where($key, $oldValues[$key]);
                    }

                    $deleteQuery->delete();

                    //Already in silent mode
                    if ($ed !== null) {
                        $this->setEventDispatcher($ed);
                    }

                    $this->fireModelEvent('updated');
                }
            } else {
                $saved = true;
            }
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($query);

            if (!$this->getConnectionName() &&
                $connection = $query->getConnection()
            ) {
                $this->setConnection($connection->getName());
            }
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

}
