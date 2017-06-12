<?php

/**
 * Source Codes Checker.
 *
 * This file is part of the Nette Framework (https://nette.org)
 */

namespace Nette\CodeChecker;

use Nette;
use Nette\CommandLine\Parser;

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo('Install packages using `composer update`');
	exit(1);
}

set_exception_handler(function ($e) {
	echo "Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n";
	die(2);
});

set_error_handler(function ($severity, $message, $file, $line) {
	if (($severity & error_reporting()) === $severity) {
		throw new \ErrorException($message, 0, $severity, $file, $line);
	}
	return FALSE;
});


echo '
CodeChecker version 2.9
-----------------------
';

$cmd = new Parser(<<<XX
Usage:
    php code-checker.php [options]

Options:
    -d <path>             Folder or file to scan (default: current directory)
    -i | --ignore <mask>  Files to ignore
    -f | --fix            Fixes files
    -l | --eol            Convert newline characters
    --no-progress         Do not show progress dots
    --short-arrays        Enforces PHP 5.4 short array syntax
    --strict-types        Checks whether PHP 7.0 directive strict_types is enabled


XX
, [
	'-d' => [Parser::REALPATH => TRUE, Parser::VALUE => getcwd()],
	'--ignore' => [Parser::REPEATABLE => TRUE],
]);

$options = $cmd->parse();
if ($cmd->isEmpty()) {
	$cmd->help();
}



class CodeChecker extends Nette\Object
{
	/** @var bool */
	public $readOnly = FALSE;

	/** @var bool */
	public $showProgress = FALSE;

	/** @var bool */
	public $useColors;

	public $accept = [
		'*.php', '*.phpt', '*.inc',
		'*.txt', '*.texy', '*.md',
		'*.css', '*.less', '*.sass', '*.scss', '*.js', '*.json', '*.latte', '*.htm', '*.html', '*.phtml', '*.xml',
		'*.ini', '*.neon', '*.yml',
		'*.sh', '*.bat',
		'*.sql',
		'.htaccess', '.gitignore',
	];

	public $ignore = [
		'.git', '.svn', '.idea', '*.tmp', 'tmp', 'temp', 'log', 'vendor', 'node_modules', 'bower_components',
		'*.min.js', 'package.json', 'package-lock.json',
	];

	private $tasks = [];

	/** @var string */
	private $relativePath;

	/** @var bool */
	private $error;


	public function run($path)
	{
		set_time_limit(0);

		$this->useColors = PHP_SAPI === 'cli' && ((function_exists('posix_isatty') && posix_isatty(STDOUT))
				|| getenv('ConEmuANSI') === 'ON' || getenv('ANSICON') !== FALSE || getenv('term') === 'xterm-256color');

		if ($this->readOnly) {
			echo "Running in read-only mode\n";
		}

		echo "Scanning {$this->color('white', $path)}\n";

		$counter = 0;
		$success = TRUE;
		$files = is_file($path)
			? [$path]
			: Nette\Utils\Finder::findFiles($this->accept)->exclude($this->ignore)->from($path)->exclude($this->ignore);

		foreach ($files as $file)
		{
			if ($this->showProgress) {
				echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";
			}
			$this->relativePath = ltrim(substr($file, strlen($path)), '/\\');
			$success = $this->processFile($file) && $success;
		}

		if ($this->showProgress) {
			echo str_pad('', 40), "\x0D";
		}

		echo "Done.\n";
		return $success;
	}


	public function addTask(callable $task, $pattern = NULL)
	{
		$this->tasks[] = [$task, $pattern];
	}


	/**
	 * @param  string
	 * @return bool
	 */
	private function processFile($file)
	{
		$this->error = FALSE;
		$origContents = $lastContents = file_get_contents($file);

		foreach ($this->tasks as $task) {
			list($handler, $pattern) = $task;
			if ($pattern && !$this->matchFileName($pattern, basename($file))) {
				continue;
			}
			$contents = $lastContents;
			$handler($contents, $this);
			if (!$this->error) {
				$lastContents = $contents;
			}
		}

		if ($lastContents !== $origContents && !$this->readOnly) {
			file_put_contents($file, $lastContents);
		}
		return !$this->error;
	}


