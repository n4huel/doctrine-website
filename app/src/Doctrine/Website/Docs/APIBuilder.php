<?php

declare(strict_types=1);

namespace Doctrine\Website\Docs;

use Doctrine\Website\ProcessFactory;
use Doctrine\Website\Projects\Project;
use Doctrine\Website\Projects\ProjectVersion;
use function file_put_contents;
use function sprintf;
use function unlink;

class APIBuilder
{
    /** @var ProcessFactory */
    private $processFactory;

    /** @var string */
    private $projectsPath;

    /** @var string */
    private $sculpinSourcePath;

    public function __construct(
        ProcessFactory $processFactory,
        string $projectsPath,
        string $sculpinSourcePath
    ) {
        $this->processFactory    = $processFactory;
        $this->projectsPath      = $projectsPath;
        $this->sculpinSourcePath = $sculpinSourcePath;
    }

    public function buildAPIDocs(
        Project $project,
        ProjectVersion $version
    ) : void {
        $configContent = <<<CONFIG
<?php

use Sami\RemoteRepository\GitHubRemoteRepository;

return new Sami\Sami('%s', [
    'build_dir' => '%s',
    'cache_dir' => '%s',
    'remote_repository' => new GitHubRemoteRepository('%s', '%s'),
    'versions' => '%s',
]);
CONFIG;

        $codeDir  = $this->projectsPath . '/' . $project->getRepositoryName() . $project->getCodePath();
        $buildDir = $this->sculpinSourcePath . '/api/' . $project->getSlug() . '/' . $version->getSlug();
        $cacheDir = $this->projectsPath . '/' . $project->getRepositoryName() . '/cache';

        $renderedConfigContent = sprintf(
            $configContent,
            $codeDir,
            $buildDir,
            $cacheDir,
            'doctrine/' . $project->getRepositoryName(),
            $this->projectsPath . '/' . $project->getRepositoryName(),
            $version->getBranchName()
        );

        $configPath   = $this->projectsPath . '/' . $project->getRepositoryName() . '/sami.php';
        $samiPharPath = $this->sculpinSourcePath . '/../sami.phar';

        $this->filePutContents($configPath, $renderedConfigContent);

        $command = 'php ' . $samiPharPath . ' update ' . $configPath . ' --verbose';

        $this->processFactory->run($command);

        $this->unlinkFile($configPath);
    }

    protected function filePutContents(string $path, string $contents) : void
    {
        file_put_contents($path, $contents);
    }

    protected function unlinkFile(string $path) : void
    {
        unlink($path);
    }
}
