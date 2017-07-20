<?php
/**
 * Upload behavior
 *
 * Enables users to easily add file uploading and necessary validation rules
 *
 * PHP versions 4 and 5
 *
 * Copyright 2010, Jose Diaz-Gonzalez
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2010, Jose Diaz-Gonzalez
 * @package       upload
 * @subpackage    upload.models.behaviors
 * @link          http://github.com/josegonzalez/upload
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::uses('Folder', 'Utility');
App::uses('UploadException', 'Upload.Lib/Error/Exception');
class UploadBehavior extends ModelBehavior {

	public $defaults = array(
		'rootDir'			=> null,
		'pathMethod'		=> 'primaryKey',
		'path'				=> '{ROOT}webroot{DS}files{DS}{model}{DS}{field}{DS}',
		'fields'			=> array('dir' => 'dir', 'type' => 'type', 'size' => 'size'),
		'mimetypes'			=> array(),
		'extensions'		=> array(),
		'maxSize'			=> 2097152,
		'minSize'			=> 8,
		'maxHeight'			=> 0,
		'minHeight'			=> 0,
		'maxWidth'			=> 0,
		'minWidth'			=> 0,
		'thumbnails'		=> true,
		'thumbnailMethod'	=> 'imagick',
		'thumbnailName'		=> null,
		'thumbnailPath'		=> null,
		'thumbnailPrefixStyle'=> true,
		'thumbnailQuality'	=> 75,
		'thumbnailSizes'	=> array(),
		'thumbnailType'		=> false,
		'deleteOnUpdate'	=> false,
		'mediaThumbnailType'=> 'png',
		'saveDir'			=> true,
		'deleteFolderOnDelete' => false,
);

	protected $_imageMimetypes = array(
		'image/bmp',
		'image/gif',
		'image/jpeg',
		'image/pjpeg',
		'image/png',
		'image/vnd.microsoft.icon',
		'image/x-icon',
	);

	protected $_mediaMimetypes = array(
		'application/pdf',
		'application/postscript',
	);

	protected $_pathMethods = array('flat', 'primaryKey', 'random', 'randomCombined');

	protected $_resizeMethods = array('imagick', 'php');

	private $__filesToRemove = array();

	private $__foldersToRemove = array();

	protected $_removingOnly = array();

/**
 * Runtime configuration for this behavior
 *
 * @var array
 **/
	public $runtime;

/**
 * Initiate Upload behavior
 *
 * @param object $Model instance of model
 * @param array $config array of configuration settings.
 * @return void
 * @access public
 */
	public function setup(Model $Model, $config = array()) {
		if (isset($this->settings[$Model->alias])) return;
		$this->settings[$Model->alias] = array();

		foreach ($config as $field => $options) {
			$this->_setupField($Model, $field, $options);
		}
	}

/**
 * Setup a particular upload field
 *
 * @param AppModel $Model Model instance
 * @param string $field Name of field being modified
 * @param array $options array of configuration settings for a field
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	public function _setupField(Model $Model, $field, $options) {
		if (is_int($field)) {
			$field = $options;
			$options = array();
		}

		$this->defaults['rootDir'] = ROOT . DS . APP_DIR . DS;
		if (!isset($this->settings[$Model->alias][$field])) {
			$options = array_merge($this->defaults, (array) $options);

			// HACK: Remove me in next major version
			if (!empty($options['thumbsizes'])) {
				$options['thumbnailSizes'] = $options['thumbsizes'];
			}

			if (!empty($options['prefixStyle'])) {
				$options['thumbnailPrefixStyle'] = $options['prefixStyle'];
			}
			// ENDHACK

			$options['fields'] += $this->defaults['fields'];
			if ($options['rootDir'] === null) {
				$options['rootDir'] = $this->defaults['rootDir'];
			}

			if ($options['thumbnailName'] === null) {
				if ($options['thumbnailPrefixStyle']) {
					$options['thumbnailName'] = '{size}_{filename}';
				} else {
					$options['thumbnailName'] = '{filename}_{size}';
				}
			}

			if ($options['thumbnailPath'] === null) {
				$options['thumbnailPath'] = Folder::slashTerm($this->_path($Model, $field, array(
					'isThumbnail' => true,
					'path' => $options['path'],
					'rootDir' => $options['rootDir']
				)));
			} else {
				$options['thumbnailPath'] = Folder::slashTerm($this->_path($Model, $field, array(
					'isThumbnail' => true,
					'path' => $options['thumbnailPath'],
					'rootDir' => $options['rootDir']
				)));
			}

			$options['path'] = Folder::slashTerm($this->_path($Model, $field, array(
				'isThumbnail' => false,
				'path' => $options['path'],
				'rootDir' => $options['rootDir']
			)));

			if (!in_array($options['thumbnailMethod'], $this->_resizeMethods)) {
				$options['thumbnailMethod'] = 'imagick';
			}
			if (!in_array($options['pathMethod'], $this->_pathMethods)) {
				$options['pathMethod'] = 'primaryKey';
			}
			$options['pathMethod'] = '_getPath' . Inflector::camelize($options['pathMethod']);
			$options['thumbnailMethod'] = '_resize' . Inflector::camelize($options['thumbnailMethod']);
			$this->settings[$Model->alias][$field] = $options;
		}
	}

/**
 * Convenience method for configuring UploadBehavior settings
 *
 * @param AppModel $Model Model instance
 * @param string $field Name of field being modified
 * @param mixed $one A string or an array of data.
 * @param mixed $two Value in case $one is a string (which then works as the key).
 *   Unused if $one is an associative array, otherwise serves as the values to $one's keys.
 * @return void
 */
	public function uploadSettings(Model $Model, $field, $one, $two = null) {
		if (empty($this->settings[$Model->alias][$field])) {
			$this->_setupField($Model, $field, array());
		}

		$data = array();

		if (is_array($one)) {
			if (is_array($two)) {
				$data = array_combine($one, $two);
			} else {
				$data = $one;
			}
		} else {
			$data = array($one => $two);
		}
		$this->settings[$Model->alias][$field] = $data + $this->settings[$Model->alias][$field];
	}

