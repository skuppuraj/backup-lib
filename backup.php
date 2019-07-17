<?php

if (class_exists('ZipArchive')):
class SYNC_ZipArchive extends ZipArchive {
	public $last_error = 'Unknown: ZipArchive does not return error messages';
}
endif;
