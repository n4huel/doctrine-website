<?php

declare(strict_types=1);

namespace Doctrine\Website\Docs;

use AlgoliaSearch\Client;
use AlgoliaSearch\Index;
use Doctrine\RST\Nodes\DocumentNode;
use Doctrine\RST\Nodes\Node;
use Doctrine\RST\Nodes\ParagraphNode;
use Doctrine\RST\Nodes\TitleNode;
use Doctrine\Website\Model\Project;
use Doctrine\Website\Model\ProjectVersion;

use function get_class;
use function in_array;
use function is_string;
use function md5;
use function preg_match;
use function strip_tags;
use function strpos;

/**
 * Influenced by Laravel.com website code search indexes that also use Algolia.
 */
class SearchIndexer
{
    public const INDEX_NAME = 'pages';

    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function initSearchIndex(): void
    {
        $index = $this->getSearchIndex();

        $index->setSettings([
            'attributesToIndex' => ['projectName', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'content'],
            'customRanking' => ['asc(rank)'],
            'ranking' => ['words', 'typo', 'attribute', 'proximity', 'custom'],
            'minWordSizefor1Typo' => 3,
            'minWordSizefor2Typos' => 7,
            'allowTyposOnNumericTokens' => false,
            'minProximity' => 2,
            'ignorePlurals' => true,
            'advancedSyntax' => true,
            'removeWordsIfNoResults' => 'allOptional',
        ]);

        $index->clearIndex();
    }

    /** @param DocumentNode[] $documents */
    public function buildSearchIndexes(
        Project $project,
        ProjectVersion $version,
        array $documents
    ): void {
        $records = [];

        foreach ($documents as $document) {
            $this->buildDocumentSearchRecords($document, $records, $project, $version);
        }

        $this->getSearchIndex()->addObjects($records);
    }

    /** @param mixed[][] $records */
    private function buildDocumentSearchRecords(
        DocumentNode $document,
        array &$records,
        Project $project,
        ProjectVersion $version
    ): void {
        $environment = $document->getEnvironment();

        $slug        = $environment->getUrl();
        $currentLink = $slug;

        $current = [
            'h1' => null,
            'h2' => null,
            'h3' => null,
            'h4' => null,
            'h5' => null,
        ];

        $nodeTypes = [TitleNode::class, ParagraphNode::class];

        $nodes = $document->getNodes(static function (Node $node) use ($nodeTypes): bool {
            return in_array(get_class($node), $nodeTypes, true);
        });

        foreach ($nodes as $node) {
            $value = $this->renderNodeValue($node);

            if (strpos($value, '{{ DOCS_SOURCE_PATH') !== false) {
                continue;
            }

            $html = $node->render();

            if ($node instanceof TitleNode) {
                preg_match('/<a id=\"([^\"]*)\">.*<\/a>/iU', $html, $match);

                $currentLink = $slug . '.html' . (isset($match[1]) ? '#' . $match[1] : '');
            }

            $records[] = $this->getNodeSearchRecord(
                $node,
                $current,
                $currentLink,
                $project,
                $version,
            );
        }
    }

    /**
     * @param string[] $current
     *
     * @return mixed[]
     */
    private function getNodeSearchRecord(
        Node $node,
        array &$current,
        string &$currentLink,
        Project $project,
        ProjectVersion $version
    ): array {
        $level = $node instanceof TitleNode ? $node->getLevel() : false;

        if ($level !== false) {
            $current['h' . $level] = $this->renderNodeValue($node);

            for ($i = $level + 1; $i <= 5; $i++) {
                $current['h' . $i] = null;
            }

            $content = '';
        } else {
            $content = $this->renderNodeValue($node);
        }

        return [
            'objectID' => $version->getSlug() . '-' . $currentLink . '-' . md5($this->renderNodeValue($node)),
            'rank' => $this->getRank($node),
            'h1' => $current['h1'],
            'h2'  => $current['h2'],
            'h3'  => $current['h3'],
            'h4'  => $current['h4'],
            'h5'  => $current['h5'],
            'url' => '/projects/' . $project->getDocsSlug() . '/en/' . $version->getSlug() . '/' . $currentLink,
            'content' => $content !== '' ? strip_tags($content) : '',
            'projectName' => $project->getShortName(),
            '_tags' => [
                $version->getSlug(),
                $project->getSlug(),
            ],
        ];
    }

    private function getRank(Node $node): int
    {
        $ranks = [
            'h1' => 0,
            'h2' => 1,
            'h3' => 2,
            'h4' => 3,
            'h5' => 4,
            'h6' => 5,
            'p'  => 6,
        ];

        $elementName = 'p';

        if ($node instanceof TitleNode) {
            $elementName = 'h' . $node->getLevel();
        } elseif ($node instanceof ParagraphNode) {
            $elementName = 'p';
        }

        return $ranks[$elementName];
    }

    private function renderNodeValue(Node $node): string
    {
        $nodeValue = $node->getValue();

        if ($nodeValue instanceof Node) {
            return $nodeValue->render();
        }

        if (is_string($nodeValue)) {
            return $nodeValue;
        }

        return (string) $nodeValue;
    }

    private function getSearchIndex(): Index
    {
        return $this->client->initIndex(self::INDEX_NAME);
    }
}