/**
 * Before save method. Called before all saves
 *
 * Handles setup of file uploads
 *
 * @param AppModel $Model Model instance
 * @return boolean
 */
	public function beforeSave(Model $Model, $created = false, $options = array()) {
		$this->_removingOnly = array();
		foreach ($this->settings[$Model->alias] as $field => $options) {
			if (!isset($Model->data[$Model->alias][$field])) continue;
			if (!is_array($Model->data[$Model->alias][$field])) continue;
			if (!isset($Model->data[$Model->alias][$field]['name'])) continue;
			
			// add a unique id string to the begining of the file name to take care of a deletion issue
			$Model->data[$Model->alias][$field]['name'] = date('YmdHis'). '_'. $Model->data[$Model->alias][$field]['name'];

			$this->runtime[$Model->alias][$field] = $Model->data[$Model->alias][$field];

			$removing = !empty($Model->data[$Model->alias][$field]['remove']);
			if ($removing || ($this->settings[$Model->alias][$field]['deleteOnUpdate']
			&& isset($Model->data[$Model->alias][$field]['name'])
			&& strlen($Model->data[$Model->alias][$field]['name']))) {
				// We're updating the file, remove old versions
				if (!empty($Model->id)) {
					$data = $Model->find('first', array(
						'conditions' => array("{$Model->alias}.{$Model->primaryKey}" => $Model->id),
						'contain' => false,
						'recursive' => -1,
					));
					$this->_prepareFilesForDeletion($Model, $field, $data, $options);
				}

				if ($removing) {
					$Model->data[$Model->alias] = array(
						$field => null,
						$options['fields']['type'] => null,
						$options['fields']['size'] => null,
						$options['fields']['dir'] => null,
					);

					$this->_removingOnly[$field] = true;
					continue;
				} else {
					$Model->data[$Model->alias][$field] = array(
						$field => null,
						$options['fields']['type'] => null,
						$options['fields']['size'] => null,
					);
				}
			} elseif (!isset($Model->data[$Model->alias][$field]['name'])
			|| !strlen($Model->data[$Model->alias][$field]['name'])) {
				// if field is empty, don't delete/nullify existing file
				unset($Model->data[$Model->alias][$field]);
				continue;
			}

			$Model->data[$Model->alias] = array_merge($Model->data[$Model->alias], array(
				$field => $this->runtime[$Model->alias][$field]['name'],
				$options['fields']['type'] => $this->runtime[$Model->alias][$field]['type'],
				$options['fields']['size'] => $this->runtime[$Model->alias][$field]['size']
			));
		}
		return true;
	}

	public function afterSave(Model $Model, $created = false, $options = array()) {
		$temp = array($Model->alias => array());
		foreach ($this->settings[$Model->alias] as $field => $options) {
			if (!isset($Model->data[$Model->alias])) continue;
			if (!in_array($field, array_keys($Model->data[$Model->alias]))) continue;
			if (empty($this->runtime[$Model->alias][$field])) continue;
		        if (isset($this->_removingOnly[$field])) continue;

			$tempPath = $this->_getPath($Model, $field);

			$path = $this->settings[$Model->alias][$field]['path'];
			$thumbnailPath = $this->settings[$Model->alias][$field]['thumbnailPath'];

			if (!empty($tempPath)) {
				$path .= $tempPath . DS;
				$thumbnailPath .= $tempPath . DS;
			}
			$tmp = $this->runtime[$Model->alias][$field]['tmp_name'];
			$filePath = $path . $Model->data[$Model->alias][$field];
			if (!$this->handleUploadedFile($Model->alias, $field, $tmp, $filePath)) {
				$Model->invalidate($field, 'Unable to move the uploaded file to '.$filePath);
				throw new UploadException('Unable to upload file');
			}

			$this->_createThumbnails($Model, $field, $path, $thumbnailPath);
			if ($Model->hasField($options['fields']['dir'])) {
				if ($created && $options['pathMethod'] == '_getPathFlat') {
				} else if ($options['saveDir']) {
					$temp[$Model->alias][$options['fields']['dir']] = "'{$tempPath}'";
				}
			}
		}
		
		if (!empty($temp[$Model->alias])) {
			$Model->updateAll($temp[$Model->alias], array(
				$Model->alias.'.'.$Model->primaryKey => $Model->id
			));
		}
		if (empty($this->__filesToRemove[$Model->alias])) return true;
		foreach ($this->__filesToRemove[$Model->alias] as $file) {
			$result[] = $this->unlink($file);
		}
		return $result;
	}

	public function handleUploadedFile($ModelAlias, $field, $tmp, $filePath) {
		return is_uploaded_file($tmp) && @move_uploaded_file($tmp, $filePath);
	}

	public function unlink($file) {
		return @unlink($file);
	}

	public function deleteFolder($Model, $path) {
		$folders = $this->__foldersToRemove[$Model->alias];
		foreach ( $folders as $folder ) {
			$dir = $path . $folder;
			$it = new RecursiveDirectoryIterator($dir);
			$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
			foreach($files as $file) {
				if ($file->getFilename() === '.' || $file->getFilename() === '..') {
					continue;
				}
				if ($file->isDir()) {
					@rmdir($file->getRealPath());
				} else {
					@unlink($file->getRealPath());
				}
			}
			@rmdir($dir);
		}
	}

	public function beforeDelete(Model $Model, $cascade = true) {
		$data = $Model->find('first', array(
			'conditions' => array("{$Model->alias}.{$Model->primaryKey}" => $Model->id),
			'contain' => false,
			'recursive' => -1,
		));

		foreach ($this->settings[$Model->alias] as $field => $options) {
			$this->_prepareFilesForDeletion($Model, $field, $data, $options);
		}
		return true;
	}

	public function afterDelete(Model $Model) {
		$result = array();
		if (!empty($this->__filesToRemove[$Model->alias])) {
			foreach ($this->__filesToRemove[$Model->alias] as $file) {
				$result[] = $this->unlink($file);
			}
		}

		foreach ($this->settings[$Model->alias] as $field => $options) {
			if ($options['deleteFolderOnDelete'] == true) {
				$this->deleteFolder($Model, $options['path']);
				return true;
			}
		}
		return $result;
	}

