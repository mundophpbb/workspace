<?php
namespace mundophpbb\workspace\service;

/**
 * Stable content fingerprint shared by validation and download preparation.
 * The hash covers every relative path, byte length and file content.
 */
final class release_fingerprint
{
	public static function calculate(array $files)
	{
		ksort($files, SORT_STRING);
		$context = hash_init('sha256');
		foreach ($files as $name => $content)
		{
			$content = (string) $content;
			hash_update($context, (string) $name . "\0" . strlen($content) . "\0" . $content . "\0");
		}
		return substr(hash_final($context), 0, 24);
	}

	public static function is_valid($value)
	{
		return is_string($value) && (bool) preg_match('/^[a-f0-9]{24}$/D', $value);
	}
}
