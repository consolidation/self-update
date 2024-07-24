<?php

namespace SelfUpdate;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Composer\Semver\Semver;
use Symfony\Component\HttpClient\HttpClient;
use UnexpectedValueException;

/**
 * Business logic for the self-update command.
 *
 * @author Alexander Menk <alex.menk@gmail.com>
 */
class SelfUpdateManager
{
    private ?array $latestRelease = null;

    public function __construct(protected string $gitHubRepository, protected string $currentVersion, protected string $applicationName, protected bool $isPreviewOptionSet, protected bool $isCompatibleOptionSet, protected string $versionConstraintArg){}

    public function isUpToDate(): bool {
        $latestRelease = $this->getLatestReleaseFromGithub();
        return NULL === $latestRelease || Comparator::greaterThanOrEqualTo($this->currentVersion, $latestRelease['version']);
    }

    /**
     * Get the latest release version and download URL according to given
     * constraints.
     *
     * @return string[]|null
     *    "version" and "download_url" elements if the latest release is
     *     available, otherwise - NULL.
     */
    public function getLatestReleaseFromGithub(): ?array
    {
        if (null !== $this->latestRelease) {
            return $this->latestRelease;
        }

        $options = [
            'preview' => $this->isPreviewOptionSet,
            'compatible' => $this->isCompatibleOptionSet,
            'version_constraint' => $this->versionConstraintArg,
        ];

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

            $this->latestRelease = [
                'version' => $releaseVersion,
                'tag_name' => $release['tag_name'],
                'download_url' => $release['assets'][0]->browser_download_url,
            ];
            return $this->latestRelease;
        }

        return null;
    }

    /**
     * Get all releases from GitHub.
     *
     * @return array
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *
     * @throws \Exception
     */
    private function getReleasesFromGithub(): array
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
     * Returns TRUE if the release version satisfies current major version constraint.
     */
    private function satisfiesMajorVersionConstraint(string $releaseVersion): bool
    {
        if (preg_match('/^v?(\d+)/', $this->currentVersion, $matches)) {
            return Semver::satisfies($releaseVersion , '^' . $matches[1]);
        }

        return false;
    }

}