/**
 * Verify that the uploaded file has been moved to the
 * destination successfully. This rule is special that it
 * is invalidated in afterSave(). Therefore it is possible
 * for save() to return true and this rule to fail.
 *
 * @param Object $Model
 * @return boolean Always true
 * @access public
 */
	public function moveUploadedFile(Model $Model) {
		return true;
	}
/**
 * Check that the file does not exceed the max
 * file size specified by PHP
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isUnderPhpSizeLimit(Model $Model, $check) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_INI_SIZE;
	}

/**
 * Check that the file does not exceed the max
 * file size specified in the HTML Form
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isUnderFormSizeLimit(Model $Model, $check) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_FORM_SIZE;
	}

/**
 * Check that the file was completely uploaded
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isCompletedUpload(Model $Model, $check) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_PARTIAL;
	}

/**
 * Check that a file was uploaded
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isFileUpload(Model $Model, $check) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_NO_FILE;
	}

/**
 * Check that the PHP temporary directory is missing
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function tempDirExists(Model $Model, $check, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_NO_TMP_DIR;
	}

/**
 * Check that the file was successfully written to the server
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function isSuccessfulWrite(Model $Model, $check, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_CANT_WRITE;
	}

/**
 * Check that a PHP extension did not cause an error
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @return boolean Success
 * @access public
 */
	public function noPhpExtensionErrors(Model $Model, $check, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return $check[$field]['error'] !== UPLOAD_ERR_EXTENSION;
	}

