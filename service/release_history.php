<?php
namespace mundophpbb\workspace\service;

/** Release identifiers, notes and content fingerprints. Does not archive the exported ZIP. */
class release_history
{
	const MAX_RELEASES = 20;
	public static function validVersion($version)
	{
		return is_string($version) && strlen($version) <= 40 &&
			preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
	}
	public static function validPackageName($name)
	{
		return is_string($name) && strlen($name) <= 180 &&
			preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $name) === 1 &&
			strpos($name, '..') === false;
	}
	public static function fingerprint(array $snapshot)
	{
		return checkpoint_manager::fingerprint($snapshot['files'], false);
	}
	public static function validNotes($notes)
	{
		return is_string($notes) && strlen($notes) <= 12000 &&
			strpos($notes, "\0") === false && preg_match('//u', $notes) === 1;
	}
}
