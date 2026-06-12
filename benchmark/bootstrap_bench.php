<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$resultsDir = $root . DIRECTORY_SEPARATOR . 'benchmark' . DIRECTORY_SEPARATOR . 'results';
$latestPrefix = $resultsDir . DIRECTORY_SEPARATOR . 'bootstrap-latest';
$baselinePrefix = $resultsDir . DIRECTORY_SEPARATOR . 'bootstrap-baseline';
$comparePrefix = $resultsDir . DIRECTORY_SEPARATOR . 'bootstrap-compare';

ensureDirectory($resultsDir);
ensureDirectory($resultsDir . DIRECTORY_SEPARATOR . 'storage');

$mode = $argv[1] ?? 'compare';
if (! in_array($mode, ['record', 'compare'], true)) {
	fwrite(STDERR, "Usage: php benchmark/bootstrap_bench.php [record|compare]\n");
	exit(1);
}

$latestXml = $latestPrefix . '.xml';
$latestTxt = $latestPrefix . '.txt';
$latestJson = $latestPrefix . '.json';
$baselineXml = $baselinePrefix . '.xml';
$baselineTxt = $baselinePrefix . '.txt';
$baselineJson = $baselinePrefix . '.json';
$compareTxt = $comparePrefix . '.txt';
$compareJson = $comparePrefix . '.json';

runBenchmarkDump($root, $latestXml);

$latestConsole = renderReport($root, [$latestXml], 'console');
file_put_contents($latestTxt, normalizeOutput($latestConsole));

$latestJsonRaw = renderReport($root, [$latestXml], 'json');
file_put_contents($latestJson, prettyJson($latestJsonRaw));

if ($mode === 'record') {
	copy($latestXml, $baselineXml);
	copy($latestTxt, $baselineTxt);
	copy($latestJson, $baselineJson);

	echo "Recorded bootstrap baseline:\n";
	echo " - {$baselineXml}\n";
	echo " - {$baselineTxt}\n";
	echo " - {$baselineJson}\n";
	exit(0);
}

if (! file_exists($baselineXml)) {
	fwrite(STDERR, "Baseline file not found: {$baselineXml}\nRun `composer bench:bootstrap:record` first.\n");
	exit(1);
}

$compareConsole = renderReport($root, [$baselineXml, $latestXml], 'console');
file_put_contents($compareTxt, normalizeOutput($compareConsole));

$compareJsonRaw = renderReport($root, [$baselineXml, $latestXml], 'json');
file_put_contents($compareJson, prettyJson($compareJsonRaw));

echo "Wrote bootstrap comparison artifacts:\n";
echo " - {$latestTxt}\n";
echo " - {$latestJson}\n";
echo " - {$compareTxt}\n";
echo " - {$compareJson}\n";

function runBenchmarkDump(string $root, string $dumpFile): void
{
	$result = runCommand($root, [
		PHP_BINARY,
		$root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpbench',
		'run',
		'--config=phpbench.json',
		'--filter=BootstrapBench',
		'--progress=none',
		'--dump-file=' . $dumpFile,
		'--quiet',
		'--no-ansi',
	]);

	if ($result['exitCode'] !== 0) {
		fwrite(STDERR, $result['stderr'] . PHP_EOL);
		exit($result['exitCode']);
	}
}

function renderReport(string $root, array $xmlFiles, string $output): string
{
	$command = [
		PHP_BINARY,
		$root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpbench',
		'report',
		'--config=phpbench.json',
		'--report=baseline-summary',
		'--output=' . $output,
		'--no-ansi',
	];

	foreach ($xmlFiles as $file) {
		$command[] = '--file=' . $file;
	}

	$result = runCommand($root, $command);
	if ($result['exitCode'] !== 0) {
		fwrite(STDERR, $result['stderr'] . PHP_EOL);
		exit($result['exitCode']);
	}

	return $result['stdout'];
}

/**
 * @return array{exitCode:int, stdout:string, stderr:string}
 */
function runCommand(string $cwd, array $command): array
{
	$descriptors = [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];

	$process = proc_open(
		$command,
		$descriptors,
		$pipes,
		$cwd,
		null,
		['bypass_shell' => true]
	);

	if (! is_resource($process)) {
		throw new RuntimeException('Unable to start process.');
	}

	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);

	return [
		'exitCode' => $exitCode,
		'stdout' => $stdout === false ? '' : $stdout,
		'stderr' => $stderr === false ? '' : $stderr,
	];
}

function ensureDirectory(string $path): void
{
	if (! is_dir($path)) {
		mkdir($path, 0777, true);
	}
}

function prettyJson(string $json): string
{
	$json = stripDeprecationLines($json);
	$decoded = json_decode(trim($json), true);
	if ($decoded === null && trim($json) !== 'null') {
		return trim($json) . PHP_EOL;
	}

	return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function normalizeOutput(string $output): string
{
	return rtrim(str_replace("\r\n", "\n", stripDeprecationLines($output))) . PHP_EOL;
}

function stripDeprecationLines(string $output): string
{
	$lines = preg_split("/\r\n|\n|\r/", $output);
	if ($lines === false) {
		return $output;
	}

	$filtered = array_values(array_filter(
		$lines,
		static fn (string $line): bool => ! str_contains($line, 'Deprecated:')
	));

	return implode(PHP_EOL, $filtered);
}
