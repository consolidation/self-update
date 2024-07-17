<?php

namespace SelfUpdate;

use Composer\Semver\VersionParser;
use Composer\Semver\Semver;
use Composer\Semver\Comparator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem as sfFilesystem;
use Symfony\Component\HttpClient\HttpClient;
use UnexpectedValueException;

/**
 * Update the *.phar from the latest GitHub release.
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateCommand extends Command
{
    public const SELF_UPDATE_COMMAND_NAME = 'self:update';

    protected string $gitHubRepository;

    protected string $currentVersion;

    protected string $applicationName;

    protected bool $ignorePharRunningCheck;

    public function __construct(string $applicationName = null, string $currentVersion = null, string $gitHubRepository = null)
    {
        $this->applicationName = $applicationName;
        $version_parser = new VersionParser();
        $this->currentVersion = $version_parser->normalize($currentVersion);
        $this->gitHubRepository = $gitHubRepository;
        $this->ignorePharRunningCheck = false;

        parent::__construct(self::SELF_UPDATE_COMMAND_NAME);
    }

    /**
     * Set ignorePharRunningCheck to true.
     */
    public function ignorePharRunningCheck($ignore = true): void
    {
        $this->ignorePharRunningCheck = $ignore;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $app = $this->applicationName;

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
     * Get all releases from GitHub.
     *
     * @return array
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *
     * @throws \Exception
     */
    protected function getReleasesFromGithub(): array
    {
        $version_parser = new VersionParser();

        $opts = [
            'headers' => [
                'User-Agent' => $this->applicationName  . ' (' . $this->gitHubRepository . ')' . ' Self-Update (PHP)',
            ],
        ];
        $client = HttpClient::create($opts);
        $response = $client->request(
            'GET',
            'https://api.github.com/repos/' . $this->gitHubRepository . '/releases'
        );

        $releases = json_decode($response->getContent(), FALSE, 512, JSON_THROW_ON_ERROR);

        if (!isset($releases[0])) {
            throw new \Exception('API error - no release found at GitHub repository ' . $this->gitHubRepository);
        }
        $parsed_releases = [];
        foreach ($releases as $release) {
            try {
                $normalized = $version_parser->normalize($release->tag_name);
            } catch (UnexpectedValueException) {
                // If this version does not look quite right, let's ignore it.
                continue;
            }

            $parsed_releases[$normalized] = [
                'tag_name' => $release->tag_name,
                'assets' => $release->assets,
                'prerelease' => $release->prerelease,
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
     * Get the latest release version and download URL according to given
     * constraints.
     *
     * @return string[]|null
     *    "version" and "download_url" elements if the latest release is
     *     available, otherwise - NULL.
     */
    public function getLatestReleaseFromGithub(array $options): ?array
    {
        $options = array_merge([
              'preview' => false,
              'compatible' => false,
              'version_constraint' => null,
            ], $options);

        foreach ($this->getReleasesFromGithub() as $releaseVersion => $release) {
            // We do not care about this release if it does not contain assets.
            if (!isset($release['assets'][0]) || !is_object($release['assets'][0])) {
                continue;
            }

            if ($options['compatible'] && !$this->satisfiesMajorVersionConstraint($releaseVersion)) {
                // If it does not satisfy, look for the next one.
                continue;
            }

            if (!$options['preview'] && ((VersionParser::parseStability($releaseVersion) !== 'stable') || $release['prerelease'])) {
                // If preview not requested and current version is not stable, look for the next one.
                continue;
            }

            if (null !== $options['version_constraint'] && !Semver::satisfies($releaseVersion, $options['version_constraint'])) {
                // Release version does not match version constraint option.
                continue;
            }

            return [
                'version' => $releaseVersion,
                'tag_name' => $release['tag_name'],
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->ignorePharRunningCheck && empty(\Phar::running())) {
            throw new \RuntimeException(self::SELF_UPDATE_COMMAND_NAME . ' only works when running the phar version of ' . $this->applicationName . '.');
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

        $latestRelease = $this->getLatestReleaseFromGithub([
            'preview' => $isPreviewOptionSet,
            'compatible' => $isCompatibleOptionSet,
            'version_constraint' => $versionConstraintArg,
        ]);
        if (null === $latestRelease || Comparator::greaterThanOrEqualTo($this->currentVersion, $latestRelease['version'])) {
            $output->writeln('No update available');
            return Command::SUCCESS;
        }

        $fs = new sfFilesystem();

        $output->writeln('Downloading ' . $this->applicationName . ' (' . $this->gitHubRepository . ') ' . $latestRelease['tag_name']);

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
        // This will never be reached, but it keeps static analysis tools happy :)
        return Command::SUCCESS;
    }

    /**
     * Returns TRUE if the release version satisfies current major version constraint.
     */
    protected function satisfiesMajorVersionConstraint(string $releaseVersion): bool
    {
        if (preg_match('/^v?(\d+)/', $this->currentVersion, $matches)) {
            return Semver::satisfies($releaseVersion , '^' . $matches[1]);
        }

        return false;
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
