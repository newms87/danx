<?php

namespace Newms87\DanxLaravel\Traits;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

trait ConsoleStreamable
{
	const
		OUTPUT_COMMENT = 'comment',
		OUTPUT_CRITICAL = 'critical',
		OUTPUT_DEBUG = 'debug',
		OUTPUT_ERROR = 'error',
		OUTPUT_HIGHLIGHT = 'highlight',
		OUTPUT_INFO = 'info',
		OUTPUT_SUCCESS = 'success',
		OUTPUT_TEXT = 'text',
		OUTPUT_WARNING = 'warning';

	const OUTPUT_STYLE = [
		self::OUTPUT_COMMENT   => ['magenta'],
		self::OUTPUT_CRITICAL  => ['white', 'red'],
		self::OUTPUT_DEBUG     => ['blue'],
		self::OUTPUT_ERROR     => ['red'],
		self::OUTPUT_HIGHLIGHT => ['white', 'yellow'],
		self::OUTPUT_INFO      => ['cyan'],
		self::OUTPUT_SUCCESS   => ['green'],
		self::OUTPUT_TEXT      => ['default'],
		self::OUTPUT_WARNING   => ['yellow'],
	];

	protected ?OutputStyle $consoleOutput = null;
	protected ?ArgvInput   $consoleInput  = null;

	/**
	 * @return OutputStyle
	 */
	public function consoleOutput(): OutputStyle
	{
		if (!$this->consoleOutput) {
			$this->configureConsoleStream();
		}

		return $this->consoleOutput;
	}

	/**
	 * @return ArgvInput
	 */
	public function consoleInput(): ArgvInput
	{
		if (!$this->consoleInput) {
			$this->configureConsoleStream();
		}

		return $this->consoleInput;
	}

	/**
	 * Configure the Input and Output streams.
	 * Will also setup all the styles for the output stream
	 */
	public function configureConsoleStream()
	{
		$output             = new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG, null, new OutputFormatter());
		$this->consoleInput = new ArgvInput();

		$this->consoleOutput = app(
			OutputStyle::class, [
				'input'  => $this->consoleInput,
				'output' => $output,
			]
		);

		foreach(self::OUTPUT_STYLE as $style => $colors) {
			$formatter = new OutputFormatterStyle(...$colors);

			$this->consoleOutput->getFormatter()->setStyle($style, $formatter);
		}
	}

	/**
	 * Debug styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function debug($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_DEBUG, $verbosity);
	}

	/**
	 * Info styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function info($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_INFO, $verbosity);
	}

	/**
	 * Comment styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function comment($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_COMMENT, $verbosity);
	}

	/**
	 * Warning styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function warning($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_WARNING, $verbosity);
	}

	/**
	 * Error styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function error($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_ERROR, $verbosity);
	}

	/**
	 * Critical styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function critical($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_CRITICAL, $verbosity);
	}

	/**
	 * Highlighted styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function highlight($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_HIGHLIGHT, $verbosity);
	}

	/**
	 * Success styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function success($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_SUCCESS, $verbosity);
	}

	/**
	 * Default styled output
	 *
	 * @param     $string
	 * @param int $verbosity
	 */
	public function text($string, $verbosity = OutputInterface::VERBOSITY_DEBUG)
	{
		$this->output($string, self::OUTPUT_TEXT, $verbosity);
	}

	/**
	 * Write to the console w/ style
	 *
	 * @param        $string
	 * @param string $style
	 * @param int    $verbosity
	 */
	public function output(
		$string,
		$style = self::OUTPUT_INFO,
		$verbosity = OutputInterface::VERBOSITY_DEBUG
	)
	{
		$styled = $style ? "<$style>$string</$style>" : $string;

		$this->consoleOutput()->writeln($styled, $verbosity);
	}

	/**
	 * Confirm a question with the user.
	 *
	 * @param string $question
	 * @param bool   $default
	 * @return bool
	 */
	public function confirm($question, $default = false): bool
	{
		return $this->consoleOutput()->confirm($question, $default);
	}

	/**
	 * Prompt the user for input.
	 *
	 * @param string      $question
	 * @param string|null $default
	 * @return mixed
	 */
	public function ask($question, $default = null)
	{
		return $this->consoleOutput()->ask($question, $default);
	}

	/**
	 * Prompt the user for input with auto-completion.
	 *
	 * @param string      $question
	 * @param array       $choices
	 * @param string|null $default
	 * @return mixed
	 */
	public function anticipate($question, $choices, $default = null)
	{
		return $this->askWithCompletion($question, $choices, $default);
	}

	/**
	 * Prompt the user for input with auto-completion.
	 *
	 * @param string      $question
	 * @param array       $choices
	 * @param string|null $default
	 * @return mixed
	 */
	public function askWithCompletion($question, $choices, $default = null)
	{
		$question = new Question($question, $default);

		$question->setAutocompleterValues($choices);

		return $this->consoleOutput()->askQuestion($question);
	}

	/**
	 * Prompt the user for input but hide the answer from the console.
	 *
	 * @param string $question
	 * @param bool   $fallback
	 * @return mixed
	 */
	public function secret($question, $fallback = true)
	{
		$question = new Question($question);

		$question->setHidden(true)->setHiddenFallback($fallback);

		return $this->consoleOutput()->askQuestion($question);
	}

	/**
	 * Give the user a single choice from an array of answers.
	 *
	 * @param string      $question
	 * @param array       $choices
	 * @param string|null $default
	 * @param mixed|null  $attempts
	 * @param bool|null   $multiple
	 * @return string
	 */
	public function choice($question, $choices, $default = null, $attempts = null, $multiple = null)
	{
		$question = new ChoiceQuestion($question, $choices, $default);

		$question->setMaxAttempts($attempts)->setMultiselect($multiple);

		return $this->consoleOutput()->askQuestion($question);
	}

	/**
	 * Format input to textual table.
	 *
	 * @param array           $headers
	 * @param Arrayable|array $rows
	 * @param string          $tableStyle
	 * @param array           $columnStyles
	 * @return void
	 */
	public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
	{
		$table = new Table($this->consoleOutput());

		if ($rows instanceof Arrayable) {
			$rows = $rows->toArray();
		}

		$table->setHeaders((array)$headers)->setRows($rows)->setStyle($tableStyle);

		foreach($columnStyles as $columnIndex => $columnStyle) {
			$table->setColumnStyle($columnIndex, $columnStyle);
		}

		$table->render();
	}
}
