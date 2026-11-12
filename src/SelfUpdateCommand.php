<?php

namespace SelfUpdate;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as sfFilesystem;
use UnexpectedValueException;

/**
 * Update the *.phar from the latest GitHub release.
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateCommand extends Command
{
    public const SELF_UPDATE_COMMAND_NAME = 'self:update';

    public function __construct(private readonly SelfUpdateManager $selfUpdateManager, private readonly bool $ignorePharRunningCheck = false)
    {
        parent::__construct(self::SELF_UPDATE_COMMAND_NAME);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $app = $this->selfUpdateManager->applicationName;

        // Follow Composer's pattern of command and channel names.
        $this
            ->setAliases(array('update', 'self-update'))
            ->setDescription("Updates $app to the latest version.")
            ->addArgument('version_constraint', InputArgument::OPTIONAL, 'Apply version constraint')
            ->addOption('stable', NULL, InputOption::VALUE_NONE, 'Use stable releases (default)')
            ->addOption('preview', NULL, InputOption::VALUE_NONE, 'Preview unstable (e.g., alpha, beta, etc.) releases')
            ->addOption('compatible', NULL, InputOption::VALUE_NONE, 'Stay on current major version')
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks GitHub for newer
versions of $app and if found, installs the latest.
EOT
            );
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->ignorePharRunningCheck && empty(\Phar::running())) {
            throw new \RuntimeException(self::SELF_UPDATE_COMMAND_NAME . ' only works when running the phar version of ' . $this->selfUpdateManager->applicationName . '.');
        }

        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $programName   = basename($localFilename);
        $isPhar = PHP_SAPI !== 'micro';
        $tempFilename = dirname($localFilename) . '/' . basename($localFilename, $isPhar ? '.phar' : '') . '-temp' . ($isPhar ? '.phar' : '.tar.gz');

        // check for permissions in local filesystem before start connection process
        if (! is_writable($tempDirectory = dirname($tempFilename))) {
            throw new \RuntimeException(
                $programName . ' update failed: the "' . $tempDirectory .
                '" directory used to download the temp file could not be written'
            );
        }

        if (!is_writable($localFilename)) {
            throw new \RuntimeException(
                $programName . ' update failed: the "' . $localFilename . '" file could not be written (execute with sudo)'
            );
        }

        $isPreviewOptionSet = $input->getOption('preview');
        $isStable = $input->getOption('stable') || !$isPreviewOptionSet;
        if ($isPreviewOptionSet && $isStable) {
            throw new \RuntimeException(self::SELF_UPDATE_COMMAND_NAME . ' support either stable or preview, not both.');
        }

        $isCompatibleOptionSet = $input->getOption('compatible');
        $versionConstraintArg = $input->getArgument('version_constraint');

        // Determine asset patterns
        if ($isPhar) {
            $assetPattern = '/^.*\.phar$/i';
        } else {
            // Assume native binary tar.gz pattern: native-<name>-<platform>.tar.gz
            $platform = php_uname('s') === 'Linux' ? 'linux' : (php_uname('s') === 'Darwin' ? 'macos' : 'windows');
            $arch = php_uname('m');
            // Maybe one day the world can agree on what to call this architecture.
            if ($arch === 'arm64') {
                $arch = 'aarch64';
            }
            $assetPattern = '/^.*-' . $platform . '-' . $arch . '\.tar.gz$/i';
        }

        $options = [
            'preview' => $isPreviewOptionSet,
            'compatible' => $isCompatibleOptionSet,
            'version_constraint' => $versionConstraintArg,
            'asset_pattern' => $assetPattern,
        ];

        if ($this->selfUpdateManager->isUpToDate($options)) {
            $output->writeln('No update available');
            return Command::SUCCESS;
        }

        $latestRelease = $this->selfUpdateManager->getLatestReleaseFromGithub($options);

        $fs = new sfFilesystem();

        $output->writeln('Downloading ' . $this->selfUpdateManager->applicationName . ' (' . $this->selfUpdateManager->gitHubRepository . ') ' . $latestRelease['tag_name']);

        $fs->copy($latestRelease['download_url'], $tempFilename);

        $output->writeln('Download finished');

        try {
            \error_reporting(E_ALL); // suppress notices

            if ($isPhar) {
                $output->writeln('<info>Updating phar...</info>');
                @chmod($tempFilename, 0777 & ~umask());
                // test the phar validity
                $phar = new \Phar($tempFilename);
                unset($phar);
                @rename($tempFilename, $localFilename);
                $output->writeln('<info>Successfully updated ' . $programName . '</info>');
            } else {
                $output->writeln('<info>Updating native binary...</info>');
                // Native binary: extract from tar.gz and replace
                try {
                    $phar = new \PharData($tempFilename);
                    // Extract binary (assume same name as programName)
                    $extractedPath = dirname($localFilename) . '/' . $programName . '-extracted';
                    if (!mkdir($extractedPath) && !is_dir($extractedPath)) {
                        throw new \RuntimeException(sprintf('Directory "%s" was not created', $extractedPath));
                    }
                    $phar->extractTo($extractedPath, $programName, TRUE);
                    @chmod($extractedPath . '/' . $programName, 0777 & ~umask());
                    @rename($extractedPath . '/' . $programName, $localFilename);
                    @unlink($tempFilename);
                    @rmdir($extractedPath);
                    $output->writeln('<info>Successfully updated ' . $programName . '</info>');
                } catch (\Exception $e) {
                    throw new \RuntimeException('Failed to extract binary from tar.gz: ' . $e->getMessage());
                }
            }

            $this->_exit();
        } catch (\Exception $e) {
            @unlink($tempFilename);
            $output->writeln('<error>Update failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Stop execution
     *
     * This is a workaround to prevent warning of dispatcher after replacing
     * the phar file.
     *
     * @return void
     */
    protected function _exit(): void {
        exit;
    }
}
