<?php

/**
 * @author Chaim Leichman, MIPO Technologies Ltd
 */

namespace marena\meta;

use Yii;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\db\Query;
use yii\db\Schema;

trait MetaTrait
{

	/** @var boolean $autoLoadMetaData Whether meta data should be loaded */
	protected $autoLoadMetaData = true;

	/** @var boolean $autoSaveMetaFields Whether meta data should be saved */
	protected $autoSaveMetaFields = false;

	/** @var mixed $metaData Array of the this record's meta data */
	protected $metaData = null;

	/** @var array $metaDataUpdateQueue Queue of meta data key-value pairs to update */
	protected $metaDataUpdateQueue = [];

	/**
	 * Override __get of yii\db\ActiveRecord
	 *
	 * @param string $name the property name
	 * @return mixed
	 */
	public function __get($name)
	{
		$value = parent::__get($name);
		if(!isset($value) && !$this->hasAttribute($name)) {
			return $this->getMetaAttribute($name);
		}
		return $value;
	}

	public function setAttributes($values, $safeOnly = false)
	{
		if (is_array($values)) {
			$attributes = array_flip($safeOnly ? $this->safeAttributes() : $this->attributes());
			foreach ($values as $name => $value) {
				if (isset($attributes[$name])) {
					$this->$name = $value;
				} elseif (!in_array($name, $this->meta_unsafe)) {
					$this->$name = $value;
				}
			}
		}
	}

	/**
	 * @return array
	 */
	private function getMetaAttributes()
	{
		$attributes = [];
		foreach ($this->metaData as $item) {
			settype($item['meta_value'],$item['meta_type']);
			$attributes[$item['meta_key']] = $item['meta_value'];
		}
		return $attributes;
	}

	/**
	 * @return mixed
	 */
	public function getAllAttributes()
	{
		return ArrayHelper::merge($this->getAttributes(null, $this->except_attributes), $this->getMetaAttributes());
	}

	/**
	 * Returns attribute values.
	 * @param array $names list of attributes whose value needs to be returned.
	 * Defaults to null, meaning all attributes listed in [[attributes()]] will be returned.
	 * If it is an array, only the attributes in the array will be returned.
	 * @param array $except list of attributes whose value should NOT be returned.
	 * @param bool $metaData retrun metadat.
	 * @return array attribute values (name => value).
	 */
	public function getAttributes($names = null, $except = [], $metaData = true)
	{
		$values = [];
		if ($names === null) {
			$names = $this->attributes();
		}
		foreach ($names as $name) {
			$values[$name] = $this->$name;
		}

		if ($metaData) {
			$values = ArrayHelper::merge($values, $this->getMetaAttributes());
		}

		$except = ArrayHelper::merge($except, $this->except_attributes);

		foreach ($except as $name) {
			unset($values[$name]);
		}

		return $values;
	}

	/**
	 * Override __get of yii\db\ActiveRecord
	 *
	 * @param string $name the property name or the event name
	 * @param mixed $value the property value
	 */
	public function __set($name, $value)
	{
		if ($this->hasAttribute($name))
			parent::__set($name, $value);
		else {
			if ($this->autoSaveMetaFields && !$this->isNewRecord)
				$this->setMetaAttribute($name, $value);
			else
				$this->enqueueMetaUpdate($name, $value);
		}
	}

	/**
	 * Catch the afterFind event to load the meta data if the
	 * $autoLoadMetaData flag is set to true
	 *
	 */
	public function afterFind()
	{
		parent::afterFind();

		if ($this->autoLoadMetaData)
			$this->loadMetaData();
	}

	/**
	 * Catch the afterSave event to save all of the queued meta data
	 *
	 */
	public function afterSave($insert, $changedAttributes)
	{
		$queue = $this->metaDataUpdateQueue;

		if (is_array($queue) && count($queue)) {
			foreach ($queue as $name => $value)
				$this->setMetaAttribute($name, $value);

			$this->metaDataUpdateQueue = array();
		}

		parent::afterSave($insert, $changedAttributes);
	}

	/**
	 * Enqueue a meta key-value pair to be saved when the record is saved
	 *
	 * @param string $name the property name or the event name
	 * @param mixed $value the property value
	 */
	protected function enqueueMetaUpdate($name, $value)
	{
		if (!is_array($this->metaDataUpdateQueue))
			$this->metaDataUpdateQueue = array();

		$this->metaDataUpdateQueue[$name] = $value;
	}