/**
 * Check that the file is of a valid mimetype
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param array $mimetypes file mimetypes to allow
 * @return boolean Success
 * @access public
 */
	public function isValidMimeType(Model $Model, $check, $mimetypes = array(), $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the mimetype is invalid
		if (!isset($check[$field]['type']) || !strlen($check[$field]['type'])) {
			return false;
		}

		// Sometimes the user passes in a string instead of an array
		if (is_string($mimetypes)) {
			$mimetypes = array($mimetypes);
		}

		foreach ($mimetypes as $key => $value) {
			if (!is_int($key)) {
				$mimetypes = $this->settings[$Model->alias][$field]['mimetypes'];
				break;
			}
		}

		if (empty($mimetypes)) $mimetypes = $this->settings[$Model->alias][$field]['mimetypes'];

		return in_array($check[$field]['type'], $mimetypes);
	}

/**
 * Check that the upload directory is writable
 *
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param string $path Full upload path
 * @return boolean Success
 * @access public
 */
	public function isWritable(Model $Model, $check, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return is_writable($this->settings[$Model->alias][$field]['path']);
	}

/**
 * Check that the upload directory exists
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param string $path Full upload path
 * @return boolean Success
 * @access public
 */
	public function isValidDir(Model $Model, $check, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		return is_dir($this->settings[$Model->alias][$field]['path']);
	}

/**
 * Check that the file is below the maximum file upload size
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $size Maximum file size
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxSize(Model $Model, $check, $size = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the size is too small
		if (!isset($check[$field]['size']) || !strlen($check[$field]['size'])) {
			return false;
		}

		if (!$size) $size = $this->settings[$Model->alias][$field]['maxSize'];

		return $check[$field]['size'] <= $size;
	}

/**
 * Check that the file is above the minimum file upload size
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $size Minimum file size
 * @return boolean Success
 * @access public
 */
	public function isAboveMinSize(Model $Model, $check, $size = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the size is too small
		if (!isset($check[$field]['size']) || !strlen($check[$field]['size'])) {
			return false;
		}

		if (!$size) $size = $this->settings[$Model->alias][$field]['minSize'];

		return $check[$field]['size'] >= $size;
	}

/**
 * Check that the file has a valid extension
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param array $extensions file extenstions to allow
 * @return boolean Success
 * @access public
 */
	public function isValidExtension(Model $Model, $check, $extensions = array(), $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the extension is invalid
		if (!isset($check[$field]['name']) || !strlen($check[$field]['name'])) {
			return false;
		}

		// Sometimes the user passes in a string instead of an array
		if (is_string($extensions)) {
			$extensions = array($extensions);
		}

		// Sometimes a user does not specify any extensions in the validation rule
		foreach ($extensions as $key => $value) {
			if (!is_int($key)) {
				$extensions = $this->settings[$Model->alias][$field]['extensions'];
				break;
			}
		}

		if (empty($extensions)) $extensions = $this->settings[$Model->alias][$field]['extensions'];
		$pathInfo = $this->_pathinfo($check[$field]['name']);

		$extensions = array_map('strtolower', $extensions);
		return in_array(strtolower($pathInfo['extension']), $extensions);
	}

/**
 * Check that the file is above the minimum height requirement
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $height Height of Image
 * @return boolean Success
 * @access public
 */
	public function isAboveMinHeight(Model $Model, $check, $height = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$height) $height = $this->settings[$Model->alias][$field]['minHeight'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $height > 0 && $imgHeight >= $height;
	}

/**
 * Check that the file is below the maximum height requirement
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $height Height of Image
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxHeight(Model $Model, $check, $height = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$height) $height = $this->settings[$Model->alias][$field]['maxHeight'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $height > 0 && $imgHeight <= $height;
	}

/**
 * Check that the file is above the minimum width requirement
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $width Width of Image
 * @return boolean Success
 * @access public
 */
	public function isAboveMinWidth(Model $Model, $check, $width = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$width) $width = $this->settings[$Model->alias][$field]['minWidth'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $width > 0 && $imgWidth >= $width;
	}

