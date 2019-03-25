<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\DatabaseDI;

use Nette;
use Nette\DI\Config\Expect;


/**
 * Nette Framework Database services.
 */
class DatabaseExtension extends Nette\DI\CompilerExtension
{
	/** @var DatabaseConfig[] */
	public $config;

	/** @var bool */
	private $debugMode;


	public function __construct(bool $debugMode = false)
	{
		$this->debugMode = $debugMode;
	}


	public function getConfigSchema(): Nette\DI\Config\Schema
	{
		return Expect::arrayOf(Expect::from(new DatabaseConfig))
			->normalize(function ($val) {
				return is_array(reset($val)) || reset($val) === null
					? $val
					: ['default' => $val];
			});
	}


	public function loadConfiguration()
	{
		$autowired = true;
		foreach ($this->config as $name => $config) {
			$config->autowired = $config->autowired ?? $autowired;
			$autowired = false;
			$this->setupDatabase($config, $name);
		}
	}


	private function setupDatabase(DatabaseConfig $config, string $name): void
	{
		$builder = $this->getContainerBuilder();

		foreach ($config->options as $key => $value) {
			if (is_string($value) && preg_match('#^PDO::\w+\z#', $value)) {
				$config->options[$key] = $value = constant($value);
			}
			if (preg_match('#^PDO::\w+\z#', $key)) {
				unset($config->options[$key]);
				$config->options[constant($key)] = $value;
			}
		}

		$connection = $builder->addDefinition($this->prefix("$name.connection"))
			->setFactory(Nette\Database\Connection::class, [$config->dsn, $config->user, $config->password, $config->options])
			->setAutowired($config->autowired);

		$structure = $builder->addDefinition($this->prefix("$name.structure"))
			->setFactory(Nette\Database\Structure::class)
			->setArguments([$connection])
			->setAutowired($config->autowired);

		if (!empty($config->reflection)) {
			$conventionsServiceName = 'reflection';
			$config->conventions = $config->reflection;
			if (is_string($config->conventions) && strtolower($config->conventions) === 'conventional') {
				$config->conventions = 'Static';
			}
		} else {
			$conventionsServiceName = 'conventions';
		}

		if (!$config->conventions) {
			$conventions = null;

		} elseif (is_string($config->conventions)) {
			$conventions = $builder->addDefinition($this->prefix("$name.$conventionsServiceName"))
				->setFactory(preg_match('#^[a-z]+\z#i', $config->conventions)
					? 'Nette\Database\Conventions\\' . ucfirst($config->conventions) . 'Conventions'
					: $config->conventions)
				->setArguments(strtolower($config->conventions) === 'discovered' ? [$structure] : [])
				->setAutowired($config->autowired);

		} else {
			$conventions = Nette\DI\Config\Processor::processArguments([$config['conventions']])[0];
		}

		$builder->addDefinition($this->prefix("$name.context"))
			->setFactory(Nette\Database\Context::class, [$connection, $structure, $conventions])
			->setAutowired($config->autowired);

		if ($config->debugger) {
			$connection->addSetup('@Tracy\BlueScreen::addPanel', [
				[Nette\Bridges\DatabaseTracy\ConnectionPanel::class, 'renderException'],
			]);
			if ($this->debugMode) {
				$connection->addSetup([Nette\Database\Helpers::class, 'createDebugPanel'], [$connection, !empty($config->explain), $name]);
			}
		}

		if ($this->name === 'database') {
			$builder->addAlias($this->prefix($name), $this->prefix("$name.connection"));
			$builder->addAlias("nette.database.$name", $this->prefix($name));
			$builder->addAlias("nette.database.$name.context", $this->prefix("$name.context"));
		}
	}
}
