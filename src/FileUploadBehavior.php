<?php
/**
 * @author Igor Prokofev <mr.igor.prokofev@gmail.com>
 * @link https://github.com/one-more-developer/yii2-upload-behavior
 */

namespace valiant\behaviors;

use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidCallException;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;

/**
 * Class FileUploadBehavior
 * @package valiant\behaviors
 *
 * @property ActiveRecord $owner
 */
class FileUploadBehavior extends Behavior
{
	const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

	/** @var string Name of attribute which holds the attachment. */
	public $attribute = 'attachment';

	/** @var string Path template to use in storing files. */
	public $filePath = '@webroot/uploads/[[pk]].[[extension]]';

	/** @var string Where to store images. */
	public $fileUrl = '/uploads/[[pk]].[[extension]]';

	/** @var UploadedFile */
	protected $file;

	/**
	 * @inheritdoc
	 */
	public function events()
	{
		return [
			ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
			ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
			ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
			ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
			ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
		];
	}

	/**
	 * Before validate event.
	 */
	public function beforeValidate()
	{
		if ($this->owner->{$this->attribute} instanceof UploadedFile) {
			$this->file = $this->owner->{$this->attribute};
		} else {
			$this->file = UploadedFile::getInstance($this->owner, $this->attribute);

			if (empty($this->file)) {
				$this->file = UploadedFile::getInstanceByName($this->attribute);
			}

			if ($this->file instanceof UploadedFile) {
				$this->owner->{$this->attribute} = $this->file;
			}
		}
	}

	/**
	 * Before save event.
	 *
	 * @throws \yii\base\InvalidConfigException
	 */
	public function beforeSave()
	{
		if ($this->file instanceof UploadedFile) {
			if (!$this->owner->isNewRecord) {
				/** @var ActiveRecord $oldModel */
				$oldModel = $this->owner->findOne($this->owner->primaryKey);
				$behavior = static::getInstance($oldModel, $this->attribute);
				$behavior->cleanFiles();
			}
			$this->owner->{$this->attribute} = $this->file->extension;
		} else {
			// Fix html forms bug, when we have empty file field
			if (!$this->owner->isNewRecord && empty($this->owner->{$this->attribute})) {
				$this->owner->{$this->attribute} = ArrayHelper::getValue($this->owner->oldAttributes, $this->attribute, null);
			}
		}
	}

	/**
	 * Removes files associated with attribute
	 */
	public function cleanFiles()
	{
		$path = $this->resolvePath($this->filePath);
		@unlink($path);
	}

	/**
	 * Replaces all placeholders in path variable with corresponding values
	 *
	 * @param string $path
	 * @return string
	 */
	public function resolvePath($path)
	{
		$path = Yii::getAlias($path);
		$primaryKey = implode('_', $this->owner->getPrimaryKey(true));
		$extension = strtolower($this->owner->{$this->attribute});

		return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($primaryKey, $extension) {
			$name = $matches[1];
			$result = '[[' . $name . ']]';
			switch ($name) {
				case 'model':
					$reflectionClass = new \ReflectionClass($this->owner->className());
					$result = lcfirst($reflectionClass->getShortName());
					break;
				case 'id':
				case 'pk':
					$result = lcfirst($primaryKey);
					break;
				case 'extension':
					$result = $extension;
					break;
				case 'attribute':
					$result = lcfirst($this->attribute);
					break;
				case 'id_path':
					$result = $this->makeIdPath($primaryKey);
					break;
				case 'id_hash':
					$result = $this->makeIdHashPath($primaryKey);
					break;
				case 'id_hash_piece':
					$result = $this->makeIdHashPiece($primaryKey);
					break;
				default:
					if (preg_match('|^attribute_(\w+)$|', $name, $attributeMatches)) {
						$attribute = $attributeMatches[1];
						$result = $this->owner->{$attribute};
					}
					break;
			}
			return $result;
		}, $path);
	}

	/**
	 * @param mixed $id
	 * @return string
	 */
	protected function makeIdPath($id)
	{
		$id = is_array($id) ? implode('', $id) : $id;
		$length = 10;
		$id = str_pad($id, $length, '0', STR_PAD_RIGHT);

		$result = [];
		for ($i = 0; $i < $length; $i++)
			$result[] = substr($id, $i, 1);

		return implode('/', $result);
	}

	/**
	 * @param mixed $id
	 * @return string
	 */
	protected function makeIdHashPath($id)
	{
		$hash = md5($id);
		return implode('/', [
			substr($hash, 0, 1),
			substr($hash, 1, 2),
			substr($hash, 3),
		]);
	}

	/**
	 * @param mixed $id
	 * @return number
	 */
	protected function makeIdHashPiece($id)
	{
		$hash = md5($id);
		return hexdec(substr($hash, 0, 2));
	}

	/**
	 * After save event.
	 */
	public function afterSave()
	{
		if ($this->file instanceof UploadedFile) {
			$path = $this->getUploadedFilePath($this->attribute);
			FileHelper::createDirectory(pathinfo($path, PATHINFO_DIRNAME), 0775, true);
			if (!$this->file->saveAs($path)) {
				throw new Exception('File saving error.');
			}
			$this->owner->trigger(static::EVENT_AFTER_FILE_SAVE);
		}
	}

	/**
	 * Returns file path for attribute.
	 *
	 * @param string $attribute
	 * @return string
	 */
	public function getUploadedFilePath($attribute)
	{
		$behavior = static::getInstance($this->owner, $attribute);
		return $this->owner->{$attribute} ? $behavior->resolvePath($behavior->filePath) : '';
	}

	/**
	 * Returns behavior instance for specified class and attribute
	 *
	 * @param ActiveRecord $model
	 * @param string $attribute
	 * @throws \yii\base\InvalidCallException
	 * @return static
	 */
	public static function getInstance(ActiveRecord $model, $attribute)
	{
		foreach ($model->behaviors as $behavior) {
			if ($behavior instanceof self && $behavior->attribute == $attribute) {
				return $behavior;
			}
		}

		throw new InvalidCallException('Missing behavior for attribute ' . VarDumper::dumpAsString($attribute));
	}

	/**
	 * Before delete event.
	 */
	public function beforeDelete()
	{
		$this->cleanFiles();
	}

	/**
	 * Returns file url for the attribute.
	 *
	 * @param string $attribute
	 * @return string|null
	 */
	public function getUploadedFileUrl($attribute)
	{
		if (!$this->owner->{$attribute}) {
			return null;
		}

		$behavior = static::getInstance($this->owner, $attribute);
		return $behavior->resolvePath($behavior->fileUrl);
	}
}
