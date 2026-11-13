<?php
namespace mundophpbb\workspace\service;

/** Bounded, text-only unified line comparison. No file writes or shell commands. */
class github_line_diff
{
	const MAX_BYTES = 65536;
	const MAX_LINES = 320;

	public static function compare($local, $remote)
	{
		if (!is_string($local) || !is_string($remote) ||
			strlen($local) > self::MAX_BYTES || strlen($remote) > self::MAX_BYTES ||
			strpos($local, "\0") !== false || strpos($remote, "\0") !== false ||
			!preg_match('//u', $local) || !preg_match('//u', $remote))
			throw new \RuntimeException('diff_unavailable');
		$a = self::lines($local);
		$b = self::lines($remote);
		$n = count($a); $m = count($b);
		if ($n > self::MAX_LINES || $m > self::MAX_LINES)
			throw new \RuntimeException('diff_unavailable');
		$dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--)
			for ($j = $m - 1; $j >= 0; $j--)
				$dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i+1][$j+1] + 1 :
					max($dp[$i+1][$j], $dp[$i][$j+1]);
		$rows = []; $i = 0; $j = 0;
		while ($i < $n || $j < $m)
		{
			if ($i < $n && $j < $m && $a[$i] === $b[$j])
			{
				$rows[] = ['kind'=>'equal','old'=>$i+1,'new'=>$j+1,'text'=>$a[$i]];
				$i++; $j++;
			}
			elseif ($j < $m && ($i === $n || $dp[$i][$j+1] >= $dp[$i+1][$j]))
			{
				$rows[] = ['kind'=>'add','old'=>null,'new'=>$j+1,'text'=>$b[$j]];
				$j++;
			}
			else
			{
				$rows[] = ['kind'=>'remove','old'=>$i+1,'new'=>null,'text'=>$a[$i]];
				$i++;
			}
		}
		return $rows;
	}

	private static function lines($text)
	{
		if ($text === '') return [];
		$result = preg_split('/\r\n|\n|\r/', $text);
		if (substr($text, -1) === "\n" || substr($text, -1) === "\r") array_pop($result);
		return $result;
	}
}
