# Sync backup
Simple backup library to create zip file 
This library use the following methods to take backup
1.Bin 
2.ZipArchive
3.PclZip

## Example:
```include('backup.php');
$backup_array = array ();
global $backup_core;

$backup_core = new SYNC_Backup();
$zipfile = 'first.zip';
$zip = new $backup_core->use_zip_object;

if (file_exists($zipfile)) {
	$opencode = $zip->open($zipfile);
	$original_size = filesize($zipfile);
	clearstatcache();
} else {
	$create_code = (version_compare(PHP_VERSION, '5.2.12', '>') && defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;
	$opencode = $zip->open($zipfile, $create_code);
	$original_size = 0;
}

foreach ($backup_array as $file => $add_as) {
	@touch($zipfile);
	$zip->addFile($file, $add_as);
}

if ($zip->close()) {
	echo 'success';
}else{
	echo "failure";
}
```