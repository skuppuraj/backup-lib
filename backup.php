<?php

if (!defined('IWP_ZIP_EXECUTABLE')) define('IWP_ZIP_EXECUTABLE', "/usr/bin/zip,/bin/zip,/usr/local/bin/zip,/usr/sfw/bin/zip,/usr/xdg4/bin/zip,/opt/bin/zip");
if (!defined('IWP_ZIP_NOCOMPRESS')) define('IWP_ZIP_NOCOMPRESS', '.jpg,.jpeg,.png,.gif,.zip,.gz,.bz2,.xz,.rar,.mp3,.mp4,.mpeg,.avi,.mov');
if (!defined('IWP_BINZIP_OPTS')) {
	$zip_nocompress = array_map('trim', explode(',', IWP_ZIP_NOCOMPRESS));
	$zip_binzip_opts = '';
	foreach ($zip_nocompress as $ext) {
		if (empty($zip_binzip_opts)) {
			$zip_binzip_opts = "-n $ext:".strtoupper($ext);
		} else {
			$zip_binzip_opts .= ':'.$ext.':'.strtoupper($ext);
		}
	}
	define('IWP_BINZIP_OPTS', $zip_binzip_opts);
}

class SYNC_Backup{

	public $binzip = 0;
	public $debug = false;
	private $use_zip_object = 'IWP_MMB_ZipArchive';