	/**
	 * Load the meta data for this record
	 *
	 * @return void
	 */
	protected function loadMetaData()
	{
		$rows = (new Query)
			->select('*')
			->from($this->metaTableName())
			->where([
				self::tableName() . '_id' => $this->{$this->getPkName()}
			])
			->all();

		$this->metaData = $rows;
	}

	/**
	 * Return the name of the meta table associated with this model
	 *
	 * @return string
	 */
	public function metaTableName()
	{
		$tblName = self::tableName() . '_meta';
		return $tblName;
	}

	/**
	 * @link https://github.com/yiisoft/yii2/issues/6533
	 * @return string
	 */
	public function getDbName()
	{
		$db = Yii::$app->db;
		$dsn = $db->dsn;
		$name = 'dbname';

		if (preg_match('/' . $name . '=([^;]*)/', $dsn, $match)) {
			return $match[1];
		} else {
			return null;
		}
	}

	/**
	 * @param boolean $autoCreate Create the table if it does not exist
	 * @return boolean If table exists
	 */
	protected function assertMetaTable($autoCreate = false)
	{
		$row = (new Query)
			->select('*')
			->from('information_schema.tables')
			->where([
				'table_schema' => $this->getDbName(),
				'table_name' => $this->metaTableName()
			])
			->limit(1)
			->all();

		if (null === $row) {
			if ($autoCreate) {
				$this->createMetaTable();
				return true;
			} else
				return false;
		} else
			return true;
	}

	/**
	 *
	 */
	protected function createMetaTable()
	{
		$db = Yii::$app->db;
		$tbl = $this->metaTableName();

		$ret = $db
			->createCommand()
			->createTable($tbl, [
				'id' => Schema::TYPE_BIGPK,
				self::tableName() . '_id' => Schema::TYPE_BIGINT . ' NOT NULL default \'0\'',
				'meta_key' => Schema::TYPE_STRING . ' default NULL',
				'meta_value' => 'longtext',
				'meta_type' => 'varcher(32)',
			], 'ENGINE=MyISAM  DEFAULT CHARSET=utf8')
			->execute();

		if ($ret) {
			$db
				->createCommand()
				->createIndex('UNIQUE_META_RECORD', $tbl, [self::tableName() . '_id', 'meta_key'], true)
				->execute();
		}

		return $ret;
	}

	protected function getPkName()
	{
		$pk = $this->primaryKey();
		$pk = $pk[0];

		return $pk;
	}

	/**
	 * Return the value of the named meta attribute
	 *
	 * @param string $name Property name
	 * @return mixed Property value
	 */
	protected function getMetaAttribute($name)
	{
		if (!$this->assertMetaTable())
			return null;

		$row = (new Query)
			->select('meta_value, meta_type')
			->from($this->metaTableName())
			->where([
				self::tableName() . '_id' => $this->{$this->getPkName()},
				'meta_key' => $name
			])
			->limit(1)
			->one();

		return is_array($row) ? $row['meta_value'] : null;
	}

	/**
	 * Set the value of the named meta attribute
	 *
	 * @param string $name the property name or the event name
	 * @param mixed $value the property value
	 */
	protected function setMetaAttribute($name, $value)
	{
		// Assert that the meta table exists,
		// and create it if it does not
		$this->assertMetaTable(true);

		$db = Yii::$app->db;
		$tbl = $this->metaTableName();

		$pk = $this->getPkName();

		// Check if we need to create a new record or update an existing record
		$currentVal = $this->getMetaAttribute($name);
		if (is_null($currentVal)) {
			if (is_null($value))
				return null;

			$ret = $db
				->createCommand()
				->insert($tbl, [
					self::tableName() . '_id' => $this->{$pk},
					'meta_key' => $name,
					'meta_value' => is_scalar($value) ? $value : serialize($value),
					'meta_type' => gettype($value),
				])
				->execute();
		} else {
			if (!is_null($value)) {

				$ret = $db
					->createCommand()
					->update($tbl, [
						'meta_value' => is_scalar($value) ? $value : serialize($value),
						'meta_type' => gettype($value),
					], self::tableName() . "_id = '{$this->$pk}' AND meta_key = '{$name}'")
					->execute();

			} else {

				$ret = $db
					->createCommand()
					->delete($tbl, self::tableName() . "_id = '{$this->$pk}' AND meta_key = '{$name}'")
					->execute();
			}
		}

		// If update succeeded, save the new value right away
		if ($ret)
			$this->metaData[$name] = $value;

		return $ret;
	}
}
