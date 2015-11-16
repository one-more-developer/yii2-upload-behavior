<?php
/**
 * @author Igor Prokofev <mr.igor.prokofev@gmail.com>
 * @link https://github.com/one-more-developer/yii2-upload-behavior
 */

namespace valiant\behaviors;

use PHPThumb\GD;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * Class ImageUploadBehavior
 * @package valiant\behaviors
 */
class ImageUploadBehavior extends FileUploadBehavior
{
    public $attribute = 'image';

    public $createThumbsOnSave = false;
    public $createThumbsOnRequest = false;

    /** @var array Thumbnail profiles, array of [width, height] */
    public $thumbs = [];

    /** @var string Path template to use in storing files. */
    public $filePath = '@webroot/images/[[pk]].[[extension]]';

    /** @var string Where to store images. */
    public $fileUrl = '/images/[[pk]].[[extension]]';

    /** @var string Path template for thumbnails. Please use the [[profile]] placeholder. */
    public $thumbPath = '@webroot/images/[[profile]]_[[pk]].[[extension]]';

    /** @var string Url template for thumbnails. */
    public $thumbUrl = '/images/[[profile]]_[[pk]].[[extension]]';

    /**
     * @inheritdoc
     */
    public function events()
    {
        return ArrayHelper::merge(parent::events(), [
            static::EVENT_AFTER_FILE_SAVE => 'afterFileSave',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function cleanFiles()
    {
        parent::cleanFiles();
        foreach (array_keys($this->thumbs) as $profile) {
            @unlink($this->getThumbFilePath($this->attribute, $profile));
        }
    }

    /**
     * Resolves profile path for thumbnail profile.
     *
     * @param string $path
     * @param string $profile
     * @return string
     */
    public function resolveProfilePath($path, $profile)
    {
        $path = $this->resolvePath($path);
        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($profile) {
            $name = $matches[1];
            $result = '[[' . $name . ']]';
            switch ($name) {
                case 'profile':
                    $result = $profile;
                    break;
                case 'width':
                case 'height':
                    $result = ArrayHelper::getValue($this->thumbs[$profile], $name, '-');
                    break;
            }
            return $result;
        }, $path);
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @return string
     */
    public function getThumbFilePath($attribute, $profile = 'thumb')
    {
        $behavior = static::getInstance($this->owner, $attribute);
        return $behavior->resolveProfilePath($behavior->thumbPath, $profile);
    }

    /**
     *
     * @param string $attribute
     * @param string|null $emptyUrl
     * @return string|null
     */
    public function getImageFileUrl($attribute, $emptyUrl = null)
    {
        return $this->owner->{$attribute} ? $this->getUploadedFileUrl($attribute, $emptyUrl) : $emptyUrl;
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @param string|null $emptyUrl
     * @return string|null
     */
    public function getThumbFileUrl($attribute, $profile = 'thumb', $emptyUrl = null)
    {
        if (!$this->owner->{$attribute}) {
            return $emptyUrl;
        }

        $behavior = static::getInstance($this->owner, $attribute);
        if ($behavior->createThumbsOnRequest) {
            $behavior->createThumbs();
        }

        return $behavior->resolveProfilePath($behavior->thumbUrl, $profile);
    }

    /**
     * After file save event handler.
     */
    public function afterFileSave()
    {
        if ($this->createThumbsOnSave) {
            $this->createThumbs();
        }
    }

    /**
     * Creates image thumbnails
     */
    public function createThumbs()
    {
        $path = $this->getUploadedFilePath($this->attribute);
        if(file_exists($path)) {
            foreach ($this->thumbs as $profile => $config) {
                $thumbPath = static::getThumbFilePath($this->attribute, $profile);
                if (!is_file($thumbPath)) {
                    /** @var GD $thumb */
                    $thumb = new GD($path);
                    if (isset($config['width']) && isset($config['height'])) {
                        $thumb->adaptiveResize($config['width'], $config['height']);
                    } else if (!isset($config['height'])) {
                        $thumb->resize($config['width']);
                    } else if (!isset($config['width'])) {
                        $thumb->resize($config['width']);
                    } else {
                        throw new InvalidConfigException('Either "width" or "height" properties must be specified.');
                    }
                    FileHelper::createDirectory(pathinfo($thumbPath, PATHINFO_DIRNAME), 0775, true);
                    $thumb->save($thumbPath);
                }
            }
        }
    }
}
