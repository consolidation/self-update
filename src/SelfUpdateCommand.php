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
 * Update the *.phar from the latest github release.
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateCommand extends Command
{
    const SELF_UPDATE_COMMAND_NAME = 'self:update';

    protected $gitHubRepository;

    protected $currentVersion;

    protected $applicationName;

    /**
     * @var bool
     */
    protected $isPreview = false;

    /**
     * @var bool
     */
    protected $isStable = true;

    /**
     * @var bool
     */
    protected $isCompatible = false;

    /**
     * @var null|string
     */
    protected $versionConstraint = null;

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
            ->addOption('version_constraint', NULL, InputOption::VALUE_REQUIRED, 'Apply version constraint')
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks github for newer
versions of $app and if found, installs the latest.
EOT
            );
    }

    /**
     * Get all releases from GitHub.
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function getReleasesFromGithub()
    {
        $version_parser = new VersionParser();
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . $this->applicationName  . ' (' . $this->gitHubRepository . ')' . ' Self-Update (PHP)',
                ],
            ],
        ];

        $context = stream_context_create($opts);

        $releases = file_get_contents('https://api.github.com/repos/' . $this->gitHubRepository . '/releases', false, $context);
        $releases = json_decode($releases);

        if (!isset($releases[0])) {
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

            if (null !== $this->versionConstraint && !Semver::satisfies($normalized, $this->versionConstraint)) {
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
     * Get the latest release version and download URL according to given constraints.
     *
     * @throws \Exception
     *
     * @return string[]|null
     *  "version" and "download_url" elements if the latest release is available, otherwise - NULL.
     */
    public function getLatestReleaseFromGithub() {
        foreach ($this->getReleasesFromGithub() as $release) {
            // We do not care about this release if it does not contain assets.
            if (!isset($release['assets'][0]) || !is_object($release['assets'][0])) {
                continue;
            }

            $releaseVersion = $release['tag_name'];
            if ($this->isCompatible
                && null !== $this->getMajorVersionConstraint()
                && !Semver::satisfies($releaseVersion , $this->getMajorVersionConstraint())) {
                // If it does not satisfies, look for the next one.
                continue;
            }

            if (!$this->isPreview && VersionParser::parseStability($releaseVersion ) !== 'stable') {
                // If preview not requested and current version is not stable, look for the next one.
                continue;
            }

            if (Semver::satisfies($releaseVersion, $this->currentVersion)) {
                // The latest release matches the current one.
                return null;
            }

            return [
                'version' => $releaseVersion,
                'download_url' => $release['assets'][0]->browser_download_url,
            ];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
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

        if (!is_writable($localFilename)) {
            throw new \Exception(
                $programName . ' update failed: the "' . $localFilename . '" file could not be written (execute with sudo)'
            );
        }

        $this->isPreview = $input->getOption('preview');
        $this->isStable = $input->getOption('stable') || !$this->isPreview;
        if ($this->isPreview && $this->isStable) {
            throw new \Exception(self::SELF_UPDATE_COMMAND_NAME . ' support either stable or preview, not both.');
        }

        $this->isCompatible = $input->getOption('compatible');
        $this->versionConstraint = $input->getOption('version_constraint');

        $latestRelease = $this->getLatestReleaseFromGithub();
        if (null === $latestRelease) {
            $output->writeln('No update available');
            return 0;
        }

        $fs = new sfFilesystem();

        $output->writeln('Downloading ' . $this->applicationName . ' (' . $this->gitHubRepository . ') ' . $latestRelease['version']);

        $fs->copy($latestRelease['download_url'], $tempFilename);

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

            exit;
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
     * Returns the major version constraint.
     *
     * @return string|null
     */
    protected function getMajorVersionConstraint()
    {
        if (preg_match('/^v?(\d+)/', $this->currentVersion, $matches)) {
            return '^' . $matches[1];
        }

        return null;
    }
}
