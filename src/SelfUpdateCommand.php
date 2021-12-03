<?php

namespace SelfUpdate;

use Composer\Semver\VersionParser;
use Composer\Semver\Semver;
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

        // Follow Composer's pattern of command and channel names.
        $this
            ->setAliases(array('update', 'self-update'))
            ->setDescription("Updates $app to the latest version.")
            ->addOption('stable', NULL, InputOption::VALUE_NONE, 'Use stable releases (default)')
            ->addOption('preview', NULL, InputOption::VALUE_NONE, 'Preview unstable (e.g., alpha, beta, etc.) releases')
            ->addOption('compatible', NULL, InputOption::VALUE_NONE, 'Stay on current major version')
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks github for newer
versions of $app and if found, installs the latest.
EOT
            );
    }

    /**
     * Get all releases from Github.
     */
    protected function getReleasesFromGithub()
    {
        $version_parser = new VersionParser();
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
        $parsed_releases = [];
        foreach ($releases as $release) {
            try {
                $normalized = $version_parser->normalize($release->tag_name);
            } catch (\UnexpectedValueException $e) {
                // If this version does not look quite right, let's ignore it.
                continue;
            }
            $parsed_releases[$normalized] = [
                'tag_name' => $normalized,
                'assets' => $release->assets,
            ];
        }
        $sorted_versions = Semver::rsort(array_keys($parsed_releases));
        $sorted_releases = [];
        foreach ($sorted_versions as $version) {
            $sorted_releases[$version] = $parsed_releases[$version];
        }
        return $sorted_releases;
    }

    /**
     * Get latest release according to given constraints
     */
    public function getLatestReleaseFromGithub($preview = false, $major_constraint = '') {
        $releases = $this->getReleasesFromGithub();
        $version = null;
        $url = null;

        foreach ($releases as $release) {
            // We do not care about this release if it does not contain assets.
            if (count($release['assets']) && is_object($release['assets'][0])) {
                $current_version = $release['tag_name'];
                if ($major_constraint) {
                    if (!Semver::satisfies($current_version, $major_constraint)) {
                        // If it does not satisfies, look for the next one.
                        continue;
                    }
                }
                if (!$preview && VersionParser::parseStability($current_version) !== 'stable') {
                    // If preview not requested and current version is not stable, look for the next one.
                    continue;
                }
                $url = $release['assets'][0]->browser_download_url;
                $version = $current_version;
                break;
            }
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

        $preview = $input->getOption('preview');
        $stable = $input->getOption('stable') || !$preview;
        $compatible = $input->getOption('compatible');
        $major_constraint = '';
        if ($preview && $stable) {
            throw new \Exception(self::SELF_UPDATE_COMMAND_NAME . ' support either stable or preview, not both.');
        }

        if ($compatible) {
            if (preg_match('/^v?(\d+)/', $this->currentVersion, $matches)) {
                $current_major = $matches[1];
                $major_constraint = "^${current_major}";
            }
        }

        list($latest, $downloadUrl) = $this->getLatestReleaseFromGithub($preview, $major_constraint);

        if (!$latest || Semver::satisfies($latest, $this->currentVersion)) {
            $output->writeln('No update available');
            return 0;
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
            return 1;
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
