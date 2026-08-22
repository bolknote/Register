<?php

declare(strict_types = 1);

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

/**
 * @copyright 2016-2020 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Test;

use Codeception\Test\Unit;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Finder;
use Register\Rose\Helper\ProfileHelper;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\File\SingleFileArrayStorage;

/**
 * @group profile
 */
final class ProfileTest extends Unit
{
    private const int TEST_FILE_NUM = 17;

    private function getTempFilename(): string
    {
        return __DIR__ . '/../../tmp/index.php';
    }

    #[\Override]
    protected function _before(): void
    {
        @unlink($this->getTempFilename());
    }

    /** @return list<string> */
    private function getCorpusFilenames(): array
    {
        $filenames = glob(__DIR__ . '/../../Resource/data/*.txt');
        if ($filenames === false) {
            throw new \RuntimeException('Cannot enumerate profiling corpus files.');
        }

        return array_slice($filenames, 0, self::TEST_FILE_NUM);
    }

    private function createIndexable(string $filename): Indexable
    {
        $content = file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Cannot read profiling corpus file "%s".', $filename));
        }

        $lineEnd = strpos($content, "\n");

        return new Indexable(
            basename($filename),
            $lineEnd === false ? $content : substr($content, 0, $lineEnd),
            $content
        );
    }

    /*
        public function testSnippet()
        {
            $start = microtime(true);

            $filenames = glob(__DIR__ . '/../../Resource/data/' . '*.txt');
            $filenames = array_slice($filenames, 0, self::TEST_FILE_NUM);

            $stemmer        = new PorterStemmerRussian();
            $snippetBuilder = new SnippetBuilder($stemmer);

            $indexProfilePoints[] = Helper::getProfilePoint('Preparing data', -$start + ($start = microtime(true)));

            $contentArray = [];
            foreach ($filenames as $filename) {
                $contentArray[] = file_get_contents($filename);
            }
            $indexProfilePoints[] = Helper::getProfilePoint('reading', -$start + ($start = microtime(true)));

            $contentArray = $snippetBuilder->cleanupContent($contentArray);
            $indexProfilePoints[] = Helper::getProfilePoint('cleanup', -$start + ($start = microtime(true)));

            $start2 = $start;

            foreach ($contentArray as $content) {
                $snippet = $snippetBuilder->buildSnippet(['test' => [83, 90], 'test2' => [49, 55, 142]], $content);

                $indexProfilePoints[] = Helper::getProfilePoint('pre-building', -$start + ($start = microtime(true)));

                $snippet = $snippet->toString();
    //			codecept_debug($snippet);

                $indexProfilePoints[] = Helper::getProfilePoint('post-building', -$start + ($start = microtime(true)));
            }

            $indexProfilePoints[] = Helper::getProfilePoint('building', -$start2 + (microtime(true)));

    //		codecept_debug($matches);

            foreach (array_merge($indexProfilePoints) as $point) {
                codecept_debug(Helper::formatProfilePoint($point));
            }
        }
    */
    public function testFileProfiling(): void
    {
        $start = microtime(true);

        $stemmer = new PorterStemmerRussian(new PorterStemmerEnglish());
        $storage = new SingleFileArrayStorage($this->getTempFilename());
        $indexer = new Indexer($storage, $stemmer);

        $indexProfilePoints   = [];
        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Indexer initialization', -$start + ($start = microtime(true)));

        $indexProfilePoints = array_merge(
            $indexProfilePoints,
            $storage->load(true)
        );

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Storage loading', -$start + ($start = microtime(true)));

        $filenames = $this->getCorpusFilenames();

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Preparing data', -$start + ($start = microtime(true)));

        foreach ($filenames as $filename) {
            $indexable = $this->createIndexable($filename);

//			$indexProfilePoints[] = Helper::getProfilePoint('Reading item', -$start + ($start = microtime(true)));

            $indexer->index($indexable);

//			$indexProfilePoints[] = Helper::getProfilePoint('Indexing item', -$start + ($start = microtime(true)));
        }

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Indexing', -$start + ($start = microtime(true)));

        $storage->cleanup();

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Storage cleanup', -$start + ($start = microtime(true)));

        $storage->save();

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Storage save', -$start + ($start = microtime(true)));

        $storage = new SingleFileArrayStorage($this->getTempFilename());
        $finder  = new Finder($storage, $stemmer);

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Finder initialization', -$start + ($start = microtime(true)));

        $loadingProfilePoints = $storage->load(true);

        $result = $finder->find(new Query('захотел разговаривать'), true);

        foreach (array_merge($indexProfilePoints, $loadingProfilePoints, $result->getProfilePoints()) as $point) {
            codecept_debug(ProfileHelper::formatProfilePoint($point));
        }
    }

    public function testDatabaseProfiling(): void
    {
        $start = microtime(true);

        global $register_rose_test_db;

        $pdo = new \PDO($register_rose_test_db['dsn'], $register_rose_test_db['username'], $register_rose_test_db['passwd']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $storage = new PdoStorage($pdo, 'profiling_');

        $indexProfilePoints   = [];
        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Db initialization', -$start + ($start = microtime(true)));

        $storage->erase();

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Db cleanup', -$start + ($start = microtime(true)));

        $stemmer = new PorterStemmerRussian(new PorterStemmerEnglish());
        $indexer = new Indexer($storage, $stemmer);

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Indexer initialization', -$start + ($start = microtime(true)));

        $filenames = $this->getCorpusFilenames();

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Preparing data', -$start + ($start = microtime(true)));

        foreach ($filenames as $filename) {
            $indexable = $this->createIndexable($filename);

//			$indexProfilePoints[] = Helper::getProfilePoint('Reading item', -$start + ($start = microtime(true)));

            $indexer->index($indexable);

//			$indexProfilePoints[] = Helper::getProfilePoint('Indexing item', -$start + ($start = microtime(true)));
        }

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Indexing', -$start + ($start = microtime(true)));

        $storage = new PdoStorage($pdo, 'profiling_');
        $finder  = new Finder($storage, $stemmer);

        $indexProfilePoints[] = ProfileHelper::getProfilePoint('Finder initialization', -$start + ($start = microtime(true)));

        $result = $finder->find(new Query('захотел разговаривать'), true);

        foreach (array_merge($indexProfilePoints, $result->getProfilePoints()) as $point) {
            codecept_debug(ProfileHelper::formatProfilePoint($point));
        }
    }
}