/**
 * Check that the file is below the maximum width requirement
 *
 * @param Object $Model
 * @param mixed $check Value to check
 * @param int $width Width of Image
 * @return boolean Success
 * @access public
 */
	public function isBelowMaxWidth(Model $Model, $check, $width = null, $requireUpload = true) {
		$field = $this->_getField($check);

		if (!empty($check[$field]['remove'])) {
			return true;
		}

		// Allow circumvention of this rule if uploads is not required
		if (!$requireUpload && $check[$field]['error'] === UPLOAD_ERR_NO_FILE) {
			return true;
		}

		// Non-file uploads also mean the height is too big
		if (!isset($check[$field]['tmp_name']) || !strlen($check[$field]['tmp_name'])) {
			return false;
		}

		if (!$width) $width = $this->settings[$Model->alias][$field]['maxWidth'];

		list($imgWidth, $imgHeight) = getimagesize($check[$field]['tmp_name']);
		return $width > 0 && $imgWidth <= $width;
	}

	public function _resizeImagick(Model $Model, $field, $path, $size, $geometry, $thumbnailPath) {
		$srcFile  = $path . $Model->data[$Model->alias][$field];
		$pathInfo = $this->_pathinfo($srcFile);
		$thumbnailType = $imageFormat = $this->settings[$Model->alias][$field]['thumbnailType'];

		$isMedia = $this->_isMedia($Model, $this->runtime[$Model->alias][$field]['type']);
		$image    = new Imagick();

		if ($isMedia) {
			$image->setResolution(300, 300);
			$srcFile = $srcFile.'[0]';
		}

		$image->readImage($srcFile);
		$height   = $image->getImageHeight();
		$width    = $image->getImageWidth();

		if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
			// resize with banding
			list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
			$image->thumbnailImage($destW, $destH, true);
			$imageGeometry = $image->getImageGeometry();
			$x = ($destW - $imageGeometry['width']) / 2;
			$y = ($destH - $imageGeometry['height']) / 2;
			$image->setGravity(Imagick::GRAVITY_CENTER);
			$image->extentImage($destW, $destH, $x, $y);
		} elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
			// cropped resize (best fit)
			list($destW, $destH) = explode('x', $geometry);
			$image->cropThumbnailImage($destW, $destH);
		} elseif (preg_match('/^[\\d]+w$/', $geometry)) {
			// calculate heigh according to aspect ratio
			$image->thumbnailImage((int)$geometry-1, 0);
		} elseif (preg_match('/^[\\d]+h$/', $geometry)) {
			// calculate width according to aspect ratio
			$image->thumbnailImage(0, (int)$geometry-1);
		} elseif (preg_match('/^[\\d]+l$/', $geometry)) {
			// calculate shortest side according to aspect ratio
			$destW = 0;
			$destH = 0;
			$destW = ($width > $height) ? (int)$geometry-1 : 0;
			$destH = ($width > $height) ? 0 : (int)$geometry-1;

			$imagickVersion = phpversion('imagick');
			$image->thumbnailImage($destW, $destH, !($imagickVersion[0] == 3));
		}

		if ($isMedia) {
			$thumbnailType = $imageFormat = $this->settings[$Model->alias][$field]['mediaThumbnailType'];
		}

		if (!$thumbnailType || !is_string($thumbnailType)) {
			try {
				$thumbnailType = $imageFormat = $image->getImageFormat();
				// Fix file casing
				while (true) {
					$ext = false;
					$pieces = explode('.', $srcFile);
					if (count($pieces) > 1) {
						$ext = end($pieces);
					}

					if (!$ext || !strlen($ext)) {
						break;
					}

					$low = array(
						'ext' => strtolower($ext),
						'thumbnailType' => strtolower($thumbnailType),
					);

					if ($low['ext'] == 'jpg' && $low['thumbnailType'] == 'jpeg') {
						$thumbnailType = $ext;
						break;
					}

					if ($low['ext'] == $low['thumbnailType']) {
						$thumbnailType = $ext;
					}

					break;
				}
			} catch (Exception $e) {$this->log($e->getMessage(), 'upload');
				$thumbnailType = $imageFormat = 'png';
			}
		}

		$fileName = str_replace(
			array('{size}', '{filename}', '{primaryKey}'),
			array($size, $pathInfo['filename'], $Model->id),
			$this->settings[$Model->alias][$field]['thumbnailName']
		);

		$destFile = "{$thumbnailPath}{$fileName}.{$thumbnailType}";

		$image->setImageCompressionQuality($this->settings[$Model->alias][$field]['thumbnailQuality']);
		$image->setImageFormat($imageFormat);
		if (!$image->writeImage($destFile)) {
			return false;
		}

		$image->clear();
		$image->destroy();
		return true;
	}

	public function _resizePhp(Model $Model, $field, $path, $size, $geometry, $thumbnailPath) {
		$srcFile  = $path . $Model->data[$Model->alias][$field];
		$pathInfo = $this->_pathinfo($srcFile);
		$thumbnailType = $this->settings[$Model->alias][$field]['thumbnailType'];

		if (!$thumbnailType || !is_string($thumbnailType)) {
			$thumbnailType = $pathInfo['extension'];
		}

		if (!$thumbnailType) {
			$thumbnailType = 'png';
		}

		$fileName = str_replace(
			array('{size}', '{filename}', '{primaryKey}'),
			array($size, $pathInfo['filename'], $Model->id),
			$this->settings[$Model->alias][$field]['thumbnailName']
		);

		$destFile = "{$thumbnailPath}{$fileName}.{$thumbnailType}";

		copy($srcFile, $destFile);
		$src = null;
		$createHandler = null;
		$outputHandler = null;
		switch (strtolower($pathInfo['extension'])) {
			case 'gif':
				$createHandler = 'imagecreatefromgif';
				break;
			case 'jpg':
			case 'jpeg':
				$createHandler = 'imagecreatefromjpeg';
				break;
			case 'png':
				$createHandler = 'imagecreatefrompng';
				break;
			default:
				return false;
		}

		$supportsThumbnailQuality = false;
		switch (strtolower($thumbnailType)) {
			case 'gif':
				$outputHandler = 'imagegif';
				break;
			case 'jpg':
			case 'jpeg':
				$outputHandler = 'imagejpeg';
				$supportsThumbnailQuality = true;
				break;
			case 'png':
				$outputHandler = 'imagepng';
				$supportsThumbnailQuality = true;
				break;
			default:
				return false;
		}

		if ($src = $createHandler($destFile)) {
			$srcW = imagesx($src);
			$srcH = imagesy($src);

			// determine destination dimensions and resize mode from provided geometry
			if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
				// resize with banding
				list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
				$resizeMode = 'band';
			} elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
				// cropped resize (best fit)
				list($destW, $destH) = explode('x', $geometry);
				$resizeMode = 'best';
			} elseif (preg_match('/^[\\d]+w$/', $geometry)) {
				// calculate heigh according to aspect ratio
				$destW = (int)$geometry-1;
				$resizeMode = false;
			} elseif (preg_match('/^[\\d]+h$/', $geometry)) {
				// calculate width according to aspect ratio
				$destH = (int)$geometry-1;
				$resizeMode = false;
			} elseif (preg_match('/^[\\d]+l$/', $geometry)) {
				// calculate shortest side according to aspect ratio
				if ($srcW > $srcH) $destW = (int)$geometry-1;
				else $destH = (int)$geometry-1;
				$resizeMode = false;
			}
			if (!isset($destW)) $destW = ($destH/$srcH) * $srcW;
			if (!isset($destH)) $destH = ($destW/$srcW) * $srcH;

			// determine resize dimensions from appropriate resize mode and ratio
			if ($resizeMode == 'best') {
				// "best fit" mode
				if ($srcW > $srcH) {
					if ($srcH/$destH > $srcW/$destW) $ratio = $destW/$srcW;
					else $ratio = $destH/$srcH;
				} else {
					if ($srcH/$destH < $srcW/$destW) $ratio = $destH/$srcH;
					else $ratio = $destW/$srcW;
				}
				$resizeW = $srcW*$ratio;
				$resizeH = $srcH*$ratio;
			} else if ($resizeMode == 'band') {
				// "banding" mode
				if ($srcW > $srcH) $ratio = $destW/$srcW;
				else $ratio = $destH/$srcH;
				$resizeW = $srcW*$ratio;
				$resizeH = $srcH*$ratio;
			} else {
				// no resize ratio
				$resizeW = $destW;
				$resizeH = $destH;
			}

			$img = imagecreatetruecolor($destW, $destH);
			imagealphablending($img, false);
			imagesavealpha($img, true);
			imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
			imagecopyresampled($img, $src, ($destW-$resizeW)/2, ($destH-$resizeH)/2, 0, 0, $resizeW, $resizeH, $srcW, $srcH);

			if ($supportsThumbnailQuality) {
				$outputHandler($img, $destFile, $this->settings[$Model->alias][$field]['thumbnailQuality']);
			} else {
				$outputHandler($img, $destFile);
			}

			return true;
		}
		return false;
	}

	public function _getPath(Model $Model, $field) {
		$path = $this->settings[$Model->alias][$field]['path'];
		$pathMethod = $this->settings[$Model->alias][$field]['pathMethod'];

		if (method_exists($this, $pathMethod)) {
			return $this->$pathMethod($Model, $field, $path);
		}

		return $this->_getPathPrimaryKey($Model, $field, $path);
	}

	public function _getPathFlat(Model $Model, $field, $path) {
		$destDir = $path;
		$this->_mkPath($destDir);
		return '';
	}

	public function _getPathPrimaryKey(Model $Model, $field, $path) {
		$destDir = $path . $Model->id . DIRECTORY_SEPARATOR;
		$this->_mkPath($destDir);
		return $Model->id;
	}

	public function _getPathRandom(Model $Model, $field, $path) {
		$endPath = null;
		$decrement = 0;
		$string = crc32($field . microtime());

		for ($i = 0; $i < 3; $i++) {
			$decrement = $decrement - 2;
			$endPath .= sprintf("%02d" . DIRECTORY_SEPARATOR, substr('000000' . $string, $decrement, 2));
		}

		$destDir = $path . $endPath;
		$this->_mkPath($destDir);

		return substr($endPath, 0, -1);
	}

	public function _getPathRandomCombined(Model $Model, $field, $path) {
		$endPath = null;
		$decrement = 0;
		$string = crc32($field . microtime() . $Model->id);

		for ($i = 0; $i < 3; $i++) {
			$decrement = $decrement - 2;
			$endPath .= sprintf("%02d" . DIRECTORY_SEPARATOR, substr('000000' . $string, $decrement, 2));
		}

		$destDir = $path . $endPath;
		$this->_mkPath($destDir);

		return substr($endPath, 0, -1);
	}

	public function _mkPath($destDir) {
		if (!file_exists($destDir)) {
			@mkdir($destDir, 0777, true);
			@chmod($destDir, 0777);
		}
		return true;
	}

