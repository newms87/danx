<?php

namespace Newms87\Danx\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'vapor:decrypt')]
class VaporDecryptCommand extends Command
{
	protected        $signature   = 'vapor:decrypt {env}';
	protected static $defaultName = 'vapor:decrypt';
	protected        $description = 'Decrypt an environment file used for vapor deployed environments';

	protected Filesystem $files;

	public function __construct(Filesystem $files)
	{
		parent::__construct();
		$this->files = $files;
	}

	public function handle()
	{
		$key = config('danx.encryption.key');

		if (!$key) {
			$this->components->error('The danx.encryption.key config is required.');

			return Command::FAILURE;
		}

		$cipher = 'AES-256-CBC';
		$key    = $this->parseKey($key);

		$outputFile    = base_path('.env.' . $this->argument('env'));
		$encryptedFile = $outputFile . '.encrypted';

		if (!$this->files->exists($encryptedFile)) {
			$this->components->error('Encrypted environment file not found.');

			return Command::FAILURE;
		}

		try {
			$encrypter = new Encrypter($key, $cipher);

			$this->files->put(
				$outputFile,
				$encrypter->decrypt($this->files->get($encryptedFile))
			);
		} catch(Exception $e) {
			$this->components->error($e->getMessage());

			return Command::FAILURE;
		}

		$this->components->info('Environment successfully decrypted.');

		$this->components->twoColumnDetail('Decrypted file', $outputFile);

		$this->newLine();

		return Command::SUCCESS;
	}

	/**
	 * Parse the encryption key.
	 *
	 * @param string $key
	 * @return string
	 */
	protected function parseKey(string $key)
	{
		if (Str::startsWith($key, $prefix = 'base64:')) {
			$key = base64_decode(Str::after($key, $prefix));
		}

		return $key;
	}
}
