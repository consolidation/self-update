<?php

namespace SelfUpdate;

use Composer\Semver\VersionParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as sfFilesystem;

/**
 * Update the robo.phar from the latest github release
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateCommand extends Command
{
    const SELF_UPDATE_COMMAND_NAME = 'self:update';

    protected $gitHubRepository;

    protected $currentVersion;

    protected $applicationName;

    public function __construct($applicationName = null, $currentVersion = null, $gitHubRepository = null)
    {
        $this->applicationName = $applicationName;
        $this->currentVersion = $currentVersion;
        $this->gitHubRepository = $gitHubRepository;

        parent::__construct(self::SELF_UPDATE_COMMAND_NAME);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $app = $this->applicationName;

        $this
            ->setAliases(array('update'))
            ->setDescription("Updates $app to the latest version.")
            ->addOption('allow-unstable', NULL, InputOption::VALUE_NONE, 'Allow unstable (e.g., alpha, beta, etc.) releases to be downloaded')
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks github for newer
versions of $app and if found, installs the latest.
EOT
            );
    }

    protected function getReleasesFromGithub()
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . $this->applicationName  . ' (' . $this->gitHubRepository . ')' . ' Self-Update (PHP)'
                ]
            ]
        ];

        $context = stream_context_create($opts);

        $releases = file_get_contents('https://api.github.com/repos/' . $this->gitHubRepository . '/releases', false, $context);
        $releases = json_decode($releases);

        if (! isset($releases[0])) {
            throw new \Exception('API error - no release found at GitHub repository ' . $this->gitHubRepository);
        }
        return $releases;
    }

    protected function getLatestReleaseFromGithub()
    {
        $releases = $this->getReleasesFromGithub();
        $version = $releases[0]->tag_name;
        $url     = $releases[0]->assets[0]->browser_download_url;

        return [ $version, $url ];
    }

    protected function getLatestStableReleaseFromGithub()
    {
        $releases = $this->getReleasesFromGithub();

        foreach ($releases as $release) {
            $version = $release->tag_name;
            $url     = $release->assets[0]->browser_download_url;
            if (VersionParser::parseStability($version) === 'stable') {
                break;
            }
        }

        if (VersionParser::parseStability($version) !== 'stable') {
            throw new \Exception('API error - no stable release found at GitHub repository ' . $this->gitHubRepository);
        }

        return [ $version, $url ];
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (empty(\Phar::running())) {
            throw new \Exception(self::SELF_UPDATE_COMMAND_NAME . ' only works when running the phar version of ' . $this->applicationName . '.');
        }

        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $programName   = basename($localFilename);
        $tempFilename  = dirname($localFilename) . '/' . basename($localFilename, '.phar') . '-temp.phar';

        // check for permissions in local filesystem before start connection process
        if (! is_writable($tempDirectory = dirname($tempFilename))) {
            throw new \Exception(
                $programName . ' update failed: the "' . $tempDirectory .
                '" directory used to download the temp file could not be written'
            );
        }

        if (! is_writable($localFilename)) {
            throw new \Exception(
                $programName . ' update failed: the "' . $localFilename . '" file could not be written (execute with sudo)'
            );
        }

        if ($input->getOption('allow-unstable') !== FALSE) {
            list( $latest, $downloadUrl ) = $this->getLatestReleaseFromGithub();
        }
        else {
            list( $latest, $downloadUrl ) = $this->getLatestStableReleaseFromGithub();
        }

        if ($this->currentVersion == $latest) {
            $output->writeln('No update available');
            return;
        }

        $fs = new sfFilesystem();

        $output->writeln('Downloading ' . $this->applicationName . ' (' . $this->gitHubRepository . ') ' . $latest);

        $fs->copy($downloadUrl, $tempFilename);

        $output->writeln('Download finished');

        try {
            \error_reporting(E_ALL); // supress notices

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
            if (! $e instanceof \UnexpectedValueException && ! $e instanceof \PharException) {
                throw $e;
            }
            $output->writeln('<error>The download is corrupted (' . $e->getMessage() . ').</error>');
            $output->writeln('<error>Please re-run the self-update command to try again.</error>');
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
    protected function _exit()
    {
        exit;
    }
}