	private function matchFileName($pattern, $name)
	{
		$neg = substr($pattern, 0, 1) === '!';
		foreach (explode(',', ltrim($pattern, '!')) as $part) {
			if (fnmatch($part, $name, FNM_CASEFOLD)) {
				return !$neg;
			}
		}
		return $neg;
	}


	public function fix($message, $line = NULL)
	{
		$this->write($this->readOnly ? 'FOUND' : 'FIX', $message, $line, 'aqua');
		$this->error = $this->error || $this->readOnly;
	}


	public function warning($message, $line = NULL)
	{
		$this->write('WARNING', $message, $line, 'yellow');
	}


	public function error($message, $line = NULL)
	{
		$this->write('ERROR', $message, $line, 'red');
		$this->error = TRUE;
	}


	private function write($type, $message, $line, $color)
	{
		$base = basename($this->relativePath);
		echo $this->color($color, str_pad("[$type]", 10)),
			$base === $this->relativePath ? '' : $this->color('silver', dirname($this->relativePath) . DIRECTORY_SEPARATOR),
			$this->color('white', $base . ($line ? ':' . $line : '')), '    ',
			$this->color($color, $message), "\n";
	}


	private function color($color = NULL, $s = NULL)
	{
		static $colors = [
			'black' => '0;30', 'gray' => '1;30', 'silver' => '0;37', 'white' => '1;37',
			'navy' => '0;34', 'blue' => '1;34', 'green' => '0;32', 'lime' => '1;32',
			'teal' => '0;36', 'aqua' => '1;36', 'maroon' => '0;31', 'red' => '1;31',
			'purple' => '0;35', 'fuchsia' => '1;35', 'olive' => '0;33', 'yellow' => '1;33',
			NULL => '0',
		];
		if ($this->useColors) {
			$c = explode('/', $color);
			$s = "\033[" . ($c[0] ? $colors[$c[0]] : '')
				. (empty($c[1]) ? '' : ';4' . substr($colors[$c[1]], -1))
				. 'm' . $s . ($s === NULL ? '' : "\033[0m");
		}
		return $s;
	}

}



set_time_limit(0);

$checker = new CodeChecker;
$tasks = 'Nette\CodeChecker\Tasks';

foreach ($options['--ignore'] as $ignore) {
	$checker->ignore[] = $ignore;
}
$checker->readOnly = !isset($options['--fix']);
$checker->showProgress = !isset($options['--no-progress']);

$checker->addTask([$tasks, 'controlCharactersChecker']);
$checker->addTask([$tasks, 'bomFixer']);
$checker->addTask([$tasks, 'utf8Checker']);
$checker->addTask([$tasks, 'invalidPhpDocChecker'], '*.php,*.phpt');

if (isset($options['--short-arrays'])) {
	$checker->addTask([$tasks, 'shortArraySyntaxFixer'], '*.php,*.phpt');
}
if (isset($options['--strict-types'])) {
	$checker->addTask([$tasks, 'strictTypesDeclarationChecker'], '*.php,*.phpt');
}
if (isset($options['--eol'])) {
	$checker->addTask([$tasks, 'newlineNormalizer'], '!*.sh');
}

$checker->addTask([$tasks, 'invalidDoubleQuotedStringChecker'], '*.php,*.phpt');
$checker->addTask([$tasks, 'trailingPhpTagRemover'], '*.php,*.phpt');
$checker->addTask([$tasks, 'latteSyntaxChecker'], '*.latte');
$checker->addTask([$tasks, 'neonSyntaxChecker'], '*.neon');
$checker->addTask([$tasks, 'jsonSyntaxChecker'], '*.json');
$checker->addTask([$tasks, 'yamlIndentationChecker'], '*.yml');
$checker->addTask([$tasks, 'trailingWhiteSpaceFixer']);
$checker->addTask([$tasks, 'tabIndentationChecker'], '*.css,*.less,*.js,*.json,*.neon');
$checker->addTask([$tasks, 'tabIndentationPhpChecker'], '*.php,*.phpt');
$checker->addTask([$tasks, 'unexpectedTabsChecker'], '*.yml');

$ok = $checker->run($options['-d']);

exit($ok ? 0 : 1);