	public function __construct(){
		if (@file_exists('/proc/user_beancounters') && @file_exists('/proc/meminfo') && @is_readable('/proc/meminfo')) {
			$meminfo = @file_get_contents('/proc/meminfo', false, null, -1, 200);
			if (is_string($meminfo) && preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $matches)) {
				$memory_mb = $matches[1]/1024;
				# If the report is of a large amount, then we're probably getting the total memory on the hypervisor (this has been observed), and don't really know the VPS's memory
				$vz_log = "OpenVZ; reported memory: ".round($memory_mb, 1)." MB";
				if ($memory_mb < 1024 || $memory_mb > 8192) {
					$openvz_lowmem = true;
					$vz_log .= " (will not use BinZip)";
				}
			}
		}
		if (empty($openvz_lowmem)) {
			$binzip = $this->find_working_bin_zip();
			if (is_string($binzip)) {
				$this->binzip = $binzip;
				$this->use_zip_object = 'IWP_MMB_BinZip';
			}
		}
	}

	public function find_working_bin_zip($logit = true, $cacheit = true) {
		if ($this->detect_safe_mode()) return false;
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if (!function_exists('popen') || !function_exists('proc_open') || !function_exists('escapeshellarg')) {
			return false;
		}

		$existing = $this->jobdata_get('binzip', null);
		# Theoretically, we could have moved machines, due to a migration
		if (null !== $existing && (!is_string($existing) || @is_executable($existing))) return $existing;

		$backup_dir = $this->backups_dir_location();
		foreach (explode(',', IWP_ZIP_EXECUTABLE) as $potzip) {
			if (!@is_executable($potzip)) continue;
			// if ($logit) $this->log("Testing: $potzip");

			# Test it, see if it is compatible with Info-ZIP
			# If you have another kind of zip, then feel free to tell me about it
			@mkdir($backup_dir.'/binziptest/subdir1/subdir2', 0777, true);

			if (!file_exists($backup_dir.'/binziptest/subdir1/subdir2')) return false;
			
			file_put_contents($backup_dir.'/binziptest/subdir1/subdir2/test.html', '<html><body><h1>Test content</h1></body></html>');
			@unlink($backup_dir.'/binziptest/test.zip');
			if (is_file($backup_dir.'/binziptest/subdir1/subdir2/test.html')) {

				$exec = "cd ".escapeshellarg($backup_dir)."; $potzip";
				if (defined('IWP_BINZIP_OPTS') && IWP_BINZIP_OPTS) $exec .= ' '.IWP_BINZIP_OPTS;
				$exec .= " -v -u -r binziptest/test.zip binziptest/subdir1";

				$all_ok=true;
				$handle = popen($exec, "r");
				if ($handle) {
					while (!feof($handle)) {
						$w = fgets($handle);
						if ($w && $logit) $this->log("Output: ".trim($w));
					}
					$ret = pclose($handle);
					if ($ret !=0) {
						if ($logit) $this->log("Binary zip: error (code: $ret)");
						$all_ok = false;
					}
				} else {
					if ($logit) $this->log("Error: popen failed");
					$all_ok = false;
				}

				# Now test -@
				if (true == $all_ok) {
					file_put_contents($backup_dir.'/binziptest/subdir1/subdir2/test2.html', '<html><body><a href="https://infinitewp.com">InfiniteWP is a really great backup and restoration plugin for WordPress.</a></body></html>');
					
					$exec = $potzip;
					if (defined('IWP_BINZIP_OPTS') && IWP_BINZIP_OPTS) $exec .= ' '.IWP_BINZIP_OPTS;
					$exec .= " -v -@ binziptest/test.zip";

					$all_ok=true;

					$descriptorspec = array(
						0 => array('pipe', 'r'),
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
					);
					$handle = proc_open($exec, $descriptorspec, $pipes, $backup_dir);
					if (is_resource($handle)) {
						if (!fwrite($pipes[0], "binziptest/subdir1/subdir2/test2.html\n")) {
							@fclose($pipes[0]);
							@fclose($pipes[1]);
							@fclose($pipes[2]);
							$all_ok = false;
						} else {
							fclose($pipes[0]);
							while (!feof($pipes[1])) {
								$w = fgets($pipes[1]);
								// if ($w && $logit) $this->log("Output: ".trim($w));
							}
							fclose($pipes[1]);
							
							while (!feof($pipes[2])) {
								$last_error = fgets($pipes[2]);
								// if (!empty($last_error) && $logit) $this->log("Stderr output: ".trim($w));
							}
							fclose($pipes[2]);

							$ret = proc_close($handle);
							if ($ret !=0) {
								// if ($logit) $this->log("Binary zip: error (code: $ret)");
								$all_ok = false;
							}

						}

					} else {
						// if ($logit) $this->log("Error: proc_open failed");
						$all_ok = false;
					}

				}

				// Do we now actually have a working zip? Need to test the created object using PclZip
				// If it passes, then remove dirs and then return $potzip;
				$found_first = false;
				$found_second = false;
				if ($all_ok && file_exists($backup_dir.'/binziptest/test.zip')) {
					if (function_exists('gzopen')) {
						if(!class_exists('PclZip')) {
							if (file_exists(ABSPATH.'/wp-admin/includes/class-pclzip.php')) {
								include_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
							}else{
								include_once('class-pclzip.php');
							}
						}
						$zip = new PclZip($backup_dir.'/binziptest/test.zip');
						$foundit = 0;
						if (($list = $zip->listContent()) != 0) {
							foreach ($list as $obj) {
								if ($obj['filename'] && !empty($obj['stored_filename']) && 'binziptest/subdir1/subdir2/test.html' == $obj['stored_filename'] && $obj['size']==129) $found_first=true;
								if ($obj['filename'] && !empty($obj['stored_filename']) && 'binziptest/subdir1/subdir2/test2.html' == $obj['stored_filename'] && $obj['size']==136) $found_second=true;
							}
						}
					} else {
						// PclZip will die() if gzopen is not found
						// Obviously, this is a kludge - we assume it's working. We could, of course, just return false - but since we already know now that PclZip can't work, that only leaves ZipArchive
						// $this->log("gzopen function not found; PclZip cannot be invoked; will assume that binary zip works if we have a non-zero file");
						if (filesize($backup_dir.'/binziptest/test.zip') > 0) {
							$found_first = true;
							$found_second = true;
						}
					}
				}
				$this->remove_binzip_test_files($backup_dir);
				if ($found_first && $found_second) {
					// if ($logit) $this->log("Working binary zip found: $potzip");
					// if ($cacheit) $this->jobdata_set('binzip', $potzip);
					return $potzip;
				}

			}
			$this->remove_binzip_test_files($backup_dir);
		}
		// if ($cacheit) $this->jobdata_set('binzip', false);
		return false;
	}
	public function detect_safe_mode() {
		return (@ini_get('safe_mode') && strtolower(@ini_get('safe_mode')) != "off") ? 1 : 0;
	}
	private function remove_binzip_test_files($backup_dir) {
		@unlink($backup_dir.'/binziptest/subdir1/subdir2/test.html');
		@unlink($backup_dir.'/binziptest/subdir1/subdir2/test2.html');
		@rmdir($backup_dir.'/binziptest/subdir1/subdir2');
		@rmdir($backup_dir.'/binziptest/subdir1');
		@unlink($backup_dir.'/binziptest/test.zip');
		@rmdir($backup_dir.'/binziptest');
	}
	public function backups_dir_location() {
		return dirname(__FILE__);
	}
	public function str_lreplace($search, $replace, $subject) {
		$pos = strrpos($subject, $search);
		if($pos !== false) $subject = substr_replace($subject, $replace, $pos, strlen($search));
		return $subject;
	}
}

