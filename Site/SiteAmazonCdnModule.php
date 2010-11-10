<?php

require_once 'Services/Amazon/S3.php';
require_once 'Services/Amazon/S3/AccessControlList.php';
require_once 'Swat/exceptions/SwatFileNotFoundException.php';
require_once 'Site/SiteCdn.php';
require_once 'Site/SiteApplicationModule.php';

/**
 * Application module that provides access to an Amazon S3 bucket.
 *
 * @package   Site
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class SiteAmazonCdnModule extends SiteApplicationModule implements SiteCdn
{
	// {{{ public properties

	/**
	 * The name of the S3 bucket to use
	 *
	 * @var string
	 */
	public $bucket_id;

	/**
	 * The key to use for accessing the bucket
	 *
	 * @var string
	 */
	public $access_key_id;

	/**
	 * The secret to use for accessing the bucket
	 *
	 * @var string
	 */
	public $access_key_secret;

	// }}}
	// {{{ protected properties

	/**
	 * The Amazon S3 bucket
	 *
	 * @var Services_Amazon_S3_Resource_Bucket
	 */
	protected $bucket;

	/**
	 * Max age of for the Cache Control header.
	 *
	 * In seconds, defaults to 1 hour (cloudfront's minimum cache time).
	 *
	 * @var integer
	 */
	protected $max_age = 3600;

	/**
	 * Whether or not to check the md5 of a file when saving.
	 *
	 * If true, check to see if the object already exists and checks the current
	 * file's md5 against the new file's md5 before copying. Defaults to true.
	 *
	 * md5 is always saved in a custom metadata field. ETag isn't used because
	 * amazon doesn't guarantee it to always be the file's md5, even though it
	 * appears as though it always is.
	 *
	 * @var boolean
	 */
	protected $check_md5 = true;

	/**
	 * Access control list for the object
	 *
	 * This may be one of the predefined ACLs specified by a
	 * Services_Amazon_S3_AccessControlList::ACL_xxx constant, or a
	 * Services_Amazon_S3_AccessControlList instance.
	 *
	 * @var string|Services_Amazon_S3_AccessControlList
	 */
	protected $acl = Services_Amazon_S3_AccessControlList::ACL_PUBLIC_READ;

	// }}}
	// {{{ public function init()

	/**
	 * Initializes this module
	 */
	public function init()
	{
		$s3 = Services_Amazon_S3::getAccount(
			$this->access_key_id,
			$this->access_key_secret);

		$this->bucket = $s3->getBucket($this->bucket_id);
	}

	// }}}
	// {{{ public function setCheckMd5()

	/**
	 * Sets whether or not to check the md5 field;
	 *
	 * @param boolean $check_md5 true if we want to check it, false if we don't.
	 */
	public function setCheckMd5($check_md5 = true)
	{
		$this->check_md5 = $check_md5;
	}

	// }}}
	// {{{ public function setMaxAge()

	/**
	 * Sets maxium age for cache control
	 *
	 * @param integer $max_age the maximum age of the resource in seconds.
	 */
	public function setMaxAge($max_age)
	{
		$this->max_age = $max_age;
	}

	// }}}
	// {{{ public function setAcl()

	/**
	 * Sets maxium age for cache control
	 *
	 * @param integer $max_age the maximum age of the resource in seconds.
	 */
	public function setAcl($acl)
	{
		$this->acl = $acl;
	}

	// }}}
	// {{{ public function copyFile()

	/**
	 * Copies a file to the Amazon S3 bucket
	 *
	 * @param string $source the source path of the file to copy.
	 * @param string $destination the destination path of the file to copy.
	 * @param string $mime_type the MIME type of the file. If null, we grab the
	 *                           MIME type from the file. Defaults to null.
	 * @param integer $max_age the maximum age for cache control. If set
	 *                          overrides $this->max_age. Defaults to null.
	 *
	 * @returns boolean $copy whether or not the file has been copied. If false
	 *                         this means the file was already on s3.
	 */
	public function copyFile($source, $destination, $mime_type = null,
		$max_age = null)
	{
		if (file_exists($source) === false) {
			throw new SwatFileNotFoundException(Site::_(sprintf(
				'File missing ‘%s’.', $source)));
		}

		$file = file_get_contents($source);
		if ($file === false) {
			throw new SwatFileNotFoundException(Site::_(sprintf(
				'Unable to open file ‘%s’.', $source)));
		}

		$s3_object = $this->bucket->getObject($destination);
		$md5       = md5($file);
		$copy      = true;

		if ($this->check_md5 == true) {
			// check for existing object.
			if ($s3_object->load(
				Services_Amazon_S3_Resource_Object::LOAD_METADATA_ONLY)) {
				if (array_key_exists('md5', $s3_object->userMetadata)) {
					if ($s3_object->userMetadata['md5'] == $md5) {
						$copy = false;
					}
				}
			}
		}

		if ($copy === true) {
			if ($mime_type === null) {
				$finfo     = finfo_open(FILEINFO_MIME);
				$mime_type = finfo_file($finfo, $from);
			}

			if ($max_age === null) {
				$max_age = $this->max_age;
			}

			$s3_object->data         = $file;
			$s3_object->contentType  = $mime_type;
			$s3_object->acl          = $this->acl;
			$s3_object->userMetadata = array('md5' => $md5);
			$s3_object->httpHeaders  = array(
				'cache-control' => 'max-age='.$max_age,
				);

			$s3_object->save();
		}

		return $copy;
	}

	// }}}
	// {{{ public function deleteFile()

	/**
	 * Deletes a file from the Amazon S3 bucket
	 *
	 * @param string $file_path the path, in the bucket, of the file to delete.
	 */
	public function deleteFile($file_path)
	{
		$s3_object = $this->bucket->getObject($file_path);
		$s3_object->delete();
	}

	// }}}
}

?>
