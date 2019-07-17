<?php

if (class_exists('ZipArchive')):
class SYNC_ZipArchive extends ZipArchive {
	public $last_error = 'Unknown: ZipArchive does not return error messages';
}
endif;

class SYNC_PclZip {

	protected $pclzip;
	protected $path;
	protected $addfiles;
	protected $adddirs;
	private $statindex;
	private $include_mtime = false;
	public $last_error;
	public $backups_dir_location;

	public function __construct() {
		$this->addfiles = array();
		$this->adddirs = array();
		// Put this in a non-backed-up, writeable location, to make sure that huge temporary files aren't created and then added to the backup - and that we have somewhere writable
		if (!defined('PCLZIP_TEMPORARY_DIR')) define('PCLZIP_TEMPORARY_DIR', trailingslashit($this->backups_dir_location));
	}

	# Used to include mtime in statindex (by default, not done - to save memory; probably a bit paranoid)
	public function ud_include_mtime() {
		$this->include_mtime = true;
	}

	public function __get($name) {
		if ($name == 'numFiles' || $name == 'numAll') {

			if (empty($this->pclzip)) return false;

			$statindex = $this->pclzip->listContent();

			if (empty($statindex)) {
				$this->statindex = array();
				// We return a value that is == 0, but allowing a PclZip error to be detected (PclZip returns 0 in the case of an error).
				if (0 === $statindex) $this->last_error = $this->pclzip->errorInfo(true);
				return (0 === $statindex) ? false : 0;
			}

			if ($name == 'numFiles') {
				
				$result = array();
				foreach ($statindex as $i => $file) {
					if (!isset($statindex[$i]['folder']) || 0 == $statindex[$i]['folder']) {
						$result[] = $file;
					}
					unset($statindex[$i]);
				}

				$this->statindex=$result;

			} else {
				$this->statindex=$statindex;
			}

			return count($this->statindex);
		}

		return null;

	}

	public function statIndex($i) {
		if (empty($this->statindex[$i])) return array('name' => null, 'size' => 0);
		$v = array('name' => $this->statindex[$i]['filename'], 'size' => $this->statindex[$i]['size']);
		if ($this->include_mtime) $v['mtime'] = $this->statindex[$i]['mtime'];
		return $v;
	}

	public function open($path, $flags = 0) {
	
		if(!class_exists('PclZip')) include_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
		if(!class_exists('PclZip')) {
			$this->last_error = "No PclZip class was found";
			return false;
		}

		# Route around PHP bug (exact version with the problem not known)
		$ziparchive_create_match = (version_compare(PHP_VERSION, '5.2.12', '>') && defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;

		if ($flags == $ziparchive_create_match && file_exists($path)) @unlink($path);

		$this->pclzip = new PclZip($path);

		if (empty($this->pclzip)) {
			$this->last_error = 'Could not get a PclZip object';
			return false;
		}

		# Make the empty directory we need to implement addEmptyDir()
		
		if (!is_dir($this->backups_dir_location.'/emptydir') && !mkdir($this->backups_dir_location.'/emptydir')) {
			$this->last_error = "Could not create empty directory ($this->backups_dir_location/emptydir)";
			return false;
		}

		$this->path = $path;

		return true;

	}

	# Do the actual write-out - it is assumed that close() is where this is done. Needs to return true/false
	public function close() {
		if (empty($this->pclzip)) {
			$this->last_error = 'Zip file was not opened';
			return false;
		}

		$activity = false;

		# Add the empty directories
		foreach ($this->adddirs as $dir) {
			if (false == $this->pclzip->add($this->backups_dir_location.'/emptydir', PCLZIP_OPT_REMOVE_PATH, $this->backups_dir_location.'/emptydir', PCLZIP_OPT_ADD_PATH, $dir)) {
				$this->last_error = $this->pclzip->errorInfo(true);
				return false;
			}
			$activity = true;
		}

		foreach ($this->addfiles as $rdirname => $adirnames) {
			foreach ($adirnames as $adirname => $files) {
				if (false == $this->pclzip->add($files, PCLZIP_OPT_REMOVE_PATH, $rdirname, PCLZIP_OPT_ADD_PATH, $adirname)) {
					$this->last_error = $this->pclzip->errorInfo(true);
					return false;
				}
				$activity = true;
			}
			unset($this->addfiles[$rdirname]);
		}

		$this->pclzip = false;
		$this->addfiles = array();
		$this->adddirs = array();

		clearstatcache();
		if ($activity && filesize($this->path) < 50) {
			$this->last_error = "Write failed - unknown cause (check your file permissions)";
			return false;
		}

		return true;
	}

	# Note: basename($add_as) is irrelevant; that is, it is actually basename($file) that will be used. But these are always identical in our usage.
	public function addFile($file, $add_as) {
		# Add the files. PclZip appears to do the whole (copy zip to temporary file, add file, move file) cycle for each file - so batch them as much as possible. We have to batch by dirname(). On a test with 1000 files of 25KB each in the same directory, this reduced the time needed on that directory from 120s to 15s (or 5s with primed caches).
		$rdirname = dirname($file);
		$adirname = dirname($add_as);
		$this->addfiles[$rdirname][$adirname][] = $file;
	}

	# PclZip doesn't have a direct way to do this
	public function addEmptyDir($dir) {
		$this->adddirs[] = $dir;
	}

	public function extract($path_to_extract, $path) {
		return $this->pclzip->extract(PCLZIP_OPT_PATH, $path_to_extract, PCLZIP_OPT_BY_NAME, $path);
	}

}