/**
 * Returns a path based on settings configuration
 *
 * @return string
 **/
	public function _path(Model $Model, $fieldName, $options = array()) {
		$defaults = array(
			'isThumbnail' => true,
			'path' => '{ROOT}webroot{DS}files{DS}{model}{DS}{field}{DS}',
			'rootDir' => $this->defaults['rootDir'],
		);

		$options = array_merge($defaults, $options);

		foreach ($options as $key => $value) {
			if ($value === null) {
				$options[$key] = $defaults[$key];
			}
		}

		if (!$options['isThumbnail']) {
			$options['path'] = str_replace(array('{size}', '{geometry}'), '', $options['path']);
		}

		$replacements = array(
			'{ROOT}'	=> $options['rootDir'],
			'{primaryKey}'	=> $Model->id,
			'{model}'	=> Inflector::underscore($Model->alias),
			'{field}'	=> $fieldName,
			'{time}'	=> time(),
			'{microtime}'	=> microtime(),
			'{DS}'		=> DIRECTORY_SEPARATOR,
			'//'		=> DIRECTORY_SEPARATOR,
			'/'			=> DIRECTORY_SEPARATOR,
			'\\'		=> DIRECTORY_SEPARATOR,
		);

		$newPath = Folder::slashTerm(str_replace(
			array_keys($replacements),
			array_values($replacements),
			$options['path']
		));

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			if (!preg_match('/^([a-zA-Z]:\\\|\\\\)/', $newPath)) {
				$newPath = $options['rootDir'] . $newPath;
			}
		} elseif ($newPath[0] !== DIRECTORY_SEPARATOR) {
			$newPath = $options['rootDir'] . $newPath;
		}

		$pastPath = $newPath;
		while (true) {
			$pastPath = $newPath;
			$newPath = str_replace(array(
				'//',
				'\\',
				DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
			), DIRECTORY_SEPARATOR, $newPath);
			if ($pastPath == $newPath) {
				break;
			}
		}

		return $newPath;
	}

	public function _pathThumbnail(Model $Model, $field, $params = array()) {
		return str_replace(
			array('{size}', '{geometry}'),
			array($params['size'], $params['geometry']),
			$params['thumbnailPath']
		);
	}

	public function _createThumbnails(Model $Model, $field, $path, $thumbnailPath) {
		$isImage = $this->_isImage($Model, $this->runtime[$Model->alias][$field]['type']);
		$isMedia = $this->_isMedia($Model, $this->runtime[$Model->alias][$field]['type']);
		$createThumbnails = $this->settings[$Model->alias][$field]['thumbnails'];
		$hasThumbnails = !empty($this->settings[$Model->alias][$field]['thumbnailSizes']);

		if (($isImage || $isMedia) && $createThumbnails && $hasThumbnails) {
			$method = $this->settings[$Model->alias][$field]['thumbnailMethod'];

			foreach ($this->settings[$Model->alias][$field]['thumbnailSizes'] as $size => $geometry) {
				$thumbnailPathSized = $this->_pathThumbnail($Model, $field, compact(
					'geometry', 'size', 'thumbnailPath'
				));
				$this->_mkPath($thumbnailPathSized);

				$valid = false;
				if (method_exists($Model, $method)) {
					$valid = $Model->$method($Model, $field, $path, $size, $geometry, $thumbnailPathSized);
				} elseif (method_exists($this, $method)) {
					$valid = $this->$method($Model, $field, $path, $size, $geometry, $thumbnailPathSized);
				} else {
					throw new Exception("Invalid thumbnailMethod %s", $method);
				}

				if (!$valid) {
					$Model->invalidate($field, 'resizeFail');
				}
			}
		}
	}

	public function _isImage(Model $Model, $mimetype) {
		return in_array($mimetype, $this->_imageMimetypes);
	}

	public function _isMedia(Model $Model, $mimetype) {
		return in_array($mimetype, $this->_mediaMimetypes);
	}

	public function _getMimeType($filePath) {
		if(file_exists($filePath))
		{
			if (class_exists('finfo'))
			{
				if($finfo = new finfo(defined('FILEINFO_MIME_TYPE') ? FILEINFO_MIME_TYPE : FILEINFO_MIME))
				{
					return $finfo->file($filePath);
				}
			}
			
			if (function_exists('exif_imagetype') && function_exists('image_type_to_mime_type'))
			{
				$mimetype = image_type_to_mime_type(exif_imagetype($filePath));
				if ($mimetype !== false)
				{
					return $mimetype;
				}
			}
			
			if (function_exists('mime_content_type'))
			{
				return mime_content_type($filePath);
			}
		}

		return 'application/octet-stream';
	}

	public function _prepareFilesForDeletion(Model $Model, $field, $data, $options) {
		if (!isset($data[$Model->alias][$field])) return $this->__filesToRemove;
		if (!strlen($data[$Model->alias][$field])) return $this->__filesToRemove;
		$dir = (isset($data[$Model->alias][$options['fields']['dir']])?$data[$Model->alias][$options['fields']['dir']]:false);
		$filePathDir = $this->settings[$Model->alias][$field]['path'] . DS;
		if($dir)
		{
			$filePathDir .= $dir . DS;
		}

		$id = false;
		if(isset($data[$Model->alias][$Model->primaryKey]))
		{
			if(!$dir) $dir = $data[$Model->alias][$Model->primaryKey];
			$filePathDir .= $data[$Model->alias][$Model->primaryKey]. DS;
		}
		
		// get rid of any overlapping DS
		$filePathDir = explode(DS, $filePathDir);
		foreach($filePathDir as $k => $v)
		{
			if(!trim($v)) unset($filePathDir[$k]);
		}
		// add the before and after
		array_push($filePathDir, '');
		array_unshift($filePathDir, '');
		$filePathDir = implode(DS, $filePathDir);
		
		$filePath = $filePathDir.$data[$Model->alias][$field];
		$pathInfo = $this->_pathinfo($filePath);
		
		if (!isset($this->__filesToRemove[$Model->alias])) {
			$this->__filesToRemove[$Model->alias] = array();
		}
		
		$this->__filesToRemove[$Model->alias][] = $filePath;
		$this->__foldersToRemove[$Model->alias][] = $dir;

		$createThumbnails = $options['thumbnails'];
		$hasThumbnails = !empty($options['thumbnailSizes']);

		if (!$createThumbnails || !$hasThumbnails) {
			return $this->__filesToRemove;
		}

		$DS = DIRECTORY_SEPARATOR;
		$mimeType = $this->_getMimeType($filePath);
		$isMedia = $this->_isMedia($Model, $mimeType);
		$isImagickResize = $options['thumbnailMethod'] == 'imagick';
		$thumbnailType = $options['thumbnailType'];

		if ($isImagickResize) {
			if ($isMedia) {
				$thumbnailType = $options['mediaThumbnailType'];
			}

			if (!$thumbnailType || !is_string($thumbnailType)) {
				try {
					$srcFile = $filePath;
					$image    = new Imagick();
					if ($isMedia) {
						$image->setResolution(300, 300);
						$srcFile = $srcFile.'[0]';
					}

					$image->readImage($srcFile);
					$thumbnailType = $image->getImageFormat();
				} catch (Exception $e) {
					$thumbnailType = 'png';
				}
			}
		} else {
			if (!$thumbnailType || !is_string($thumbnailType)) {
				$thumbnailType = $pathInfo['extension'];
			}

			if (!$thumbnailType) {
				$thumbnailType = 'png';
			}
		}
		foreach ($options['thumbnailSizes'] as $size => $geometry) {
			$fileName = str_replace(
				array('{size}', '{filename}', '{primaryKey}', '{time}', '{microtime}'),
				array($size, $pathInfo['filename'], $Model->id, time(), microtime()),
				$options['thumbnailName']
			);

			$thumbnailPath = $options['thumbnailPath'];
			$thumbnailPath = $this->_pathThumbnail($Model, $field, compact(
				'geometry', 'size', 'thumbnailPath'
			));

			$thumbnailFilePath = "{$thumbnailPath}{$dir}{$DS}{$fileName}.{$thumbnailType}";
			$this->__filesToRemove[$Model->alias][] = $thumbnailFilePath;
		}
		return $this->__filesToRemove;
	}

	public function _getField($check) {
		$field_keys = array_keys($check);
		return array_pop($field_keys);
	}

	public function _pathinfo($filename) {
		$pathInfo = pathinfo($filename);

		if (!isset($pathInfo['extension']) || !strlen($pathInfo['extension'])) {
			$pathInfo['extension'] = '';
		}

		// PHP < 5.2.0 doesn't include 'filename' key in pathinfo. Let's try to fix this.
		if (empty($pathInfo['filename'])) {
			$pathInfo['filename'] = basename($pathInfo['basename'], '.' . $pathInfo['extension']);
		}
		return $pathInfo;
	}

}
