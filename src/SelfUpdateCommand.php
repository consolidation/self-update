<?php

namespace SelfUpdate;

use JetBrains\PhpStorm\NoReturn;
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
        $tempFilename  = dirname($localFilename) . '/' . basename($localFilename, '.phar') . '-temp.phar';

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

        $options = [
            'preview' => $isPreviewOptionSet,
            'compatible' => $isCompatibleOptionSet,
            'version_constraint' => $versionConstraintArg,
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

            @chmod($tempFilename, 0777 & ~umask());
            // test the phar validity
            $phar = new \Phar($tempFilename);
            // free the variable to unlock the file
            unset($phar);
            @rename($tempFilename, $localFilename);
            $output->writeln('<info>Successfully updated ' . $programName . '</info>');

            $this->_exit();
        } catch (\Exception $e) {
            @unlink($tempFilename);
            if (! $e instanceof UnexpectedValueException && ! $e instanceof \PharException) {
                throw $e;
            }
            $output->writeln('<error>The download is corrupted (' . $e->getMessage() . ').</error>');
            $output->writeln('<error>Please re-run the self-update command to try again.</error>');

            return Command::FAILURE;
        }
    }

    /**
     * Stop execution
     *
     * This is a workaround to prevent warning of dispatcher after replacing
     * the phar file.
     *
     * @return void
     */
    #[NoReturn] protected function _exit(): void {
        exit;
    }
}