if (class_exists('ZipArchive')):
class SYNC_ZipArchive extends ZipArchive {
	public $last_error = 'Unknown: ZipArchive does not return error messages';
}
endif;

class IWP_MMB_PclZip {

	protected $pclzip;
	protected $path;
	protected $addfiles;
	protected $adddirs;
	private $statindex;
	private $include_mtime = false;
	public $last_error;

	public function __construct() {
		$this->addfiles = array();
		$this->adddirs = array();
		// Put this in a non-backed-up, writeable location, to make sure that huge temporary files aren't created and then added to the backup - and that we have somewhere writable
		global $backup_core;
		if (!defined('PCLZIP_TEMPORARY_DIR')) define('PCLZIP_TEMPORARY_DIR', trailingslashit($backup_core->backups_dir_location()));
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
	
		if(!class_exists('PclZip')){
			if (file_exists(ABSPATH.'/wp-admin/includes/class-pclzip.php')) {
				include_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
			}else{
				include_once('class-pclzip.php');
			}
		} 
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
		global $backup_core;
		$backup_dir = $backup_core->backups_dir_location();
		if (!is_dir($backup_dir.'/emptydir') && !mkdir($backup_dir.'/emptydir')) {
			$this->last_error = "Could not create empty directory ($backup_dir/emptydir)";
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

		global $backup_core;
		$backup_dir = $backup_core->backups_dir_location();

		$activity = false;

		# Add the empty directories
		foreach ($this->adddirs as $dir) {
			if (false == $this->pclzip->add($backup_dir.'/emptydir', PCLZIP_OPT_REMOVE_PATH, $backup_dir.'/emptydir', PCLZIP_OPT_ADD_PATH, $dir)) {
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

class IWP_MMB_BinZip extends IWP_MMB_PclZip {

	private $binzip;

	public function __construct() {
		global $backup_core;
		$this->binzip = $backup_core->binzip;
		if (!is_string($this->binzip)) {
			$this->last_error = "No binary zip was found";
			return false;
		}
		return parent::__construct();
	}

	public function addFile($file, $add_as) {

		global $backup_core;
		# Get the directory that $add_as is relative to
		$base = $backup_core->str_lreplace($add_as, '', $file);

		if ($file == $base) {
			// Shouldn't happen; but see: https://bugs.php.net/bug.php?id=62119
			// $backup_core->log("File skipped due to unexpected name mismatch (locale: ".setlocale(LC_CTYPE, "0")."): file=$file add_as=$add_as", 'notice', false, true);
		} else {
			$rdirname = untrailingslashit($base);
			# Note: $file equals $rdirname/$add_as
			$this->addfiles[$rdirname][] = $add_as;
		}

	}

	# The standard zip binary cannot list; so we use PclZip for that
	# Do the actual write-out - it is assumed that close() is where this is done. Needs to return true/false
	public function close() {

		if (empty($this->pclzip)) {
			$this->last_error = 'Zip file was not opened';
			return false;
		}

		global $backup_core, $backup_core;
		$backup_dir = $backup_core->backups_dir_location();

		$activity = false;

		# BinZip does not like zero-sized zip files
		if (file_exists($this->path) && 0 == filesize($this->path)) @unlink($this->path);

		$descriptorspec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w')
		);
		$exec = $this->binzip;
		if (defined('IWP_BINZIP_OPTS') && IWP_BINZIP_OPTS) $exec .= ' '.IWP_BINZIP_OPTS;
		$exec .= " -v -@ ".escapeshellarg($this->path);

		$last_recorded_alive = time();
		$orig_size = file_exists($this->path) ? filesize($this->path) : 0;
		$last_size = $orig_size;
		clearstatcache();

		$added_dirs_yet = false;

		# If there are no files to add, but there are empty directories, then we need to make sure the directories actually get added
		if (0 == count($this->addfiles) && 0 < count($this->adddirs)) {
			$dir = realpath($backup_core->make_zipfile_source);
			$this->addfiles[$dir] = '././.';
		}
		// Loop over each destination directory name
		foreach ($this->addfiles as $rdirname => $files) {

			$process = proc_open($exec, $descriptorspec, $pipes, $rdirname);

			if (!is_resource($process)) {
				$backup_core->log('BinZip error: proc_open failed');
				$this->last_error = 'BinZip error: proc_open failed';
				return false;
			}

			if (!$added_dirs_yet) {
				# Add the directories - (in fact, with binzip, non-empty directories automatically have their entries added; but it doesn't hurt to add them explicitly)
				foreach ($this->adddirs as $dir) {
					fwrite($pipes[0], $dir."/\n");
				}
				$added_dirs_yet=true;
			}

			$read = array($pipes[1], $pipes[2]);
			$except = null;

			if (!is_array($files) || 0 == count($files)) {
				fclose($pipes[0]);
				$write = array();
			} else {
				$write = array($pipes[0]);
			}

			while ((!feof($pipes[1]) || !feof($pipes[2]) || (is_array($files) && count($files)>0)) && false !== ($changes = @stream_select($read, $write, $except, 0, 200000))) {

				if (is_array($write) && in_array($pipes[0], $write) && is_array($files) && count($files)>0) {
					$file = array_pop($files);
					// Send the list of files on stdin
					fwrite($pipes[0], $file."\n");
					if (0 == count($files)) fclose($pipes[0]);
				}

				if (is_array($read) && in_array($pipes[1], $read)) {
					$w = fgets($pipes[1]);
					// Logging all this really slows things down; use debug to mitigate
					// if ($w && $backup_core->debug) $backup_core->log("Output from zip: ".trim($w), 'debug');
					if (time() > $last_recorded_alive + 5) {
						$backup_core->record_still_alive();
						$last_recorded_alive = time();
					}
					if (file_exists($this->path)) {
						$new_size = @filesize($this->path);
						
						clearstatcache();
						# Log when 20% bigger or at least every 50MB
						if ($new_size > $last_size*1.2 || $new_size > $last_size + 52428800) {
							$backup_core->log(basename($this->path).sprintf(": size is now: %.2f MB", round($new_size/1048576,1)));
							$last_size = $new_size;
						}
					}
				}

				if (is_array($read) && in_array($pipes[2], $read)) {
					$last_error = fgets($pipes[2]);
					if (!empty($last_error)) $this->last_error = rtrim($last_error);
				}

				// Re-set
				$read = array($pipes[1], $pipes[2]);
				$write = (is_array($files) && count($files) >0) ? array($pipes[0]) : array();
				$except = null;

			}

			fclose($pipes[1]);
			fclose($pipes[2]);

			$ret = proc_close($process);

			if ($ret != 0 && $ret != 12) {
				if ($ret < 128) {
					// $backup_core->log("Binary zip: error (code: $ret - look it up in the Diagnostics section of the zip manual at http://www.info-zip.org/mans/zip.html for interpretation... and also check that your hosting account quota is not full)");
				} else {
					// $backup_core->log("Binary zip: error (code: $ret - a code above 127 normally means that the zip process was deliberately killed ... and also check that your hosting account quota is not full)");
				}
				// if (!empty($w) && !$backup_core->debug) $backup_core->log("Last output from zip: ".trim($w), 'debug');
				return false;
			}

			unset($this->addfiles[$rdirname]);
		}

		return true;
	}

}
