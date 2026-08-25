<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit;

use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Points the captured-mail storage at a throwaway directory.
 *
 * MailcatcherState resolves everything from Environment::getVarPath(), and it is
 * static by design — it runs in ext_localconf.php, before the container exists,
 * so it cannot take an injected path. A test that writes state.json therefore
 * writes into the developer's own installation: it would switch a real catcher
 * on or off, and its result would depend on what that installation happened to
 * be doing. Re-pointing varPath keeps the tests hermetic without changing the
 * production design.
 *
 * Environment is static too, so every value is captured and restored — leaving a
 * rewritten varPath behind would silently affect every test that runs after.
 */
abstract class AbstractStorageTestCase extends UnitTestCase
{
    protected string $storageDirectory = '';

    private string $temporaryVarPath = '';

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->environmentBackup = [
            'context' => Environment::getContext(),
            'cli' => Environment::isCli(),
            'composerMode' => Environment::isComposerMode(),
            'projectPath' => Environment::getProjectPath(),
            'publicPath' => Environment::getPublicPath(),
            'varPath' => Environment::getVarPath(),
            'configPath' => Environment::getConfigPath(),
            'currentScript' => Environment::getCurrentScript(),
            'os' => Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        ];

        $this->temporaryVarPath = sys_get_temp_dir() . '/ot-mailcatcher-test-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryVarPath, 0775, true);

        $this->applyEnvironment($this->temporaryVarPath);

        $this->storageDirectory = $this->temporaryVarPath . '/mailcatcher';
        mkdir($this->storageDirectory, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->applyEnvironment($this->environmentBackup['varPath']);

        if ($this->temporaryVarPath !== '' && is_dir($this->temporaryVarPath)) {
            $this->removeDirectory($this->temporaryVarPath);
        }

        parent::tearDown();
    }

    /**
     * Writes the state file the way the backend module would.
     */
    protected function switchCatcher(bool $enabled): void
    {
        file_put_contents(
            $this->storageDirectory . '/state.json',
            json_encode(['enabled' => $enabled, 'changedAt' => date(\DATE_ATOM)], JSON_THROW_ON_ERROR)
        );
    }

    protected function placeCapturedMail(string $fileName, string $contents = "Subject: Test\r\n\r\nBody"): void
    {
        file_put_contents($this->storageDirectory . '/' . $fileName, $contents);
    }

    private function applyEnvironment(string $varPath): void
    {
        Environment::initialize(
            $this->environmentBackup['context'] instanceof ApplicationContext
                ? $this->environmentBackup['context']
                : new ApplicationContext('Testing'),
            $this->environmentBackup['cli'],
            $this->environmentBackup['composerMode'],
            $this->environmentBackup['projectPath'],
            $this->environmentBackup['publicPath'],
            $varPath,
            $this->environmentBackup['configPath'],
            $this->environmentBackup['currentScript'],
            $this->environmentBackup['os']
        );
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
